<?php

namespace App\Providers;

use App\Actions\LogSecurityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSecurityEventLogging();
    }

    /**
     * Record successful account changes that are announced by Fortify events.
     */
    protected function configureSecurityEventLogging(): void
    {
        $record = function (object $event, string $name, array $properties = []): void {
            if ($event->user instanceof User) {
                app(LogSecurityEvent::class)($name, $event->user, $properties);
            }
        };

        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $record($event, 'password.reset'));

        Event::listen(TwoFactorAuthenticationEnabled::class, function (TwoFactorAuthenticationEnabled $event) use ($record): void {
            if (! Fortify::confirmsTwoFactorAuthentication()) {
                $record($event, 'mfa.enabled');
            }
        });

        Event::listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $event) => $record($event, 'mfa.enabled'));

        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event) use ($record): void {
            $wasActive = ! Fortify::confirmsTwoFactorAuthentication()
                || isset($event->user->getPrevious()['two_factor_confirmed_at']);

            $record($event, 'mfa.disabled', ['was_active' => $wasActive]);
        });

        Event::listen(RecoveryCodesGenerated::class, fn (RecoveryCodesGenerated $event) => $record($event, 'mfa.recovery_codes.regenerated'));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
