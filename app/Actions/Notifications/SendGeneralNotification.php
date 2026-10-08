<?php

namespace App\Actions\Notifications;

use App\Models\User;
use App\Notifications\GeneralNotification;
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
