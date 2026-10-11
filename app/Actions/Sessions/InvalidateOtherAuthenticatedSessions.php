<?php

namespace App\Actions\Sessions;

use App\Events\AuthenticatedSessionRevoked;
use App\Models\User;
use App\Support\AuthenticatedSessionContext;
use Throwable;

class InvalidateOtherAuthenticatedSessions
{
    public function __construct(
        private ManageDatabaseSessions $sessions,
        private AuthenticatedSessionContext $context,
    ) {}

    public function handle(User $user, string $reason): void
    {
        $request = request();
        $currentUser = $request->user();
        $preserveCurrent = $currentUser instanceof User && $currentUser->is($user) && $request->hasSession();
        $currentSessionId = $preserveCurrent ? $request->session()->getId() : null;

        $user->increment('auth_session_version');
        $user->refresh();

        if ($preserveCurrent) {
            $this->context->updateVersion($request, $user);
        }

        $revokedCount = null;

        if ($this->sessions->isAvailable()) {
            try {
                $revokedCount = $currentSessionId === null
                    ? $this->sessions->revokeAllSessions($user)
                    : $this->sessions->revokeOtherSessions($user, $currentSessionId);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        AuthenticatedSessionRevoked::dispatch(
            user: $user,
            scope: $preserveCurrent ? AuthenticatedSessionRevoked::OtherSessions : AuthenticatedSessionRevoked::AllSessions,
            revokedCount: $revokedCount,
            actor: $preserveCurrent ? $user : null,
            origin: AuthenticatedSessionRevoked::SecurityChange,
            reason: $reason,
            notifyOwner: false,
        );
    }
}
