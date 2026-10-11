<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

class SendGeneralNotification
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function handle(
        User $user,
        string $title,
        string $body,
        ?string $actionUrl = null,
        bool $sendMail = false,
        array $metadata = [],
        bool $storeInDatabase = true,
    ): void {
        if (! $this->isValidActionUrl($actionUrl)) {
            throw new InvalidArgumentException(
                'The URL must be an application-relative path or a valid HTTPS URL.',
            );
        }

        $user->notify(new GeneralNotification(
            $title,
            $body,
            $actionUrl,
            $sendMail,
            $metadata,
            $storeInDatabase,
        ));
    }

    public function routeMail(
        string $email,
        string $title,
        string $body,
        ?string $actionUrl = null,
    ): void {
        if (! $this->isValidActionUrl($actionUrl)) {
            throw new InvalidArgumentException(
                'The URL must be an application-relative path or a valid HTTPS URL.',
            );
        }

        Notification::route('mail', $email)
            ->notify(new GeneralNotification(
                $title,
                $body,
                $actionUrl,
                sendMail: true,
                storeInDatabase: false,
            ));
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
