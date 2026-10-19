<?php

namespace App\Actions\Sessions;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class ManageDatabaseSessions
{
    public function isAvailable(): bool
    {
        return config('session.driver') === 'database';
    }

    public function revokeAllSessions(User $user): int
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }

    public function storedSessionCount(User $user): int
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->count();
    }

    private function connection(): ConnectionInterface
    {
        $connection = config('session.connection');

        return DB::connection(is_string($connection) ? $connection : null);
    }

    private function table(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
