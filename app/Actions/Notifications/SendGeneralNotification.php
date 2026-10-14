<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class SendGeneralNotification
{
    public function handle(
        User $user,
        string $title,
        string $body,
        ?string $actionUrl = null,
    ): void {
        if (! $this->isValidActionUrl($actionUrl)) {
            throw new InvalidArgumentException(
                'The URL must be an application-relative path or a valid HTTPS URL.',
            );
        }

        $user->notify(new GeneralNotification($title, $body, $actionUrl));
    }

    /**
     * Send a notification to the shared alert mailbox and Slack channel.
     *
     * @param  array<int, string>  $channels  Subset of `mail` and `slack`.
     * @return array<int, string> The channels the notification was actually sent to.
     */
    public function toAlertRecipients(
        string $title,
        string $body,
        ?string $actionUrl = null,
        array $channels = ['mail', 'slack'],
    ): array {
        if (! $this->isValidActionUrl($actionUrl)) {
            throw new InvalidArgumentException(
                'The URL must be an application-relative path or a valid HTTPS URL.',
            );
        }

        $routes = array_filter([
            'mail' => config('services.alerts.mail_to'),
            'slack' => config('services.alerts.slack_webhook_url'),
        ]);

        $deliverable = array_values(array_intersect($channels, array_keys($routes)));

        if ($deliverable === []) {
            return [];
        }

        $notifiable = Notification::routes(array_intersect_key($routes, array_flip($deliverable)));

        $notifiable->notify(new GeneralNotification($title, $body, $actionUrl, $deliverable));

        return $deliverable;
    }

    protected function isValidActionUrl(?string $actionUrl): bool
    {
        if ($actionUrl === null) {
            return true;
        }

        if (str_starts_with($actionUrl, '/') && ! str_starts_with($actionUrl, '//')) {
            return true;
        }

        return filter_var($actionUrl, FILTER_VALIDATE_URL) !== false
            && parse_url($actionUrl, PHP_URL_SCHEME) === 'https';
    }
}
