<?php

namespace Tests\Feature;

use App\Actions\LogSecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SecurityEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_password_records_a_security_event_linked_to_the_http_log(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret-value',
            'password_confirmation' => 'new-secret-value',
        ]);

        $event = Activity::inLog('security')->sole();
        $http = Activity::inLog('http')->sole();

        $this->assertSame('password.changed', $event->description);
        $this->assertTrue($event->causer->is($user));
        $this->assertTrue($event->subject->is($user));
        $this->assertNotNull($event->getProperty('request_id'));
        $this->assertSame($http->getProperty('request_id'), $event->getProperty('request_id'));
        $this->assertStringNotContainsString('new-secret-value', json_encode($event->properties));
    }

    public function test_a_failed_password_change_does_not_record_a_security_event(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-secret-value',
            'password_confirmation' => 'new-secret-value',
        ]);

        $this->assertSame(0, Activity::inLog('security')->count());
        $this->assertSame(1, Activity::inLog('http')->count());
    }

    public function test_a_cancelled_password_update_is_not_recorded_as_a_change(): void
    {
        $user = User::factory()->create();
        User::updating(fn () => false);

        try {
            $this->actingAs($user)->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-secret-value',
                'password_confirmation' => 'new-secret-value',
            ])->assertStatus(500);
        } finally {
            User::flushEventListeners();
        }

        $this->assertSame(0, Activity::inLog('security')->count());
        $this->assertTrue(password_verify('password', $user->fresh()->password));
    }

    public function test_the_request_id_cannot_be_overridden_by_the_caller(): void
    {
        Context::add('request_id', 'REAL-ID');

        app(LogSecurityEvent::class)('password.changed', null, ['request_id' => 'FORGED-ID']);

        $this->assertSame('REAL-ID', Activity::inLog('security')->sole()->getProperty('request_id'));
    }

    public function test_a_logging_failure_does_not_undo_the_password_change(): void
    {
        $user = User::factory()->create();
        config(['activitylog.activity_model' => 'App\\Models\\MissingActivity']);

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret-value',
            'password_confirmation' => 'new-secret-value',
        ])->assertRedirect();

        $this->assertTrue(password_verify('new-secret-value', $user->fresh()->password));
    }
}
