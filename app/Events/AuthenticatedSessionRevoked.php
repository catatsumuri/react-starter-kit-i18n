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

    public function __construct(
        public User $user,
        public string $scope,
        public int $revokedCount,
        public ?string $sessionFingerprint = null,
    ) {}
}
