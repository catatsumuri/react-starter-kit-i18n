<?php

namespace App\Http\Middleware;

use App\Actions\Security\RecordSecurityActivity;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class AuditAuthenticationFailures
{
    public function __construct(private RecordSecurityActivity $audit) {}

    /** @throws Throwable */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        try {
            return $next($request);
        } catch (ThrottleRequestsException $exception) {
            $this->audit->failure(
                'authentication.throttled',
                $this->audit->resolveTarget($request),
                $this->authenticationMethod($request),
                ['route' => (string) $request->route()?->getName()],
            );

            throw $exception;
        } catch (InvalidPasskeyException $exception) {
            $this->audit->failure(
                'authentication.passkey_failed',
                $this->audit->resolveTarget($request),
                'passkey',
            );

            throw $exception;
        }
    }

    private function authenticationMethod(Request $request): ?string
    {
        return match (true) {
            $request->routeIs('passkey.*') => 'passkey',
            $request->routeIs('two-factor.*') && $request->filled('recovery_code') => 'recovery_code',
            $request->routeIs('two-factor.*') => 'totp',
            $request->routeIs('login.store') => 'password',
            default => null,
        };
    }
}
