<?php

namespace App\Actions\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendThrowableAlert
{
    public function __construct(protected SendGeneralNotification $sendGeneralNotification) {}

    /**
     * Notify the shared alert recipients about a throwable, at most once per fingerprint every five minutes.
     */
    public function handle(Throwable $throwable): void
    {
        $fingerprint = sha1($throwable::class.$throwable->getFile().$throwable->getLine());

        if (! Cache::add("alerts:throwable:{$fingerprint}", true, now()->addMinutes(5))) {
            return;
        }

        try {
            $this->sendGeneralNotification->toAlertRecipients(
                '['.config('app.name').'] '.class_basename($throwable),
                str($throwable->getMessage())->limit(500)->toString(),
            );
        } catch (Throwable $exception) {
            Log::warning('Failed to send alert notification.', ['error' => $exception->getMessage()]);
        }
    }
}
