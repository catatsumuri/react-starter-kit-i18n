<?php

namespace App\Http\Responses;

use App\Actions\LogSecurityEvent;
use App\Models\User;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\FailedPasswordResetResponse as FortifyFailedPasswordResetResponse;

/**
 * Fortify dispatches no event when a password reset is rejected, so the
 * failure is recorded when its response is built. Only the broker status key
 * is stored, never the submitted token, password or email address.
 */
class FailedPasswordResetResponse extends FortifyFailedPasswordResetResponse
{
    public function toResponse($request)
    {
        $user = User::query()->where(Fortify::email(), $request->input(Fortify::email()))->first();

        app(LogSecurityEvent::class)->failure('password.reset_failed', $user, ['reason' => $this->status]);

        return parent::toResponse($request);
    }
}
