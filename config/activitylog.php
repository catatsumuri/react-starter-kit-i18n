<?php

use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Models\Activity;

return [
    'enabled' => env('ACTIVITYLOG_ENABLED', true),
    'clean_after_days' => (int) env('SECURITY_AUDIT_RETENTION_DAYS', 180),
    'default_log_name' => 'default',
    'default_auth_driver' => null,
    'include_soft_deleted_subjects' => false,
    'activity_model' => Activity::class,
    'default_except_attributes' => [],
    'buffer' => ['enabled' => false],
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
