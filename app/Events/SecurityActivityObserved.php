<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class SecurityActivityObserved
{
    use Dispatchable;

    /**
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function __construct(
        public string $event,
        public string $result,
        public ?int $actorUserId,
        public ?int $targetUserId,
        public ?string $authenticationMethod,
        public string $requestId,
        public string $networkKey,
        public string $maskedIpAddress,
        public ?string $clientKey,
        public string $clientDescription,
        public array $metadata = [],
        public ?string $sessionFingerprint = null,
        public ?string $userAgent = null,
    ) {}
}
