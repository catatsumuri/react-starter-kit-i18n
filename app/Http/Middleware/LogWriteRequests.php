<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogWriteRequests
{
    private const MAX_FIELDS = 50;

    private const MAX_LENGTH = 50;

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('audit_user', $request->user());

        return $next($request);
    }

    /**
     * Record write requests after the response is sent. Only field names are
     * stored, never values. The causer falls back to the user seen before the
     * request ran, so logout and account deletion still identify who acted.
     * The route URI template is stored instead of the raw path and route
     * parameter values are never stored, so the template and field names
     * identify the operation without leaking values. A failure to log must
     * never affect the request, so it is reported and swallowed.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethodSafe()) {
            return;
        }

        $status = $response->getStatusCode();
        $route = $request->route();
        $uri = $route?->uri() ?? 'unmatched';

        try {
            activity('http')
                ->causedBy($request->user() ?? $request->attributes->get('audit_user'))
                ->event($status >= 400 ? 'http.write_failed' : 'http.write')
                ->withProperties([
                    'method' => $request->method(),
                    'path' => $uri,
                    'route' => $route?->getName(),
                    'fields' => array_map(
                        fn (int|string $field): string => Str::limit((string) $field, self::MAX_LENGTH),
                        array_slice(array_keys($request->all()), 0, self::MAX_FIELDS),
                    ),
                    'status' => $status,
                    'ip' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 200),
                ])
                ->log($request->method().' '.$uri);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
