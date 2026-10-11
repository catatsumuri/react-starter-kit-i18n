<?php

namespace App\Http\Middleware;

use App\Support\PurposeBoundAuthentication;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePurposeBoundAuthentication
{
    public function __construct(
        private RequirePassword $password,
        private PurposeBoundAuthentication $purposes,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        ?string $redirectToRoute = null,
        int|string|null $passwordTimeoutSeconds = null,
    ): Response {
        return $this->handleForPurpose(
            $request,
            $next,
            $this->purposes->purposeFor($request),
            $redirectToRoute,
            $passwordTimeoutSeconds,
        );
    }

    public function handleForPurpose(
        Request $request,
        Closure $next,
        string $purpose,
        ?string $redirectToRoute = null,
        int|string|null $passwordTimeoutSeconds = null,
    ): Response {
        if ($this->purposes->consumeFollowUp($request)) {
            return $next($request);
        }

        $timeout = is_numeric($passwordTimeoutSeconds)
            ? (int) $passwordTimeoutSeconds
            : (int) config('auth.password_timeout', 900);

        if ($this->purposes->isConfirmed($request, $purpose, $timeout)) {
            return $next($request);
        }

        $this->purposes->prepareConfirmation($request, $purpose);

        return $this->password->handle(
            $request,
            $next,
            $redirectToRoute,
            $passwordTimeoutSeconds,
        );
    }
}
