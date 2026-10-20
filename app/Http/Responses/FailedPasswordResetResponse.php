<?php

namespace App\Http\Responses;

use App\Actions\LogSecurityEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse as FortifyFailedPasswordResetResponse;
use Throwable;

/**
 * Fortify dispatches no event when a password reset is rejected, so the
 * failure is recorded when its response is built. Only the broker status key
 * is stored, never the submitted token, password or email address.
 *
 * Recording is best effort: a failure to look up the account or to write the
 * log entry is reported and never changes the response.
 */
class FailedPasswordResetResponse extends FortifyFailedPasswordResetResponse
{
    public function toResponse($request)
    {
        app(LogSecurityEvent::class)->failure(
            'password.reset_failed',
            $this->resolveSubject((string) $request->input(Fortify::email())),
            ['reason' => $this->status],
        );

        return parent::toResponse($request);
    }

    /**
     * Resolve the account through the configured password broker, so a
     * changed auth model is respected.
     */
    private function resolveSubject(string $email): ?Model
    {
        try {
            $user = Password::broker(config('fortify.passwords'))->getUser([Fortify::email() => $email]);

            return $user instanceof Model ? $user : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
