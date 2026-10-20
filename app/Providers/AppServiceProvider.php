<?php

namespace App\Providers;

use App\Actions\LogSecurityEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

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
        $this->configureSecurityEventAccess();
    }

    /**
     * Only configured viewers with a verified email may read the security log.
     *
     * An email address only identifies a person once its owner has been
     * confirmed. A user model that cannot verify emails (Chisel removes
     * MustVerifyEmail when email verification is disabled) is therefore denied,
     * because the verified middleware lets such users through unchecked.
     */
    protected function configureSecurityEventAccess(): void
    {
        Gate::define('view-security-events', function (Authenticatable $user): bool {
            if (! $user instanceof MustVerifyEmail || ! $user->hasVerifiedEmail()) {
                return false;
            }

            $viewers = array_map(Str::lower(...), config('audit.security_viewers'));

            return in_array(Str::lower($user->getEmailForVerification()), $viewers, true);
        });
    }

    /**
     * Record successful account changes that are announced by Fortify events.
     */
    protected function configureSecurityEventLogging(): void
    {
        $saved = fn (User $user, string $key): bool => array_key_exists($key, $user->getPrevious()) && ! $user->isDirty($key);

        $record = function (object $event, string $name, ?Closure $stored = null, array $properties = []): void {
            $user = $event->user;

            if (! $user instanceof User || ($stored !== null && ! $stored($event))) {
                return;
            }

            app(LogSecurityEvent::class)($name, $user, $properties);
        };

        Event::listen(PasswordReset::class, fn (PasswordReset $event) => $record($event, 'password.reset'));

        /*
         * Fortify dispatches the two-factor events even when the underlying save
         * was cancelled or did not change anything. Each listener checks that the
         * attribute is among the last save's changes (previous values) and has no
         * unsaved change left, so a cancelled save cannot reuse an earlier save's
         * history and no extra query can fail.
         */
        Event::listen(TwoFactorAuthenticationEnabled::class, function (TwoFactorAuthenticationEnabled $event) use ($record, $saved): void {
            if (! Fortify::confirmsTwoFactorAuthentication()) {
                $record($event, 'mfa.enabled', fn ($event) => $saved($event->user, 'two_factor_secret'));
            }
        });

        Event::listen(TwoFactorAuthenticationConfirmed::class, fn (TwoFactorAuthenticationConfirmed $event) => $record(
            $event, 'mfa.enabled',
            fn ($event) => $saved($event->user, 'two_factor_confirmed_at') && $event->user->getPrevious()['two_factor_confirmed_at'] === null,
        ));

        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event) use ($record, $saved): void {
            $wasActive = ! Fortify::confirmsTwoFactorAuthentication()
                || isset($event->user->getPrevious()['two_factor_confirmed_at']);

            $record(
                $event, 'mfa.disabled',
                fn ($event) => $saved($event->user, 'two_factor_secret'),
                ['was_active' => $wasActive],
            );
        });

        Event::listen(RecoveryCodesGenerated::class, fn (RecoveryCodesGenerated $event) => $record(
            $event, 'mfa.recovery_codes.regenerated',
            fn ($event) => $saved($event->user, 'two_factor_recovery_codes'),
        ));

        /*
         * Rejected sign-in attempts. The submitted credentials are never stored;
         * the subject is only set when the attempt matched an existing account.
         */
        Event::listen(Failed::class, fn (Failed $event) => app(LogSecurityEvent::class)->failure(
            'login.failed',
            $event->user instanceof User ? $event->user : null,
        ));

        Event::listen(TwoFactorAuthenticationFailed::class, fn (TwoFactorAuthenticationFailed $event) => app(LogSecurityEvent::class)->failure(
            'mfa.failed',
            $event->user instanceof User ? $event->user : null,
            ['method' => request()->filled('recovery_code') ? 'recovery_code' : 'totp'],
        ));

        /*
         * laravel/passkeys also dispatches after a cancelled create or delete, so
         * check that the passkey row was really created or removed. Only the
         * passkey ID is stored; the user-supplied name is not.
         */
        Event::listen(PasskeyRegistered::class, fn (PasskeyRegistered $event) => $record(
            $event, 'passkey.created',
            fn (PasskeyRegistered $event) => $event->passkey->wasRecentlyCreated,
            ['passkey_id' => $event->passkey->getKey()],
        ));

        Event::listen(PasskeyDeleted::class, fn (PasskeyDeleted $event) => $record(
            $event, 'passkey.deleted',
            fn (PasskeyDeleted $event) => ! $event->passkey->exists,
            ['passkey_id' => $event->passkey->getKey()],
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
