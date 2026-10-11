<?php

namespace App\Actions\Security;

use App\Events\SecurityActivityObserved;
use App\Jobs\SendSecurityAlert;
use App\Jobs\SendSecurityNotification;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DetectSecurityThreats
{
    /** @var array<int, string> */
    private const AccountChangeEvents = [
        'authentication.two_factor_confirmed',
        'authentication.two_factor_disabled',
        'authentication.recovery_codes_regenerated',
        'authentication.passkey_registered',
        'authentication.passkey_deleted',
        'account.password_changed',
        'account.email_changed',
    ];

    public function handle(SecurityActivityObserved $event): void
    {
        if (! config('security.detection.enabled', false)) {
            return;
        }

        try {
            $cache = Cache::store(config('security.detection.cache_store', 'redis'));

            if ($event->result === 'failure') {
                $this->detectAuthenticationFailures($cache, $event);
            }

            if ($event->result === 'success') {
                $this->detectAccountChanges($cache, $event);
                $this->detectNewClient($cache, $event);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function detectAuthenticationFailures(Repository $cache, SecurityActivityObserved $event): void
    {
        $global = $this->increment($cache, 'failures:global', 'global_failures');
        $this->alertAtThreshold($cache, 'authentication.global_failures', 'global', $global, 'global_failures', $event);

        $network = $this->increment($cache, 'failures:network:'.$event->networkKey, 'network_failures');
        $this->alertAtThreshold($cache, 'authentication.network_failures', $event->networkKey, $network, 'network_failures', $event);

        if ($event->targetUserId === null) {
            return;
        }

        $accountScope = (string) $event->targetUserId;
        $account = $this->increment($cache, 'failures:account:'.$accountScope, 'account_failures');
        $this->alertAtThreshold($cache, 'authentication.account_failures', $accountScope, $account, 'account_failures', $event);

        if ($this->addMarker($cache, 'marker:network-account:'.$event->networkKey.':'.$accountScope, 'network_accounts')) {
            $accounts = $this->increment($cache, 'distinct:network-accounts:'.$event->networkKey, 'network_accounts');
            $this->alertAtThreshold($cache, 'authentication.network_accounts', $event->networkKey, $accounts, 'network_accounts', $event);
        }

        if ($this->addMarker($cache, 'marker:account-network:'.$accountScope.':'.$event->networkKey, 'account_networks')) {
            $networks = $this->increment($cache, 'distinct:account-networks:'.$accountScope, 'account_networks');
            $this->alertAtThreshold($cache, 'authentication.account_networks', $accountScope, $networks, 'account_networks', $event);
        }
    }

    private function detectAccountChanges(Repository $cache, SecurityActivityObserved $event): void
    {
        if ($event->targetUserId === null || ! in_array($event->event, self::AccountChangeEvents, true)) {
            return;
        }

        $scope = (string) $event->targetUserId;
        $count = $this->increment($cache, 'changes:account:'.$scope, 'account_changes');
        $this->alertAtThreshold($cache, 'account.security_changes', $scope, $count, 'account_changes', $event);
    }

    private function detectNewClient(Repository $cache, SecurityActivityObserved $event): void
    {
        if ($event->event !== 'authentication.login_succeeded' || $event->targetUserId === null || $event->clientKey === null) {
            return;
        }

        $days = (int) config('security.detection.known_client_days', 180);
        $key = 'security-detection:client:'.$event->targetUserId.':'.$event->clientKey;
        $isNew = $cache->add($key, true, now()->addDays($days));

        if (! $isNew) {
            $cache->put($key, true, now()->addDays($days));

            return;
        }

        $eventConfiguration = config('security.notifications.events', []);
        $channels = is_array($eventConfiguration)
            ? ($eventConfiguration['authentication.new_client'] ?? [])
            : [];
        $databaseEnabled = is_array($channels) && ($channels['database'] ?? false) === true;
        $mailEnabled = (bool) config('security.notifications.mail', true)
            && is_array($channels)
            && ($channels['mail'] ?? false) === true;

        if (! config('security.notifications.enabled', true) || (! $databaseEnabled && ! $mailEnabled)) {
            return;
        }

        SendSecurityNotification::dispatch(
            userId: $event->targetUserId,
            event: 'authentication.new_client',
            title: __('New sign-in environment'),
            body: __('A sign-in was detected from :client (:ip).', [
                'client' => $event->clientDescription,
                'ip' => $event->maskedIpAddress,
            ]),
            requestId: $event->requestId,
        );
    }

    private function increment(Repository $cache, string $key, string $threshold): int
    {
        $key = 'security-detection:'.$key;
        $seconds = $this->windowMinutes($threshold) * 60;
        $cache->add($key, 0, $seconds);

        return (int) $cache->increment($key);
    }

    private function addMarker(Repository $cache, string $key, string $threshold): bool
    {
        return $cache->add(
            'security-detection:'.$key,
            true,
            $this->windowMinutes($threshold) * 60,
        );
    }

    private function alertAtThreshold(
        Repository $cache,
        string $alert,
        string $scope,
        int $count,
        string $threshold,
        SecurityActivityObserved $event,
    ): void {
        $limit = (int) config('security.detection.thresholds.'.$threshold.'.count');

        if ($count < $limit) {
            return;
        }

        $deduplicationKey = 'security-detection:alert:'.$alert.':'.$scope;
        $cooldown = (int) config('security.detection.alert_cooldown_minutes', 60);

        if (! $cache->add($deduplicationKey, true, now()->addMinutes($cooldown))) {
            return;
        }

        try {
            SendSecurityAlert::dispatch($alert, [
                'count' => $count,
                'window_minutes' => $this->windowMinutes($threshold),
                'target_user_id' => $event->targetUserId,
                'scope_key' => $scope === (string) $event->targetUserId ? null : $scope,
                'ip_address' => $event->maskedIpAddress,
                'occurred_at' => now()->toISOString(),
            ]);
        } catch (Throwable $exception) {
            $cache->forget($deduplicationKey);
            throw $exception;
        }
    }

    private function windowMinutes(string $threshold): int
    {
        return (int) config('security.detection.thresholds.'.$threshold.'.minutes');
    }
}
