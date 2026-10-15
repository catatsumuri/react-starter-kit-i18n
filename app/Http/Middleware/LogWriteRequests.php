<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogWriteRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Record write requests after the response is sent. Only field names are
     * stored, never values.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethodSafe() || $response->getStatusCode() === 422) {
            return;
        }

        $status = $response->getStatusCode();

        activity('http')
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
