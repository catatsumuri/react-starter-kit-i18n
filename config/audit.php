<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activity Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep activity log records, per log name. The scheduled
    | activitylog:clean command deletes older records for each listed log.
    | Each value must be a positive integer.
    |
    */

    'retention_days' => [
        'http' => max(1, (int) env('AUDIT_HTTP_RETENTION_DAYS', 90)),
        'security' => max(1, (int) env('AUDIT_SECURITY_RETENTION_DAYS', 365)),
    ],

];
