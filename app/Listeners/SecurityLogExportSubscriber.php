<?php

namespace App\Listeners;

use App\Events\SecurityActivityObserved;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecurityLogExportSubscriber
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(SecurityActivityObserved::class, $this->export(...));
    }

    public function export(SecurityActivityObserved $event): void
    {
        if (! config('security.export.enabled', true)) {
            return;
        }

        try {
            Log::channel(config('security.export.log_channel', 'security_export'))->info('security_activity', [
                'schema_version' => 1,
                'event_name' => $event->event,
                'result' => $event->result,
                'occurred_at' => now()->toISOString(),
                'actor_user_id' => $event->actorUserId,
                'target_user_id' => $event->targetUserId,
                'request_id' => $event->requestId,
                'session_reference' => $event->sessionFingerprint,
                'network_reference' => $event->networkKey,
                'ip_address' => $event->maskedIpAddress,
                'user_agent' => $event->userAgent,
                'authentication_method' => $event->authenticationMethod,
                'client_reference' => $event->clientKey,
                'client_description' => $event->clientDescription,
                'metadata' => $event->metadata,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
