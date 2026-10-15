<?php

namespace App\Providers;

use App\Actions\LogSecurityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
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
        $record = function (object $event, string $name, ?Closure $stored = null, array $properties = []): void {
            $user = $event->user;

            if (! $user instanceof User || ($stored !== null && ! $stored($user->getPrevious()))) {
                return;
            }

            app(LogSecurityEvent::class)($name, $user, $properties);
        };

        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $record($event, 'password.reset'));

        /*
         * Fortify dispatches the two-factor events even when the underlying save
         * was cancelled or did not change anything. Each listener inspects the
         * attributes the save actually changed (previous values), so only a real
         * transition is recorded and no extra query can fail.
         */
        Event::listen(TwoFactorAuthenticationEnabled::class, function (TwoFactorAuthenticationEnabled $event) use ($record): void {
            if (! Fortify::confirmsTwoFactorAuthentication()) {
                $record($event, 'mfa.enabled', fn (array $previous) => array_key_exists('two_factor_secret', $previous));
            }
        });

        Event::listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $event) => $record(
            $event, 'mfa.enabled',
            fn (array $previous) => array_key_exists('two_factor_confirmed_at', $previous) && $previous['two_factor_confirmed_at'] === null,
        ));

        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event) use ($record): void {
            $wasActive = ! Fortify::confirmsTwoFactorAuthentication()
                || isset($event->user->getPrevious()['two_factor_confirmed_at']);

            $record(
                $event, 'mfa.disabled',
                fn (array $previous) => array_key_exists('two_factor_secret', $previous),
                ['was_active' => $wasActive],
            );
        });

        Event::listen(RecoveryCodesGenerated::class, fn (RecoveryCodesGenerated $event) => $record(
            $event, 'mfa.recovery_codes.regenerated',
            fn (array $previous) => array_key_exists('two_factor_recovery_codes', $previous),
        ));
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
