<?php

namespace App\Actions\Sessions;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class ManageDatabaseSessions
{
    public function isAvailable(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * @return array{
     *     currentSession: array{
     *         fingerprint: string,
     *         device: string,
     *         platform: string|null,
     *         ip_address: string,
     *         last_activity: string,
     *         is_current: bool
     *     }|null,
     *     otherSessions: LengthAwarePaginator<int|string, array{
     *         fingerprint: string,
     *         device: string,
     *         platform: string|null,
     *         ip_address: string,
     *         last_activity: string,
     *         is_current: bool
     *     }>
     * }
     */
    public function sessions(User $user, string $currentSessionId): array
    {
        $query = $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('last_activity', '>=', $this->activeSince());

        $currentSession = (clone $query)
            ->where('id', $currentSessionId)
            ->first();

        $otherSessions = (clone $query)
            ->where('id', '!=', $currentSessionId)
            ->orderByDesc('last_activity')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (stdClass $session): array => $this->present($user, $session, false));

        return [
            'currentSession' => $currentSession instanceof stdClass
                ? $this->present($user, $currentSession, true)
                : null,
            'otherSessions' => $otherSessions,
        ];
    }

    public function revoke(User $user, string $currentSessionId, string $fingerprint): bool
    {
        $sessionId = $this->sessionIdForFingerprint($user, $fingerprint);

        if ($sessionId === null || hash_equals($currentSessionId, $sessionId)) {
            return false;
        }

        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', $sessionId)
            ->delete() === 1;
    }

    public function revokeOtherSessions(User $user, string $currentSessionId): int
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }

    public function revokeAllSessions(User $user): int
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }

    public function fingerprint(User $user, string $sessionId): string
    {
        return hash_hmac(
            'sha256',
            $user->getAuthIdentifier().'|'.$sessionId.'|revoke-session',
            hash('sha256', (string) config('app.key'), true),
        );
    }

    /**
     * @return array{
     *     fingerprint: string,
     *     device: string,
     *     platform: string|null,
     *     ip_address: string,
     *     last_activity: string,
     *     is_current: bool
     * }
     */
    private function present(User $user, stdClass $session, bool $isCurrent): array
    {
        $device = $this->describeUserAgent($session->user_agent);

        return [
            'fingerprint' => $this->fingerprint($user, (string) $session->id),
            'device' => $device['browser'],
            'platform' => $device['platform'],
            'ip_address' => $this->maskIpAddress($session->ip_address),
            'last_activity' => Carbon::createFromTimestamp((int) $session->last_activity)->toISOString(),
            'is_current' => $isCurrent,
        ];
    }

    private function sessionIdForFingerprint(User $user, string $fingerprint): ?string
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->pluck('id')
            ->first(function (string $sessionId) use ($user, $fingerprint): bool {
                return hash_equals($this->fingerprint($user, $sessionId), $fingerprint);
            });
    }

    /**
     * @return array{browser: string, platform: string|null}
     */
    private function describeUserAgent(?string $userAgent): array
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return [
                'browser' => 'Unknown browser',
                'platform' => null,
            ];
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Microsoft Edge',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') || str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh'), str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        return [
            'browser' => $browser,
            'platform' => $platform,
        ];
    }

    private function maskIpAddress(?string $ipAddress): string
    {
        if ($ipAddress === null || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            return 'Unknown';
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ipAddress);
            $parts[3] = 'x';

            return implode('.', $parts);
        }

        $packedAddress = inet_pton($ipAddress);

        if ($packedAddress === false) {
            return 'Unknown';
        }

        $networkAddress = inet_ntop(substr($packedAddress, 0, 8).str_repeat("\0", 8));

        return $networkAddress === false ? 'Unknown' : $networkAddress.'/64';
    }

    private function activeSince(): int
    {
        return now()->getTimestamp() - ((int) config('session.lifetime', 120) * 60);
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
