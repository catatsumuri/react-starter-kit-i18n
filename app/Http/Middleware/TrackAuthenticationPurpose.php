<?php

namespace App\Http\Middleware;

use App\Support\PurposeBoundAuthentication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

class TrackAuthenticationPurpose
{
    public function __construct(private PurposeBoundAuthentication $purposes) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(['password.confirm.store', 'passkey.confirm'])) {
            return $next($request);
        }

        $confirmationStartedAt = Date::now()->unix();
        $response = $next($request);
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (is_int($confirmedAt) && $confirmedAt >= $confirmationStartedAt) {
            $this->purposes->confirmPending($request);
        }

        return $response;
    }
}
