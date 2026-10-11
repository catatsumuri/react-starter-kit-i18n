<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\PurposeBoundAuthentication;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentAuthenticationForEmailChange
{
    public function __construct(private RequirePurposeBoundAuthentication $authentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->changesEmail($request, $user)) {
            return $next($request);
        }

        return $this->authentication->handleForPurpose(
            $request,
            $next,
            PurposeBoundAuthentication::EmailChange,
        );
    }

    private function changesEmail(Request $request, User $user): bool
    {
        $submittedEmail = Str::lower(trim($request->string('email')->toString()));

        return $submittedEmail !== $user->email;
    }
}
