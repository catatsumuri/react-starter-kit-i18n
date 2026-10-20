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
            ->expectsOutputToContain("activitylog:clean 'security' --days=365 --force")
            ->assertSuccessful();
    }

    public function test_cleaning_the_security_log_only_deletes_old_security_records(): void
    {
        $oldSecurity = $this->activityAt('security', 366);
        $recentSecurity = $this->activityAt('security', 364);
        $oldOther = $this->activityAt('other', 366);

        $this->artisan('activitylog:clean', ['log' => 'security', '--days' => 365, '--force' => true])->assertSuccessful();

        $this->assertModelMissing($oldSecurity);
        $this->assertModelExists($recentSecurity);
        $this->assertModelExists($oldOther);
    }

    public function test_invalid_retention_days_do_not_schedule_cleanup(): void
    {
        foreach (['abc', '0', '-5', '', 'true', 'false', 'null'] as $invalid) {
            $_ENV['AUDIT_SECURITY_RETENTION_DAYS'] = $_SERVER['AUDIT_SECURITY_RETENTION_DAYS'] = $invalid;
            $this->refreshApplication();

            $this->artisan('schedule:list')
                ->doesntExpectOutputToContain("activitylog:clean 'security'")
                ->assertSuccessful();
        }
    }

    protected function tearDown(): void
    {
        unset($_ENV['AUDIT_SECURITY_RETENTION_DAYS'], $_SERVER['AUDIT_SECURITY_RETENTION_DAYS']);

        parent::tearDown();
    }

    private function activityAt(string $logName, int $daysAgo): Activity
    {
        $activity = activity($logName)->log('test');
        $activity->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $activity;
    }
}
