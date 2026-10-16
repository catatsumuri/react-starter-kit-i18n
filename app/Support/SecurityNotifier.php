<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\SecurityNotification;
use Illuminate\Support\Facades\Notification;
use Throwable;

use function Illuminate\Support\defer;

class SecurityNotifier
{
    /**
     * Notify a user after the response is sent, without ever failing the caller.
     */
    public static function notify(User $user, SecurityEvent $event): void
    {
        self::deliver(fn () => $user->notify(new SecurityNotification($event)));
    }

    /**
     * Notify a bare email address, for users that no longer exist.
     */
    public static function notifyAddress(string $email, SecurityEvent $event): void
    {
        self::deliver(fn () => Notification::route('mail', $email)->notify(new SecurityNotification($event)));
    }

    /**
     * Run the delivery after the response, reporting instead of throwing on failure.
     */
    private static function deliver(callable $send): void
    {
        defer(function () use ($send): void {
            try {
                $send();
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
