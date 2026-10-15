<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

foreach (config('audit.retention_days') as $logName => $days) {
    Schedule::command('activitylog:clean', [$logName, '--days' => $days, '--force'])->daily();
}
