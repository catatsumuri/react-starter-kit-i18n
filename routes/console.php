<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

foreach (config('audit.retention_days') as $logName => $days) {
    $validDays = is_int($days) || is_string($days)
        ? filter_var($days, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;

    if ($validDays === false) {
        report(new InvalidArgumentException("Invalid audit retention days for log [{$logName}]: cleanup is not scheduled."));

        continue;
    }

    Schedule::command('activitylog:clean', [$logName, '--days' => $validDays, '--force'])->daily();
}
