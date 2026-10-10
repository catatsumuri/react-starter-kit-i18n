<?php

namespace App\Actions\Security;

use App\Models\User;
use App\Support\ClientDescriptor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RecordSecurityActivity
{
    public function __construct(private ClientDescriptor $clients) {}

    /** @param array<string, bool|int|string|null> $metadata */
    public function success(
        string $event,
        ?User $actor,
        ?User $target,
        ?string $authenticationMethod = null,
        array $metadata = [],
        ?string $sessionFingerprint = null,
    ): void {
        try {
            $logger = activity('security')
                ->event($event)
                ->withProperties($this->properties(
                    result: 'success',
                    authenticationMethod: $authenticationMethod,
                    metadata: $metadata,
                    sessionFingerprint: $sessionFingerprint,
                ));

            $actor === null ? $logger->byAnonymous() : $logger->causedBy($actor);

            if ($target !== null) {
                $logger->performedOn($target);
            }

            $logger->log($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param array<string, bool|int|string|null> $metadata */
    public function failure(
        string $event,
        ?User $target = null,
        ?string $authenticationMethod = null,
        array $metadata = [],
    ): void {
        try {
            Log::channel('security')->warning($event, [
                ...$this->properties('failure', $authenticationMethod, $metadata),
                'target_user_id' => $target?->getKey(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function resolveTarget(Request $request): ?User
    {
        if ($request->user() instanceof User) {
            return $request->user();
        }

        $login = Str::lower(trim($request->string('email')->toString()));

        if ($login === '') {
            return null;
        }

        return User::query()->where('email', $login)->orWhere('username', $login)->first();
    }

    /**
     * @param  array<string, bool|int|string|null>  $metadata
     * @return array<string, mixed>
     */
    private function properties(
        string $result,
        ?string $authenticationMethod,
        array $metadata,
        ?string $sessionFingerprint = null,
    ): array {
        $request = request();
        $requestId = $request->attributes->get('security_request_id');

        if (! is_string($requestId)) {
            $requestId = (string) Str::uuid();
            $request->attributes->set('security_request_id', $requestId);
        }

        return [
            'result' => $result,
            'request_id' => $requestId,
            'session_fingerprint' => $sessionFingerprint,
            'client_ip' => $request->ip(),
            'user_agent' => $this->clients->sanitizeUserAgent($request->userAgent()),
            'auth_method' => $authenticationMethod,
            'metadata' => $metadata,
        ];
    }
}
