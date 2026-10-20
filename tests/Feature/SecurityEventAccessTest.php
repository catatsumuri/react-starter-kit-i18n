<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityEventAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('admin.security-events.index'))->assertRedirect(route('login'));
    }

    public function test_a_user_who_is_not_a_viewer_is_forbidden(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);

        $this->actingAs(User::factory()->create(['email' => 'other@example.com']))
            ->getJson(route('admin.security-events.index'))
            ->assertForbidden();
    }

    public function test_nobody_can_view_when_no_viewers_are_configured(): void
    {
        config(['audit.security_viewers' => []]);

        $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
            ->getJson(route('admin.security-events.index'))
            ->assertForbidden();
    }

    public function test_a_viewer_sees_only_security_events_newest_first(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);
        $this->recordEvent('security', 'password.changed', 'success');
        $this->recordEvent('other', 'note', 'success');
        $this->recordEvent('security', 'login.failed', 'failure');

        $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
            ->getJson(route('admin.security-events.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'login.failed')
            ->assertJsonPath('data.0.result', 'failure')
            ->assertJsonPath('data.1.event', 'password.changed');
    }

    public function test_the_viewer_email_is_matched_case_insensitively(): void
    {
        config(['audit.security_viewers' => ['Viewer@Example.com']]);

        $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
            ->getJson(route('admin.security-events.index'))
            ->assertOk();
    }

    public function test_a_viewer_with_an_unverified_email_is_forbidden(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);

        $this->actingAs(User::factory()->unverified()->create(['email' => 'viewer@example.com']))
            ->get(route('admin.security-events.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_events_can_be_filtered_by_result(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);
        $this->recordEvent('security', 'password.changed', 'success');
        $this->recordEvent('security', 'login.failed', 'failure');

        $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
            ->getJson(route('admin.security-events.index', ['result' => 'failure']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'login.failed');
    }

    public function test_events_can_be_filtered_by_event_name(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);
        $this->recordEvent('security', 'password.changed', 'success');
        $this->recordEvent('security', 'login.failed', 'failure');

        $this->actingAs(User::factory()->create(['email' => 'viewer@example.com']))
            ->getJson(route('admin.security-events.index', ['event' => 'password.changed']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'password.changed');
    }

    public function test_security_events_cannot_be_changed_over_http(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);

        $this->actingAs($viewer)->putJson('/admin/security-events')->assertStatus(405);
        $this->actingAs($viewer)->deleteJson('/admin/security-events')->assertStatus(405);
        $this->actingAs($viewer)->postJson('/admin/security-events')->assertStatus(405);
    }

    private function recordEvent(string $logName, string $event, string $result): void
    {
        activity($logName)->event($event)->withProperties(['result' => $result])->log($event);
    }
}
