<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogWriteRequests
{
    private const MAX_FIELDS = 50;

    private const MAX_LENGTH = 50;

    private const SENSITIVE_PARAMETER = '/token|hash|signature|key|secret/i';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('audit_user', $request->user());

        return $next($request);
    }

    /**
     * Record write requests after the response is sent. Only field names are
     * stored, never values. The causer falls back to the user seen before the
     * request ran, so logout and account deletion still identify who acted.
     * The route URI template is stored instead of the raw path, and parameters
     * and field names are masked or truncated to keep secrets and size in check.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethodSafe() || $response->getStatusCode() === 422) {
            return;
        }

        $status = $response->getStatusCode();
        $route = $request->route();
        $uri = $route?->uri() ?? 'unmatched';

        activity('http')
            ->causedBy($request->user() ?? $request->attributes->get('audit_user'))
            ->event($status >= 400 ? 'http.write_failed' : 'http.write')
            ->withProperties([
                'method' => $request->method(),
                'path' => $uri,
                'route' => $route?->getName(),
                'parameters' => $this->parameters($route?->originalParameters() ?? []),
                'fields' => array_map(
                    fn (int|string $field): string => Str::limit((string) $field, self::MAX_LENGTH),
                    array_slice(array_keys($request->all()), 0, self::MAX_FIELDS),
                ),
                'status' => $status,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 200),
            ])
            ->log($request->method().' '.$uri);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    private function parameters(array $parameters): array
    {
        $sanitized = [];

        foreach ($parameters as $name => $value) {
            $sanitized[$name] = preg_match(self::SENSITIVE_PARAMETER, $name)
                ? '[masked]'
                : Str::limit((string) (is_scalar($value) ? $value : get_debug_type($value)), self::MAX_LENGTH);
        }

        return $sanitized;
    }
}
