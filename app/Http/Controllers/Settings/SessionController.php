<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Sessions\ManageDatabaseSessions;
use App\Events\AuthenticatedSessionRevoked;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SessionController extends Controller
{
    public function __construct(private ManageDatabaseSessions $sessions) {}

    public function index(Request $request): Response
    {
        if (! $this->sessions->isAvailable()) {
            return Inertia::render('settings/sessions', [
                'available' => false,
                'currentSession' => null,
                'otherSessions' => null,
            ]);
        }

        try {
            $sessions = $this->sessions->sessions($request->user(), $request->session()->getId());
        } catch (Throwable $exception) {
            report($exception);

            return Inertia::render('settings/sessions', [
                'available' => false,
                'currentSession' => null,
                'otherSessions' => null,
            ]);
        }

        return Inertia::render('settings/sessions', [
            'available' => true,
            ...$sessions,
        ]);
    }

    public function destroy(Request $request, string $fingerprint): RedirectResponse
    {
        abort_unless($this->sessions->isAvailable(), 404);

        try {
            $revoked = $this->sessions->revoke(
                $request->user(),
                $request->session()->getId(),
                $fingerprint,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->storageFailure();
        }

        abort_unless($revoked, 404);

        AuthenticatedSessionRevoked::dispatch(
            $request->user(),
            AuthenticatedSessionRevoked::Individual,
            1,
            $fingerprint,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);

        return back();
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        abort_unless($this->sessions->isAvailable(), 404);

        try {
            $revokedCount = $this->sessions->revokeOtherSessions(
                $request->user(),
                $request->session()->getId(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->storageFailure();
        }

        AuthenticatedSessionRevoked::dispatch(
            $request->user(),
            AuthenticatedSessionRevoked::OtherSessions,
            $revokedCount,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Other sessions revoked.')]);

        return back();
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        abort_unless($this->sessions->isAvailable(), 404);

        $user = $request->user();

        try {
            $revokedCount = $this->sessions->revokeAllSessions($user);
        } catch (Throwable $exception) {
            report($exception);

            return $this->storageFailure();
        }

        AuthenticatedSessionRevoked::dispatch(
            $user,
            AuthenticatedSessionRevoked::AllSessions,
            $revokedCount,
        );

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function storageFailure(): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('Sessions are temporarily unavailable. Please try again.'),
        ]);

        return back();
    }
}
