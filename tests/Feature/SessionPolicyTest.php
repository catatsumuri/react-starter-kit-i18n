<?php

namespace Tests\Feature;

use App\Events\UserEmailChanged;
use App\Models\User;
use App\Support\AuthenticatedSessionContext;
use App\Support\PurposeBoundAuthentication;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Tests\TestCase;

class SessionPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
    }

    public function test_authenticated_session_expires_after_the_absolute_lifetime(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                AuthenticatedSessionContext::StartedAt => now()->subDays(8)->timestamp,
                AuthenticatedSessionContext::Version => 0,
            ])
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('Your session has expired. Please sign in again.'));

        $this->assertGuest();
    }

    public function test_authenticated_session_remains_valid_before_the_absolute_lifetime(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                AuthenticatedSessionContext::StartedAt => now()->subDays(6)->timestamp,
                AuthenticatedSessionContext::Version => 0,
            ])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_absolute_lifetime_can_be_disabled(): void
    {
        config(['session.absolute_lifetime' => 0]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                AuthenticatedSessionContext::StartedAt => now()->subYear()->timestamp,
                AuthenticatedSessionContext::Version => 0,
            ])
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_security_version_mismatch_logs_out_the_current_device(): void
    {
        $user = User::factory()->create(['auth_session_version' => 2]);

        $this->actingAs($user)
            ->withSession([
                AuthenticatedSessionContext::StartedAt => now()->timestamp,
                AuthenticatedSessionContext::Version => 1,
            ])
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_remembered_login_without_its_original_context_must_sign_in_again(): void
    {
        $request = Request::create('/');
        $request->setLaravelSession(new Store('test', new ArraySessionHandler(120)));

        $context = app(AuthenticatedSessionContext::class)->resolve(
            $request,
            User::factory()->create(),
            true,
        );

        $this->assertNull($context);
    }

    public function test_email_change_preserves_the_current_session_and_revokes_other_database_sessions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'));
        $this->storeSession('other-session', $user);

        $this->withSession([
            'auth.password_confirmed_at' => now()->timestamp,
            PurposeBoundAuthentication::ConfirmedPurpose => PurposeBoundAuthentication::EmailChange,
        ])
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'changed@example.com',
            ])
            ->assertRedirect(route('profile.edit'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertSame(1, $user->fresh()->auth_session_version);
    }

    public function test_password_reset_revokes_every_database_session(): void
    {
        $user = User::factory()->create();
        $this->storeSession('existing-session', $user);

        Event::dispatch(new PasswordReset($user));

        $this->assertDatabaseMissing('sessions', ['id' => 'existing-session']);
        $this->assertSame(1, $user->fresh()->auth_session_version);
    }

    public function test_non_weakening_security_change_does_not_rotate_sessions(): void
    {
        $user = User::factory()->create();
        $this->storeSession('other-session', $user);

        RecoveryCodesGenerated::dispatch($user);

        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
        $this->assertSame(0, $user->fresh()->auth_session_version);
    }

    public function test_automatic_session_revocation_does_not_duplicate_owner_notifications(): void
    {
        config([
            'queue.default' => 'sync',
            'security.detection.enabled' => false,
            'security.notifications.mail' => false,
        ]);
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'));
        $this->storeSession('other-session', $user);

        UserEmailChanged::dispatch($user, 'previous@example.com');

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('account.email_changed', $user->notifications()->first()->data['security_event']);
    }

    private function storeSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Test browser',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
