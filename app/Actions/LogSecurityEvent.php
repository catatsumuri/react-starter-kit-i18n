<?php

namespace App\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Record a security-relevant state change that has already succeeded.
 *
 * Write to the "security" log with the request ID of the current request so the
 * entry can be matched with its "http" log record. A failure to log is reported
 * and swallowed so it never breaks the operation being recorded.
 */
class LogSecurityEvent
{
    public const LOG_NAME = 'security';

    /**
     * @param  array<string, mixed>  $properties  Never pass secrets or raw user input.
     */
    public function __invoke(string $event, ?Model $subject = null, array $properties = []): void
    {
        try {
            $logger = activity(self::LOG_NAME)
                ->event($event)
                ->withProperties($properties + ['request_id' => Context::get('request_id')]);

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            $logger->log($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
