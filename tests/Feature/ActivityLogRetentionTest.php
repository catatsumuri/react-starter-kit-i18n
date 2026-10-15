<?php

namespace Tests\Feature;

use Tests\TestCase;

class ActivityLogRetentionTest extends TestCase
{
    public function test_activity_log_cleanup_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('activitylog:clean --force')
            ->assertSuccessful();
    }
}
