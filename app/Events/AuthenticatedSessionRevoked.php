<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuthenticatedSessionRevoked
{
    use Dispatchable, SerializesModels;

    public const Individual = 'individual';

    public const OtherSessions = 'other_sessions';

    public const AllSessions = 'all_sessions';

    public const SelfService = 'self_service';

    public const OperatorCli = 'operator_cli';

    public const SecurityChange = 'security_change';

    public function __construct(
        public User $user,
        public string $scope,
        public ?int $revokedCount,
        public ?string $sessionFingerprint = null,
        public ?User $actor = null,
        public string $origin = self::SelfService,
        public ?string $reason = null,
        public bool $notifyOwner = true,
    ) {}
}
