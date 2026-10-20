<?php

namespace App\Actions\Sessions;

use App\Models\User;
use App\Support\ClientDescriptor;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

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
        // token is shared by every browser, so it is rotated together with the
        // deletion, and only when a session was actually deleted.
        return $this->withRememberMeReset(
            $user,
            fn (): bool => $this->deleteSession($user, $sessionId) === 1,
            fn (bool $deleted): bool => $deleted,
        );
    }

    public function revokeOtherSessions(User $user, string $currentSessionId): int
    {
        return $this->deleteSessions($user, $currentSessionId);
    }

    public function revokeAllSessions(User $user): int
    {
        return $this->deleteSessions($user, null);
    }

    /**
     * Revoke every other session and end all remember me logins together.
     *
     * @throws SessionRevocationException when only the remember me reset went through
     */
    public function revokeOtherSessionsAndRememberMe(User $user, string $currentSessionId): int
    {
        return $this->withRememberMeReset(
            $user,
            fn (): int => $this->deleteSessions($user, $currentSessionId),
            fn (): bool => true,
        );
    }

    /**
     * Revoke every session and end all remember me logins together.
     *
     * @throws SessionRevocationException when only the remember me reset went through
     */
    public function revokeAllSessionsAndRememberMe(User $user): int
    {
        return $this->withRememberMeReset(
            $user,
            fn (): int => $this->deleteSessions($user, null),
            fn (): bool => true,
        );
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
     * Delete sessions and rotate the remember token as one unit.
     *
     * When sessions and users share a database connection both changes happen in
     * one transaction: a failure on either side rolls back both, and the token is
     * only rotated when $shouldReset accepts the deletion result. On separate
     * connections that is impossible, so the token is rotated first (a deleted
     * session could otherwise be recreated by a remember me cookie) and a failing
     * or empty deletion is reported as a partial result.
     *
     * @template T
     *
     * @param  Closure(): T  $delete
     * @param  Closure(T): bool  $shouldReset
     * @return T
     *
     * @throws SessionRevocationException
     */
    private function withRememberMeReset(User $user, Closure $delete, Closure $shouldReset): mixed
    {
        if ($this->sharesConnectionWithUsers($user)) {
            return $this->connection()->transaction(function () use ($user, $delete, $shouldReset) {
                $result = $delete();

                if ($shouldReset($result)) {
                    $this->resetRememberToken($user);
                }

                return $result;
            });
        }

        $this->resetRememberToken($user);

        try {
            $result = $delete();
        } catch (Throwable $exception) {
            throw new SessionRevocationException(rememberMeReset: true, previous: $exception);
        }

        // The token is already rotated, so "nothing deleted" must not look like
        // an untouched account: report it as a partial result instead.
        if (! $shouldReset($result)) {
            throw new SessionRevocationException(rememberMeReset: true);
        }

        return $result;
    }

    private function sharesConnectionWithUsers(User $user): bool
    {
        $default = config('database.default');
        $sessions = config('session.connection');

        return ($user->getConnectionName() ?? $default) === (is_string($sessions) ? $sessions : $default);
    }

    protected function deleteSession(User $user, string $sessionId): int
    {
        return $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', $sessionId)
            ->delete();
    }

    protected function deleteSessions(User $user, ?string $exceptSessionId): int
    {
        $query = $this->connection()
            ->table($this->table())
            ->where('user_id', $user->getAuthIdentifier());

        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }

        return $query->delete();
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
