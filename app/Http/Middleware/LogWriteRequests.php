<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogWriteRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('audit_user', $request->user());

        return $next($request);
    }

    /**
     * Record write requests after the response is sent. Only field names are
     * stored, never values. The causer falls back to the user seen before the
     * request ran, so logout and account deletion still identify who acted.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethodSafe() || $response->getStatusCode() === 422) {
            return;
        }

        $status = $response->getStatusCode();

        activity('http')
            ->causedBy($request->user() ?? $request->attributes->get('audit_user'))
            ->event($status >= 400 ? 'http.write_failed' : 'http.write')
            ->withProperties([
                'method' => $request->method(),
                'path' => $request->path(),
                'route' => $request->route()?->getName(),
                'parameters' => $request->route()?->originalParameters(),
                'fields' => array_keys($request->all()),
                'status' => $status,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ])
            ->log($request->method().' '.$request->path());
    }
}
