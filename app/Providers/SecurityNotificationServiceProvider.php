<?php

namespace App\Providers;

use App\Models\User;
use App\Support\SecurityEvent;
use App\Support\SecurityNotifier;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Notifies users by email when sensitive security events succeed.
 *
 * Notifications are sent from the code path of the action itself, never derived
 * from an audit log, and a failed notification never fails the action.
 */
class SecurityNotificationServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->listenFor(PasswordReset::class, SecurityEvent::PasswordReset);
        $this->listenFor(RecoveryCodesGenerated::class, SecurityEvent::RecoveryCodesRegenerated);

        // Fortify and the passkeys package fire these events even when the
        // underlying save was cancelled, so confirm the persisted state first.
        $this->listenFor(
            TwoFactorAuthenticationConfirmed::class,
            SecurityEvent::TwoFactorEnabled,
            fn (object $event): bool => $this->freshUser($event)?->two_factor_confirmed_at !== null,
        );
        $this->listenFor(
            TwoFactorAuthenticationDisabled::class,
            SecurityEvent::TwoFactorDisabled,
            fn (object $event): bool => $this->freshUser($event)?->two_factor_secret === null,
        );
        $this->listenFor(
            PasskeyRegistered::class,
            SecurityEvent::PasskeyAdded,
            fn (object $event): bool => $event->passkey->exists,
        );
        $this->listenFor(
            PasskeyDeleted::class,
            SecurityEvent::PasskeyRemoved,
            fn (object $event): bool => ! $event->passkey->newQuery()->whereKey($event->passkey->getKey())->exists(),
        );

        // With confirmation required, "enabled" only creates the secret; the
        // change takes effect on "confirmed".
        $this->listenFor(
            TwoFactorAuthenticationEnabled::class,
            SecurityEvent::TwoFactorEnabled,
            fn (object $event): bool => ! Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm')
                && $this->freshUser($event)?->two_factor_secret !== null,
        );
    }

    /**
     * Send the given security notification when the event fires for a user.
     *
     * @param  class-string  $eventClass
     * @param  (callable(object): bool)|null  $when
     */
    private function listenFor(string $eventClass, SecurityEvent $securityEvent, ?callable $when = null): void
    {
        Event::listen($eventClass, function (object $event) use ($securityEvent, $when): void {
            if ($event->user instanceof User && ($when === null || $when($event))) {
                SecurityNotifier::notify($event->user, $securityEvent);
            }
        });
    }

    /**
     * Get the event's user as currently persisted.
     */
    private function freshUser(object $event): ?User
    {
        return $event->user->fresh();
    }
}
