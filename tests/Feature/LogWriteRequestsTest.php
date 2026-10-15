<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_read_requests_are_not_logged(): void
    {
        $this->get(route('home'));

        $this->assertSame(0, Activity::inLog('http')->count());
    }
}
