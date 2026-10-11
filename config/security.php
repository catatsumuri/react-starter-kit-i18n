<?php

return [
    'detection' => [
        'enabled' => (bool) env('SECURITY_DETECTION_ENABLED', false),
        'cache_store' => env('SECURITY_DETECTION_CACHE_STORE', 'redis'),
        'alert_channel' => env('SECURITY_ALERT_LOG_CHANNEL', 'security'),
        'alert_cooldown_minutes' => (int) env('SECURITY_ALERT_COOLDOWN_MINUTES', 60),
        'known_client_days' => (int) env('SECURITY_KNOWN_CLIENT_DAYS', 180),
        'thresholds' => [
            'global_failures' => [
                'count' => (int) env('SECURITY_GLOBAL_FAILURE_LIMIT', 100),
                'minutes' => (int) env('SECURITY_GLOBAL_FAILURE_WINDOW', 5),
            ],
            'network_failures' => [
                'count' => (int) env('SECURITY_NETWORK_FAILURE_LIMIT', 25),
                'minutes' => (int) env('SECURITY_NETWORK_FAILURE_WINDOW', 5),
            ],
            'account_failures' => [
                'count' => (int) env('SECURITY_ACCOUNT_FAILURE_LIMIT', 10),
                'minutes' => (int) env('SECURITY_ACCOUNT_FAILURE_WINDOW', 10),
            ],
            'network_accounts' => [
                'count' => (int) env('SECURITY_NETWORK_ACCOUNT_LIMIT', 10),
                'minutes' => (int) env('SECURITY_NETWORK_ACCOUNT_WINDOW', 10),
            ],
            'account_networks' => [
                'count' => (int) env('SECURITY_ACCOUNT_NETWORK_LIMIT', 5),
                'minutes' => (int) env('SECURITY_ACCOUNT_NETWORK_WINDOW', 10),
            ],
            'account_changes' => [
                'count' => (int) env('SECURITY_ACCOUNT_CHANGE_LIMIT', 3),
                'minutes' => (int) env('SECURITY_ACCOUNT_CHANGE_WINDOW', 15),
            ],
        ],
    ],
    'notifications' => [
        'enabled' => (bool) env('SECURITY_NOTIFICATIONS_ENABLED', true),
        'mail' => (bool) env('SECURITY_NOTIFICATION_MAIL_ENABLED', true),
        'same_event_cooldown_minutes' => (int) env('SECURITY_NOTIFICATION_COOLDOWN_MINUTES', 15),
        'mail_hourly_limit' => (int) env('SECURITY_NOTIFICATION_MAIL_HOURLY_LIMIT', 5),
        'events' => [
            'account.password_changed' => ['database' => true, 'mail' => true],
            'account.email_changed' => ['database' => true, 'mail' => true],
            'authentication.two_factor_confirmed' => ['database' => true, 'mail' => true],
            'authentication.two_factor_disabled' => ['database' => true, 'mail' => true],
            'authentication.recovery_codes_regenerated' => ['database' => true, 'mail' => true],
            'authentication.passkey_registered' => ['database' => true, 'mail' => true],
            'authentication.passkey_deleted' => ['database' => true, 'mail' => true],
            'session.revoked' => ['database' => true, 'mail' => true],
            'authentication.new_client' => ['database' => true, 'mail' => true],
        ],
    ],
];
