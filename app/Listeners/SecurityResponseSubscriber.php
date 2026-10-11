<?php

namespace App\Listeners;

use App\Actions\Security\DetectSecurityThreats;
use App\Events\SecurityActivityObserved;
use App\Events\UserEmailChanged;
use App\Jobs\SendSecurityNotification;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Throwable;

class SecurityResponseSubscriber
{
    /** @var array<string, array{title: string, body: string, action: string}> */
    private const UserNotifications = [
        'authentication.two_factor_confirmed' => [
            'title' => 'Two-factor authentication enabled',
            'body' => 'Two-factor authentication was enabled for your account.',
            'action' => '/settings/security',
        ],
        'authentication.two_factor_disabled' => [
            'title' => 'Two-factor authentication disabled',
            'body' => 'Two-factor authentication was disabled for your account.',
            'action' => '/settings/security',
        ],
        'authentication.recovery_codes_regenerated' => [
            'title' => 'Recovery codes regenerated',
            'body' => 'New two-factor recovery codes were generated for your account.',
            'action' => '/settings/security',
        ],
        'authentication.passkey_registered' => [
            'title' => 'Passkey registered',
            'body' => 'A passkey was registered for your account.',
            'action' => '/settings/security',
        ],
        'authentication.passkey_deleted' => [
            'title' => 'Passkey deleted',
            'body' => 'A passkey was deleted from your account.',
            'action' => '/settings/security',
        ],
        'account.password_changed' => [
            'title' => 'Password changed',
            'body' => 'The password for your account was changed.',
            'action' => '/settings/security-activity',
        ],
        'session.revoked' => [
            'title' => 'Session access revoked',
            'body' => 'One or more authenticated sessions were revoked.',
            'action' => '/settings/sessions',
        ],
    ];

    public function __construct(private DetectSecurityThreats $detector) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(SecurityActivityObserved::class, $this->observed(...));
        $events->listen(UserEmailChanged::class, $this->emailChanged(...));
    }

    public function observed(SecurityActivityObserved $event): void
    {
        $this->detector->handle($event);

        if ($event->result !== 'success' || $event->targetUserId === null) {
            return;
        }

        if (($event->metadata['notify_owner'] ?? true) === false) {
            return;
        }

        $notification = self::UserNotifications[$event->event] ?? null;

        if ($notification === null) {
            return;
        }

        $this->dispatchNotification(
            $event->targetUserId,
            $event->event,
            __($notification['title']),
            __($notification['body']),
            $event->requestId,
            $notification['action'],
        );
    }

    public function emailChanged(UserEmailChanged $event): void
    {
        $requestId = request()->attributes->get('security_request_id');

        $this->dispatchNotification(
            (int) $event->user->getKey(),
            'account.email_changed',
            __('Email address changed'),
            __('The email address for your account was changed.'),
            is_string($requestId) ? $requestId : (string) Str::uuid(),
            '/settings/security-activity',
            $event->previousEmail,
        );
    }

    private function dispatchNotification(
        int $userId,
        string $event,
        string $title,
        string $body,
        string $requestId,
        string $actionUrl,
        ?string $additionalMailAddress = null,
    ): void {
        if (! config('security.notifications.enabled', true) || ! $this->hasEnabledChannel($event)) {
            return;
        }

        try {
            SendSecurityNotification::dispatch(
                userId: $userId,
                event: $event,
                title: $title,
                body: $body,
                requestId: $requestId,
                actionUrl: $actionUrl,
                additionalMailAddress: $additionalMailAddress,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function hasEnabledChannel(string $event): bool
    {
        $eventConfiguration = config('security.notifications.events', []);
        $channels = is_array($eventConfiguration) ? ($eventConfiguration[$event] ?? []) : [];

        if (! is_array($channels)) {
            return false;
        }

        return ($channels['database'] ?? false) === true
            || ((bool) config('security.notifications.mail', true) && ($channels['mail'] ?? false) === true);
    }
}
