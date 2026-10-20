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
        'security' => env('AUDIT_SECURITY_RETENTION_DAYS', 365),
    ],

];
