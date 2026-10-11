<?php

namespace App\Actions\Security;

use App\Events\SecurityActivityObserved;
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
        $properties = $this->properties(
            result: 'success',
            authenticationMethod: $authenticationMethod,
            metadata: $metadata,
            sessionFingerprint: $sessionFingerprint,
        );

        try {
            $logger = activity('security')
                ->event($event)
                ->withProperties($properties);

            $actor === null ? $logger->byAnonymous() : $logger->causedBy($actor);

            if ($target !== null) {
                $logger->performedOn($target);
            }

            $logger->log($event);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->observe($event, $properties, $actor, $target);
    }

    /** @param array<string, bool|int|string|null> $metadata */
    public function failure(
        string $event,
        ?User $target = null,
        ?string $authenticationMethod = null,
        array $metadata = [],
    ): void {
        $properties = $this->properties('failure', $authenticationMethod, $metadata);

        try {
            Log::channel('security')->warning($event, [
                ...$properties,
                'target_user_id' => $target?->getKey(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->observe($event, $properties, null, $target);
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

    /** @param array<string, mixed> $properties */
    private function observe(string $event, array $properties, ?User $actor, ?User $target): void
    {
        try {
            $ipAddress = is_string($properties['client_ip']) ? $properties['client_ip'] : null;
            $userAgent = is_string($properties['user_agent']) ? $properties['user_agent'] : null;
            $client = $this->clients->describe($userAgent);
            $clientDescription = implode(' on ', array_filter([$client['browser'], $client['platform']]));
            $clientKey = $client['browser'] === 'Unknown browser' && $client['platform'] === null
                ? null
                : $this->keyedIdentifier($clientDescription);

            SecurityActivityObserved::dispatch(
                event: $event,
                result: (string) $properties['result'],
                actorUserId: $actor?->getKey(),
                targetUserId: $target?->getKey(),
                authenticationMethod: is_string($properties['auth_method']) ? $properties['auth_method'] : null,
                requestId: (string) $properties['request_id'],
                networkKey: $this->keyedIdentifier($ipAddress ?? 'unknown'),
                maskedIpAddress: $this->clients->maskIpAddress($ipAddress),
                clientKey: $clientKey,
                clientDescription: $clientDescription,
                metadata: is_array($properties['metadata']) ? $properties['metadata'] : [],
                sessionFingerprint: is_string($properties['session_fingerprint']) ? $properties['session_fingerprint'] : null,
                userAgent: $userAgent,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function keyedIdentifier(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
