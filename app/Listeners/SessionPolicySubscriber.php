<?php

namespace App\Listeners;

use App\Actions\Sessions\InvalidateOtherAuthenticatedSessions;
use App\Events\UserEmailChanged;
use App\Events\UserPasswordChanged;
use App\Models\User;
use App\Support\AuthenticatedSessionContext;
use App\Support\PurposeBoundAuthentication;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\SessionGuard;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

class SessionPolicySubscriber
{
    public function __construct(
        private AuthenticatedSessionContext $context,
        private InvalidateOtherAuthenticatedSessions $invalidate,
        private PurposeBoundAuthentication $purposes,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->login(...));
        $events->listen(Logout::class, fn () => $this->context->forget());
        $events->listen(PasswordReset::class, fn ($event) => $this->invalidateUser($event->user, 'password_reset'));
        $events->listen(UserPasswordChanged::class, fn ($event) => $this->invalidate->handle($event->user, 'password_changed'));
        $events->listen(UserEmailChanged::class, $this->emailChanged(...));
        $events->listen(TwoFactorAuthenticationConfirmed::class, fn () => $this->consumeSecurityPurpose());
        $events->listen(TwoFactorAuthenticationDisabled::class, $this->twoFactorDisabled(...));
        $events->listen(RecoveryCodesGenerated::class, fn () => $this->consumeSecurityPurpose());
        $events->listen(PasskeyRegistered::class, fn () => $this->consumeSecurityPurpose());
        $events->listen(PasskeyDeleted::class, $this->passkeyDeleted(...));
    }

    public function login(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        if ($event->user instanceof User && (! $guard instanceof SessionGuard || ! $guard->viaRemember())) {
            $this->context->reset(request(), $event->user);
        }
    }

    private function invalidateUser(mixed $user, string $reason): void
    {
        if ($user instanceof User) {
            $this->invalidate->handle($user, $reason);
        }
    }

    private function emailChanged(UserEmailChanged $event): void
    {
        $this->invalidate->handle($event->user, 'email_changed');
        $this->purposes->consume(request());
    }

    private function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->invalidateUser($event->user, 'two_factor_disabled');
        $this->consumeSecurityPurpose();
    }

    private function passkeyDeleted(PasskeyDeleted $event): void
    {
        $this->invalidateUser($event->user, 'passkey_deleted');
        $this->consumeSecurityPurpose();
    }

    private function consumeSecurityPurpose(): void
    {
        $this->purposes->consume(request(), [
            'security.edit',
            'two-factor.recovery-codes',
        ]);
    }
}
