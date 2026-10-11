<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class AuthenticatedSessionContext
{
    public const StartedAt = 'auth.absolute_started_at';

    public const Version = 'auth.security_version';

    /** @return array{started_at: int, version: int}|null */
    public function resolve(Request $request, User $user, bool $viaRemember): ?array
    {
        $startedAt = $request->session()->get(self::StartedAt);
        $version = $request->session()->get(self::Version);

        if (is_int($startedAt) && is_int($version)) {
            return ['started_at' => $startedAt, 'version' => $version];
        }

        $cookie = $this->parse($request->cookie($this->cookieName()), $user);

        if ($cookie !== null) {
            $request->session()->put([self::StartedAt => $cookie['started_at'], self::Version => $cookie['version']]);

            return $cookie;
        }

        if ($viaRemember) {
            return null;
        }

        return $this->reset($request, $user);
    }

    /** @return array{started_at: int, version: int} */
    public function reset(Request $request, User $user): array
    {
        $context = ['started_at' => now()->getTimestamp(), 'version' => $user->auth_session_version];
        $request->session()->put([self::StartedAt => $context['started_at'], self::Version => $context['version']]);
        $this->queue($user, $context);

        return $context;
    }

    public function updateVersion(Request $request, User $user): void
    {
        $startedAt = $request->session()->get(self::StartedAt, now()->getTimestamp());
        $context = ['started_at' => (int) $startedAt, 'version' => $user->auth_session_version];
        $request->session()->put(self::Version, $context['version']);
        $this->queue($user, $context);
    }

    public function forget(): void
    {
        Cookie::queue(Cookie::forget($this->cookieName(), (string) config('session.path', '/'), config('session.domain')));
    }

    /** @param array{started_at: int, version: int} $context */
    private function queue(User $user, array $context): void
    {
        $lifetime = $this->lifetimeSeconds();
        $minutes = $lifetime === 0
            ? 576000
            : max(1, (int) ceil(($context['started_at'] + $lifetime - now()->getTimestamp()) / 60));
        Cookie::queue(cookie(
            $this->cookieName(),
            json_encode(['user_id' => $user->getKey(), ...$context], JSON_THROW_ON_ERROR),
            $minutes,
            (string) config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            (string) config('session.same_site', 'lax'),
        ));
    }

    /** @return array{started_at: int, version: int}|null */
    private function parse(mixed $value, User $user): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)
            || (int) ($decoded['user_id'] ?? 0) !== (int) $user->getKey()
            || ! is_int($decoded['started_at'] ?? null)
            || ! is_int($decoded['version'] ?? null)) {
            return null;
        }

        return ['started_at' => $decoded['started_at'], 'version' => $decoded['version']];
    }

    public function lifetimeSeconds(): int
    {
        return max(0, (int) config('session.absolute_lifetime', 10080)) * 60;
    }

    /** @return non-empty-string */
    private function cookieName(): string
    {
        $name = config('session.authentication_context_cookie');

        return is_string($name) && $name !== '' ? $name : 'authentication-context';
    }
}
