<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LogWriteRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_requests_are_logged_with_field_names_but_not_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret-value',
            'password_confirmation' => 'new-secret-value',
        ]);

        $activity = Activity::inLog('http')->sole();

        $this->assertSame('http.write', $activity->event->value ?? $activity->event);
        $this->assertTrue($activity->causer->is($user));
        $this->assertSame('user-password.update', $activity->getProperty('route'));
        $this->assertContains('password', $activity->getProperty('fields'));
        $this->assertStringNotContainsString('new-secret-value', json_encode($activity->properties));
    }

    public function test_logout_keeps_the_acting_user_as_causer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('logout'));

        $this->assertSame($user->id, Activity::inLog('http')->sole()->causer_id);
    }

    public function test_account_deletion_keeps_the_acting_user_as_causer(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password']);

        $this->assertModelMissing($user);
        $this->assertSame($user->id, Activity::inLog('http')->sole()->causer_id);
    }

    public function test_route_parameter_values_and_oversized_fields_are_not_stored(): void
    {
        Route::middleware('web')->post('/audit-probe/{token}/{item}', fn () => 'ok');

        $this->post('/audit-probe/SECRETTOKEN/'.str_repeat('a', 500).'?api_key=QSECRET', [
            'body_secret' => 'BODYSECRET',
            str_repeat('f', 500) => 'x',
        ] + array_fill_keys(range(1, 100), 'x'));

        $activity = Activity::inLog('http')->sole();
        $stored = json_encode($activity->properties);

        $this->assertSame('audit-probe/{token}/{item}', $activity->getProperty('path'));
        $this->assertArrayNotHasKey('parameters', $activity->properties->toArray());
        $this->assertLessThanOrEqual(50, count($activity->getProperty('fields')));
        foreach (['SECRETTOKEN', 'QSECRET', 'BODYSECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored.$activity->description);
        }
    }

    public function test_validation_failures_are_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson(route('user-password.update'), []);

        $this->assertSame(422, Activity::inLog('http')->sole()->getProperty('status'));
    }

    public function test_a_logging_failure_does_not_break_the_response(): void
    {
        Schema::drop('activity_log');

        $this->post(route('logout'))->assertRedirect();
    }

    public function test_read_requests_are_not_logged(): void
    {
        $this->get(route('home'));

        $this->assertSame(0, Activity::inLog('http')->count());
    }
}
