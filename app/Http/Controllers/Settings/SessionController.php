<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Sessions\ManageDatabaseSessions;
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Session revoked.')]);

        return back();
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        abort_unless($this->sessions->isAvailable(), 404);

        try {
            $this->sessions->resetRememberToken($request->user());
            $revokedCount = $this->sessions->revokeOtherSessions(
                $request->user(),
                $request->session()->getId(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->storageFailure();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Other sessions revoked.')]);

        return back();
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        abort_unless($this->sessions->isAvailable(), 404);

        $user = $request->user();

        try {
            $this->sessions->resetRememberToken($user);
            $revokedCount = $this->sessions->revokeAllSessions($user);
        } catch (Throwable $exception) {
            report($exception);

            return $this->storageFailure();
        }

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
