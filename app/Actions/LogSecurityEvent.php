<?php

namespace App\Actions;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Record a security-relevant action together with its outcome.
 *
 * Successful state changes and rejected attempts are written to the "security"
 * log, told apart by the "result" property. A failure to log is reported and swallowed so it
 * never breaks the operation being recorded.
 */
class LogSecurityEvent
{
    public const LOG_NAME = 'security';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    /**
     * @param  array<string, mixed>  $properties  Never pass secrets or raw user input.
     * @param  Model|null  $causer  Set when the acting user is no longer resolvable from the session.
     */
    public function __invoke(string $event, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        $this->record(self::RESULT_SUCCESS, $event, $subject, $properties, $causer);
    }

    /**
     * Record an attempt that was rejected, such as a wrong password or code.
     *
     * @param  array<string, mixed>  $properties  Never pass secrets or raw user input.
     */
    public function failure(string $event, ?Model $subject = null, array $properties = []): void
    {
        $this->record(self::RESULT_FAILURE, $event, $subject, $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(string $result, string $event, ?Model $subject, array $properties, ?Model $causer = null): void
    {
        try {
            $logger = activity(self::LOG_NAME)
                ->event($event)
                ->withProperties([...$properties, 'result' => $result]);

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
