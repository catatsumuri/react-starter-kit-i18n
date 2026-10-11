<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

class PurposeBoundAuthentication
{
    public const EmailChange = 'email_change';

    public const SecuritySettings = 'security_settings';

    public const PendingPurpose = 'auth.reauthentication.pending_purpose';

    public const ConfirmedPurpose = 'auth.reauthentication.confirmed_purpose';

    private const FollowUpRoutes = 'auth.reauthentication.follow_up_routes';

    private const FollowUpLifetimeSeconds = 60;

    public function purposeFor(Request $request): string
    {
        if ($request->routeIs('profile.update')) {
            return self::EmailChange;
        }

        if ($request->routeIs([
            'security.edit',
            'two-factor.*',
            'passkey.registration-options',
            'passkey.store',
            'passkey.destroy',
        ])) {
            return self::SecuritySettings;
        }

        $routeName = $request->route()?->getName();

        return is_string($routeName) ? 'route:'.$routeName : 'route:unknown';
    }

    public function isConfirmed(Request $request, string $purpose, int $timeout): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        return is_int($confirmedAt)
            && Date::now()->unix() - $confirmedAt <= $timeout
            && $request->session()->get(self::ConfirmedPurpose) === $purpose;
    }

    public function prepareConfirmation(Request $request, string $purpose): void
    {
        $request->session()->put(self::PendingPurpose, $purpose);
        $request->session()->forget([
            'auth.password_confirmed_at',
            self::ConfirmedPurpose,
            self::FollowUpRoutes,
        ]);
    }

    public function confirmPending(Request $request): void
    {
        $purpose = $request->session()->pull(self::PendingPurpose);

        if (is_string($purpose)) {
            $request->session()->put(self::ConfirmedPurpose, $purpose);
        }
    }

    /** @param list<string> $followUpRoutes */
    public function consume(Request $request, array $followUpRoutes = []): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $request->session()->forget([
            'auth.password_confirmed_at',
            self::PendingPurpose,
            self::ConfirmedPurpose,
        ]);

        if ($followUpRoutes === []) {
            $request->session()->forget(self::FollowUpRoutes);

            return;
        }

        $request->session()->put(self::FollowUpRoutes, [
            'routes' => array_values(array_unique($followUpRoutes)),
            'expires_at' => Date::now()->unix() + self::FollowUpLifetimeSeconds,
        ]);
    }

    public function consumeFollowUp(Request $request): bool
    {
        $routeName = $request->route()?->getName();
        $followUp = $request->session()->get(self::FollowUpRoutes, []);

        if (! is_string($routeName)
            || ! is_array($followUp)
            || ! is_int($followUp['expires_at'] ?? null)
            || $followUp['expires_at'] < Date::now()->unix()
            || ! is_array($followUp['routes'] ?? null)
            || ! in_array($routeName, $followUp['routes'], true)) {
            $request->session()->forget(self::FollowUpRoutes);

            return false;
        }

        $remainingRoutes = array_values(array_filter(
            $followUp['routes'],
            fn (mixed $route): bool => $route !== $routeName,
        ));

        if ($remainingRoutes === []) {
            $request->session()->forget(self::FollowUpRoutes);
        } else {
            $request->session()->put(self::FollowUpRoutes, [
                'routes' => $remainingRoutes,
                'expires_at' => $followUp['expires_at'],
            ]);
        }

        return true;
    }
}
