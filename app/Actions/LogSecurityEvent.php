<?php

namespace App\Actions;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Record a security-relevant state change that has already succeeded.
 *
 * Write to the "security" log. A failure to log is reported and swallowed so it
 * never breaks the operation being recorded.
 */
class LogSecurityEvent
{
    public const LOG_NAME = 'security';

    /**
     * @param  array<string, mixed>  $properties  Never pass secrets or raw user input.
     * @param  Model|null  $causer  Set when the acting user is no longer resolvable from the session.
     */
    public function __invoke(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        try {
            $logger = activity(self::LOG_NAME)
                ->event($event)
                ->withProperties($properties);

            if ($subject !== null) {
                $logger->performedOn($subject);
            }

            if ($causer !== null) {
                $logger->causedBy($causer);
            }

            $logger->log($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
