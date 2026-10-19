<?php

namespace App\Actions\Sessions;

use App\Models\User;
use App\Support\ClientDescriptor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class ManageDatabaseSessions
{
    public function __construct(private ClientDescriptor $clients) {}

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

        // A remember me cookie can recreate the session just revoked, and the
        // token is shared by every browser, so it is rotated here as well.
        $this->resetRememberToken($user);

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

    /**
     * Rotate the remember token so existing "remember me" cookies stop
     * authenticating, even though they do not depend on a stored session.
     */
    public function resetRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    public function activeSessionCount(User $user): int
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('last_activity', '>=', $this->activeSince())
            ->count();
    }

    public function storedSessionCount(User $user): int
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->count();
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
        $device = $this->clients->describe($session->user_agent);

        return [
            'fingerprint' => $this->fingerprint($user, (string) $session->id),
            'device' => $device['browser'],
            'platform' => $device['platform'],
            'ip_address' => $this->clients->maskIpAddress($session->ip_address),
            'last_activity' => Carbon::createFromTimestamp((int) $session->last_activity)->toISOString(),
            'is_current' => $isCurrent,
        ];
    }

    /**
     * Fingerprints cannot be reversed, so every candidate is hashed in turn. Only
     * active sessions are candidates (the same ones the session list shows) and
     * rows are streamed rather than loaded at once.
     */
    private function sessionIdForFingerprint(User $user, string $fingerprint): ?string
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('last_activity', '>=', $this->activeSince())
            ->select('id')
            ->cursor()
            ->pluck('id')
            ->first(function (string $sessionId) use ($user, $fingerprint): bool {
                return hash_equals($this->fingerprint($user, $sessionId), $fingerprint);
            });
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
