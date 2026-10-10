<?php

namespace App\Listeners;

use App\Actions\Security\RecordSecurityActivity;
use App\Events\AuthenticatedSessionRevoked;
use App\Events\UserAccountDeleted;
use App\Events\UserEmailChanged;
use App\Events\UserPasswordChanged;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

class SecurityAuditSubscriber
{
    public function __construct(private RecordSecurityActivity $audit) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->login(...));
        $events->listen(Failed::class, $this->loginFailed(...));
        $events->listen(TwoFactorAuthenticationFailed::class, $this->twoFactorFailed(...));
        $events->listen(TwoFactorAuthenticationEnabled::class, fn ($event) => $this->userEvent('authentication.two_factor_enabled', $event->user));
        $events->listen(TwoFactorAuthenticationConfirmed::class, fn ($event) => $this->userEvent('authentication.two_factor_confirmed', $event->user));
        $events->listen(TwoFactorAuthenticationDisabled::class, fn ($event) => $this->userEvent('authentication.two_factor_disabled', $event->user));
        $events->listen(RecoveryCodesGenerated::class, fn ($event) => $this->userEvent('authentication.recovery_codes_regenerated', $event->user));
        $events->listen(PasskeyRegistered::class, fn ($event) => $this->userEvent('authentication.passkey_registered', $event->user));
        $events->listen(PasskeyDeleted::class, fn ($event) => $this->userEvent('authentication.passkey_deleted', $event->user));
        $events->listen(PasswordReset::class, fn ($event) => $this->userEvent('account.password_changed', $event->user, 'reset_token'));
        $events->listen(Verified::class, fn ($event) => $this->userEvent('account.email_verified', $event->user));
        $events->listen(UserPasswordChanged::class, fn ($event) => $this->userEvent('account.password_changed', $event->user, 'password'));
        $events->listen(UserEmailChanged::class, fn ($event) => $this->userEvent('account.email_changed', $event->user));
        $events->listen(UserAccountDeleted::class, fn ($event) => $this->userEvent('account.deleted', $event->user));
        $events->listen(AuthenticatedSessionRevoked::class, $this->sessionRevoked(...));
    }

    public function login(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $method = match (true) {
            request()->routeIs('passkey.login') => 'passkey',
            request()->routeIs('two-factor.login.store') && request()->filled('recovery_code') => 'recovery_code',
            request()->routeIs('two-factor.login.store') => 'totp',
            default => 'password',
        };

        $this->audit->success('authentication.login_succeeded', $event->user, $event->user, $method);
    }

    public function loginFailed(Failed $event): void
    {
        $target = $event->user instanceof User ? $event->user : $this->audit->resolveTarget(request());
        $this->audit->failure('authentication.login_failed', $target, 'password');
    }

    public function twoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $method = request()->filled('recovery_code') ? 'recovery_code' : 'totp';
        $this->audit->failure('authentication.two_factor_failed', $event->user, $method);
    }

    public function sessionRevoked(AuthenticatedSessionRevoked $event): void
    {
        $this->audit->success(
            'session.revoked',
            $event->user,
            $event->user,
            metadata: ['scope' => $event->scope, 'revoked_count' => $event->revokedCount],
            sessionFingerprint: $event->sessionFingerprint,
        );
    }

    private function userEvent(string $name, mixed $user, ?string $method = null): void
    {
        if ($user instanceof User) {
            $this->audit->success($name, $user, $user, $method);
        }
    }
}
