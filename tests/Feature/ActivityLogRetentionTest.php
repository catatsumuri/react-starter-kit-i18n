<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_log_cleanup_is_scheduled_daily_per_log_with_configured_days(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain("activitylog:clean 'http' --days=90 --force")
            ->expectsOutputToContain("activitylog:clean 'security' --days=365 --force")
            ->assertSuccessful();
    }

    public function test_cleaning_the_http_log_only_deletes_old_http_records(): void
    {
        $oldHttp = $this->activityAt('http', 91);
        $recentHttp = $this->activityAt('http', 89);
        $oldSecurity = $this->activityAt('security', 91);

        $this->artisan('activitylog:clean', ['log' => 'http', '--days' => 90, '--force' => true])->assertSuccessful();

        $this->assertModelMissing($oldHttp);
        $this->assertModelExists($recentHttp);
        $this->assertModelExists($oldSecurity);
    }

    public function test_invalid_retention_days_do_not_schedule_cleanup(): void
    {
        foreach (['abc', '0', '-5', ''] as $invalid) {
            $_ENV['AUDIT_HTTP_RETENTION_DAYS'] = $_SERVER['AUDIT_HTTP_RETENTION_DAYS'] = $invalid;
            $this->refreshApplication();

            $this->artisan('schedule:list')
                ->doesntExpectOutputToContain("activitylog:clean 'http'")
                ->expectsOutputToContain("activitylog:clean 'security' --days=365 --force")
                ->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['AUDIT_HTTP_RETENTION_DAYS'], $_SERVER['AUDIT_HTTP_RETENTION_DAYS']);

        parent::tearDown();
    }

    private function activityAt(string $logName, int $daysAgo): Activity
    {
        $activity = activity($logName)->log('test');
        $activity->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $activity;
    }
}
