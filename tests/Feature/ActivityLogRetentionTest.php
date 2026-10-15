<?php

namespace Tests\Feature;

use Tests\TestCase;

class ActivityLogRetentionTest extends TestCase
{
    public function test_activity_log_cleanup_is_scheduled_daily_per_log_with_configured_days(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain("activitylog:clean 'http' --days=90 --force")
            ->expectsOutputToContain("activitylog:clean 'security' --days=365 --force")
            ->assertSuccessful();
    }

    public function test_retention_days_are_positive_integers(): void
    {
        foreach (config('audit.retention_days') as $days) {
            $this->assertIsInt($days);
            $this->assertGreaterThanOrEqual(1, $days);
        }
    }
}
