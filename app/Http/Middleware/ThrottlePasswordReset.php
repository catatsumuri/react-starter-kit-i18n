<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Limit rejected password resets before they reach the controller.
 *
 * Fortify gives POST /reset-password no throttle of its own, and every
 * rejected attempt now writes a security log row. The middleware is added to
 * Fortify's route group, so it only acts on the route that submits a reset.
 */
class ThrottlePasswordReset
{
    public const LIMITER = 'password-reset';

    public function __construct(private ThrottleRequests $throttle) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.update')) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, self::LIMITER);
    }
}
