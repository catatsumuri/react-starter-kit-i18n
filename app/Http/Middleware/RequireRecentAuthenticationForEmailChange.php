<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentAuthenticationForEmailChange
{
    public function __construct(private RequirePassword $password) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->changesEmail($request, $user)) {
            return $next($request);
        }

        return $this->password->handle($request, $next);
    }

    private function changesEmail(Request $request, User $user): bool
    {
        $submittedEmail = Str::lower(trim($request->string('email')->toString()));

        return $submittedEmail !== $user->email;
    }
}
