<?php

namespace App\Jobs;

use App\Actions\Notifications\SendGeneralNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;

class SendSecurityNotification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $userId,
        public string $event,
        public string $title,
        public string $body,
        public string $requestId,
        public ?string $actionUrl = '/settings/security-activity',
        public ?string $additionalMailAddress = null,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(SendGeneralNotification $notifications): void
    {
        if (! config('security.notifications.enabled', true)) {
            return;
        }

        $eventConfiguration = config('security.notifications.events', []);
        $channels = is_array($eventConfiguration) ? ($eventConfiguration[$this->event] ?? []) : [];
        $storeInDatabase = is_array($channels) && ($channels['database'] ?? false) === true;
        $eventMailEnabled = is_array($channels) && ($channels['mail'] ?? false) === true;

        if (! $storeInDatabase && ! $eventMailEnabled) {
            return;
        }

        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $recent = $user->notifications()
            ->where('created_at', '>=', now()->subHour())
            ->latest()
            ->limit(50)
            ->get();

        if ($recent->contains(fn (DatabaseNotification $notification): bool => ($notification->data['security_request_id'] ?? null) === $this->requestId)) {
            return;
        }

        $mailEnabled = (bool) config('security.notifications.mail', true) && $eventMailEnabled;
        $mailLimit = (int) config('security.notifications.mail_hourly_limit', 5);
        $cooldown = (int) config('security.notifications.same_event_cooldown_minutes', 15);
        $securityMailCount = $recent->filter(fn (DatabaseNotification $notification): bool => ($notification->data['security_mail_sent'] ?? false) === true)->count();
        $sameEventRecentlyMailed = $recent->contains(function (DatabaseNotification $notification) use ($cooldown): bool {
            return ($notification->data['security_event'] ?? null) === $this->event
                && ($notification->data['security_mail_sent'] ?? false) === true
                && $notification->created_at?->isAfter(now()->subMinutes($cooldown));
        });
        $sendMail = $mailEnabled && $securityMailCount < $mailLimit && ! $sameEventRecentlyMailed;
        $metadata = [
            'category' => 'security',
            'security_event' => $this->event,
            'security_request_id' => $this->requestId,
            'security_mail_sent' => $sendMail,
        ];

        $notifications->handle(
            $user,
            $this->title,
            $this->body,
            $this->actionUrl,
            $sendMail,
            $metadata,
            $storeInDatabase,
        );

        if ($sendMail && $this->additionalMailAddress !== null && $this->additionalMailAddress !== $user->email) {
            $notifications->routeMail(
                $this->additionalMailAddress,
                $this->title,
                $this->body,
                $this->actionUrl,
            );
        }
    }
}
