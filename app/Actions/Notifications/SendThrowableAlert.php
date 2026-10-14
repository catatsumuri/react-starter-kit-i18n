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
     *
     * @return array<int, string> The channels the alert was sent to; empty when throttled or not delivered.
     */
    public function handle(Throwable $throwable, bool $throttle = true): array
    {
        $fingerprint = sha1($throwable::class.$throwable->getFile().$throwable->getLine());

        try {
            if ($throttle && ! $this->claimFingerprint($fingerprint)) {
                return [];
            }

            return $this->sendGeneralNotification->toAlertRecipients(
                '['.config('app.name').'] '.class_basename($throwable),
                str($throwable->getMessage())->limit(500)->toString(),
            );
        } catch (Throwable $exception) {
            Log::warning('Failed to send alert notification.', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    /**
     * Claim the throttle slot, falling back to sending when the cache is unavailable.
     */
    protected function claimFingerprint(string $fingerprint): bool
    {
        try {
            return Cache::add("alerts:throwable:{$fingerprint}", true, now()->addMinutes(5));
        } catch (Throwable) {
            return true;
        }
    }
}
