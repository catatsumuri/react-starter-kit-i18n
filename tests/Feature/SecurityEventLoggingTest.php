<?php

namespace Tests\Feature;

use App\Actions\LogSecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Password;
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

    public function test_resetting_the_password_records_a_security_event_for_the_user(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.update'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'new-secret-value',
            'password_confirmation' => 'new-secret-value',
        ])->assertRedirect(route('login'));

        $event = Activity::inLog('security')->sole();
        $http = Activity::inLog('http')->sole();

        $this->assertSame('password.reset', $event->description);
        $this->assertTrue($event->subject->is($user));
        $this->assertNull($event->causer);
        $this->assertSame($http->getProperty('request_id'), $event->getProperty('request_id'));
        $this->assertTrue(password_verify('new-secret-value', $user->fresh()->password));
    }

    public function test_a_failed_password_reset_does_not_record_a_security_event(): void
    {
        $user = User::factory()->create();

        $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'new-secret-value',
            'password_confirmation' => 'new-secret-value',
        ]);

        $this->assertSame(0, Activity::inLog('security')->count());
        $this->assertTrue(password_verify('password', $user->fresh()->password));
    }

    public function test_a_cancelled_password_reset_is_not_recorded(): void
    {
        $user = User::factory()->create();
        User::updating(fn () => false);

        try {
            $this->post(route('password.update'), [
                'token' => Password::createToken($user),
                'email' => $user->email,
                'password' => 'new-secret-value',
                'password_confirmation' => 'new-secret-value',
            ])->assertStatus(500);
        } finally {
            User::flushEventListeners();
        }

        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_changing_the_email_records_a_security_event_without_email_values(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'new@example.com',
        ]);

        $event = Activity::inLog('security')->sole();
        $http = Activity::inLog('http')->sole();

        $this->assertSame('email.changed', $event->description);
        $this->assertTrue($event->subject->is($user));
        $this->assertSame($http->getProperty('request_id'), $event->getProperty('request_id'));
        $this->assertStringNotContainsString('example.com', json_encode($event->properties));
        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_updating_only_the_name_does_not_record_an_email_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => 'Another Name',
            'email' => $user->email,
        ]);

        $this->assertSame('Another Name', $user->fresh()->name);
        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_a_cancelled_profile_update_is_not_recorded_as_an_email_change(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        User::updating(fn () => false);

        try {
            $this->actingAs($user)->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'new@example.com',
            ])->assertStatus(500);
        } finally {
            User::flushEventListeners();
        }

        $this->assertSame('old@example.com', $user->fresh()->email);
        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_deleting_the_account_records_the_user_id_without_a_subject(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

        $event = Activity::inLog('security')->sole();
        $http = Activity::inLog('http')->sole();

        $this->assertModelMissing($user);
        $this->assertSame('account.deleted', $event->description);
        $this->assertNull($event->subject_id);
        $this->assertSame($user->id, $event->getProperty('user_id'));
        $this->assertSame($user->id, $event->causer_id);
        $this->assertSame($http->getProperty('request_id'), $event->getProperty('request_id'));
    }

    public function test_a_wrong_password_does_not_delete_or_record_the_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'wrong-password']);

        $this->assertModelExists($user);
        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_a_cancelled_account_deletion_is_not_recorded(): void
    {
        $user = User::factory()->create();
        User::deleting(fn () => false);

        try {
            $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertStatus(500);
        } finally {
            User::flushEventListeners();
        }

        $this->assertModelExists($user);
        $this->assertAuthenticatedAs($user);
        $this->assertSame(0, Activity::inLog('security')->count());
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
