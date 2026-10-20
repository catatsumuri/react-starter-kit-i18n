<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Features;
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
        $viewer = $this->verifiedViewer();
        $this->recordEvent('security', 'password.changed', 'success');
        $this->recordEvent('other', 'note', 'success');
        $this->recordEvent('security', 'login.failed', 'failure');

        $this->actingAs($viewer)
            ->getJson(route('admin.security-events.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.event', 'login.failed')
            ->assertJsonPath('data.0.result', 'failure')
            ->assertJsonPath('data.1.event', 'password.changed');
    }

    public function test_the_viewer_email_is_matched_case_insensitively(): void
    {
        $viewer = $this->verifiedViewer('Viewer@Example.com');

        $this->actingAs($viewer)
            ->getJson(route('admin.security-events.index'))
            ->assertOk();
    }

    public function test_a_viewer_with_an_unverified_email_is_redirected_to_verification(): void
    {
        $this->skipUnlessFortifyHas(Features::emailVerification());
        config(['audit.security_viewers' => ['viewer@example.com']]);

        $this->actingAs(User::factory()->unverified()->create(['email' => 'viewer@example.com']))
            ->get(route('admin.security-events.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_events_can_be_filtered_by_result(): void
    {
        $viewer = $this->verifiedViewer();
        $this->recordEvent('security', 'password.changed', 'success');
        $this->recordEvent('security', 'login.failed', 'failure');

        $this->actingAs($viewer)
            ->getJson(route('admin.security-events.index', ['result' => 'failure']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'login.failed');
    }

    public function test_events_can_be_filtered_by_event_name(): void
    {
        $viewer = $this->verifiedViewer();
        $this->recordEvent('security', 'password.changed', 'success');
        $this->recordEvent('security', 'login.failed', 'failure');

        $this->actingAs($viewer)
            ->getJson(route('admin.security-events.index', ['event' => 'password.changed']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.event', 'password.changed');
    }

    public function test_security_events_cannot_be_changed_over_http(): void
    {
        $viewer = $this->verifiedViewer();

        $this->actingAs($viewer)->putJson('/admin/security-events')->assertStatus(405);
        $this->actingAs($viewer)->deleteJson('/admin/security-events')->assertStatus(405);
        $this->actingAs($viewer)->postJson('/admin/security-events')->assertStatus(405);
    }

    public function test_the_gate_denies_a_listed_email_that_is_not_verified(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);

        $unverified = User::factory()->unverified()->create(['email' => 'viewer@example.com']);

        $this->assertTrue(Gate::forUser($unverified)->denies('view-security-events'));
    }

    public function test_the_gate_allows_a_listed_email_that_is_verified(): void
    {
        $viewer = $this->verifiedViewer();

        $this->assertTrue(Gate::forUser($viewer)->allows('view-security-events'));
    }

    public function test_a_listed_email_cannot_view_when_the_user_has_no_email_verification(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);

        $this->actingAs($this->userWithoutEmailVerification('viewer@example.com'))
            ->getJson(route('admin.security-events.index'))
            ->assertForbidden();
    }

    public function test_the_gate_denies_a_listed_email_when_the_user_has_no_email_verification(): void
    {
        config(['audit.security_viewers' => ['viewer@example.com']]);

        $user = $this->userWithoutEmailVerification('viewer@example.com');

        $this->assertTrue(Gate::forUser($user)->denies('view-security-events'));
    }

    private function verifiedViewer(string $listedAs = 'viewer@example.com'): User
    {
        $this->skipUnlessFortifyHas(Features::emailVerification());
        config(['audit.security_viewers' => [$listedAs]]);

        return User::factory()->create(['email' => 'viewer@example.com']);
    }

    /**
     * A user as it exists when Chisel removes email verification: the model
     * no longer implements MustVerifyEmail, so the verified middleware passes.
     */
    private function userWithoutEmailVerification(string $email): AuthUser
    {
        $user = new class extends AuthUser {};
        $user->forceFill(['id' => 1, 'email' => $email]);

        return $user;
    }

    private function recordEvent(string $logName, string $event, string $result): void
    {
        activity($logName)->event($event)->withProperties(['result' => $result])->log($event);
    }
}
