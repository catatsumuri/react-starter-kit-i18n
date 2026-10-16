<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Activity Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to keep activity log records, per log name. The scheduled
    | activitylog:clean command deletes older records for each listed log.
    | Each value must be a positive integer. Values are deliberately not
    | coerced here: an invalid value disables cleanup for that log and is
    | reported, instead of being silently turned into a shorter retention.
    |
    */

    'retention_days' => [
        'http' => env('AUDIT_HTTP_RETENTION_DAYS', 90),
        'security' => env('AUDIT_SECURITY_RETENTION_DAYS', 365),
    ],

];
