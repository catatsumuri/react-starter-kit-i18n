<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AuthenticatedSessionContext;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceAuthenticatedSessionPolicy
{
    public function __construct(private AuthenticatedSessionContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $context = $this->context->resolve($request, $user, $guard->viaRemember());
        $lifetime = $this->context->lifetimeSeconds();

        if ($context === null
            || $context['version'] !== $user->auth_session_version
            || ($lifetime > 0 && now()->getTimestamp() - $context['started_at'] > $lifetime)) {
            return $this->expire($request, $guard);
        }

        return $next($request);
    }

    private function expire(Request $request, SessionGuard $guard): JsonResponse|RedirectResponse
    {
        $guard->logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $this->context->forget();

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Your session has expired. Please sign in again.')], 401);
        }

        $request->session()->flash('status', __('Your session has expired. Please sign in again.'));

        return redirect()->guest(route('login'));
    }
}
