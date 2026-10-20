<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;
use Throwable;

class SecurityEventImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_security_event_cannot_be_updated(): void
    {
        $event = $this->securityEvent();

        $this->attempt(fn () => $event->update(['description' => 'tampered']));
        $this->attempt(fn () => $event->forceFill(['properties' => ['result' => 'success']])->save());

        $stored = Activity::query()->findOrFail($event->getKey());

        $this->assertSame('login.failed', $stored->description);
        $this->assertSame('failure', $stored->getProperty('result'));
    }

    public function test_a_security_event_cannot_be_deleted(): void
    {
        $event = $this->securityEvent();

        $this->attempt(fn () => $event->delete());
        $this->attempt(fn () => $event->forceDelete());

        $this->assertModelExists($event);
    }

    public function test_deleting_the_subject_user_keeps_its_security_events(): void
    {
        $user = User::factory()->create();
        $event = $this->securityEvent($user);

        $user->delete();

        $this->assertModelExists($event);
    }

    public function test_the_retention_cleanup_still_deletes_expired_security_events(): void
    {
        $expired = $this->securityEvent();
        Activity::query()->whereKey($expired->getKey())->toBase()->update(['created_at' => now()->subDays(366)]);
        $recent = $this->securityEvent();

        $this->artisan('activitylog:clean', ['log' => 'security', '--days' => 365, '--force' => true])->assertSuccessful();

        $this->assertModelMissing($expired);
        $this->assertModelExists($recent);
    }

    public function test_other_logs_are_not_affected_by_the_guard(): void
    {
        $other = activity('other')->log('note');

        $other->update(['description' => 'edited']);

        $this->assertSame('edited', $other->fresh()->description);
    }

    private function securityEvent(?User $subject = null): Activity
    {
        $logger = activity('security')->event('login.failed')->withProperties(['result' => 'failure']);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        return $logger->log('login.failed');
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (Throwable) {
            // The guard may reject by exception or by returning false; only the stored row matters.
        }
    }
}
