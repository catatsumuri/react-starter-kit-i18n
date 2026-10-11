<?php

namespace Tests\Feature;

use App\Actions\Security\RecordSecurityActivity;
use App\Actions\Sessions\ManageDatabaseSessions;
use App\Events\AuthenticatedSessionRevoked;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SecurityResponseCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'sync',
            'session.driver' => 'database',
            'security.detection.enabled' => false,
            'security.notifications.enabled' => true,
            'security.notifications.mail' => false,
            'security.notifications.events.session.revoked.database' => true,
            'security.notifications.events.session.revoked.mail' => false,
        ]);
        $this->app['session']->forgetDrivers();
    }

    public function test_inspection_requires_an_explicit_selector_type(): void
    {
        User::factory()->create(['email' => 'operator-target@example.com']);

        $this->artisan('security:inspect-user', ['selector' => 'operator-target@example.com'])
            ->expectsOutputToContain('Use id:123, email:user@example.com, or username:alice.')
            ->assertFailed();
    }

    public function test_inspection_normalizes_identifiers_and_displays_only_the_selected_users_masked_activity(): void
    {
        $user = User::factory()->create([
            'username' => 'target-user',
            'email' => 'target@example.com',
        ]);
        $otherUser = User::factory()->create();
        $this->storeSession('secret-session-id', $user);
        $this->storeSession('other-user-session', $otherUser);

        activity('security')
            ->event('authentication.login_succeeded')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'result' => 'success',
                'request_id' => 'target-request-id',
                'client_ip' => '203.0.113.42',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0',
                'auth_method' => 'password',
            ])
            ->log('authentication.login_succeeded');
        app(RecordSecurityActivity::class)->success(
            'account.password_changed',
            $otherUser,
            $otherUser,
        );

        $exitCode = Artisan::call('security:inspect-user', [
            'selector' => 'email: TARGET@EXAMPLE.COM ',
            '--limit' => '20',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('target-user', $output);
        $this->assertStringContainsString('Active database sessions: 1', $output);
        $this->assertStringContainsString('authentication.login_succeeded', $output);
        $this->assertStringContainsString('203.0.113.x', $output);
        $this->assertStringContainsString('Firefox on Windows', $output);
        $this->assertStringContainsString('target-request-id', $output);
        $this->assertStringNotContainsString('203.0.113.42', $output);
        $this->assertStringNotContainsString('secret-session-id', $output);
        $this->assertStringNotContainsString('account.password_changed', $output);
    }

    public function test_inspection_rejects_an_out_of_range_activity_limit(): void
    {
        $user = User::factory()->create();

        $this->artisan('security:inspect-user', [
            'selector' => 'id:'.$user->getKey(),
            '--limit' => '101',
        ])
            ->expectsOutputToContain('The --limit option must be an integer between 1 and 100.')
            ->assertFailed();
    }

    public function test_confirmation_rejection_preserves_sessions_without_audit_or_notification(): void
    {
        Event::fake([AuthenticatedSessionRevoked::class]);
        $user = User::factory()->create();
        $this->storeSession('target-session', $user);
        $question = "Revoke 1 session(s) for {$user->name} ({$user->email}, ID {$user->getKey()})?";

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey()])
            ->expectsConfirmation($question, 'no')
            ->expectsOutputToContain('No sessions were revoked.')
            ->assertSuccessful();

        $this->assertDatabaseHas('sessions', ['id' => 'target-session']);
        Event::assertNotDispatched(AuthenticatedSessionRevoked::class);
        $this->assertDatabaseCount('activity_log', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_force_revocation_deletes_only_the_target_sessions_and_records_an_operator_action(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->storeSession('target-session-one', $user);
        $this->storeSession('target-session-two', $user);
        $this->storeSession('other-user-session', $otherUser);

        $this->artisan('security:revoke-sessions', [
            'selector' => 'username:'.strtoupper($user->username),
            '--force' => true,
        ])
            ->expectsOutputToContain("Revoked 2 session(s) for user ID {$user->getKey()}.")
            ->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session']);

        $activity = Activity::query()->where('event', 'session.revoked')->sole();
        $metadata = $activity->properties->get('metadata');

        $this->assertNull($activity->causer_id);
        $this->assertSame($user->getKey(), (int) $activity->subject_id);
        $this->assertIsArray($metadata);
        $this->assertSame('all_sessions', $metadata['scope']);
        $this->assertSame(2, $metadata['revoked_count']);
        $this->assertSame('operator_cli', $metadata['origin']);
        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
        ]);
    }

    public function test_no_stored_sessions_does_not_create_audit_or_notification(): void
    {
        $user = User::factory()->create();

        $this->artisan('security:revoke-sessions', [
            'selector' => 'id:'.$user->getKey(),
            '--force' => true,
        ])
            ->expectsOutputToContain('No sessions are stored')
            ->assertSuccessful();

        $this->assertDatabaseCount('activity_log', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_revocation_fails_safely_for_a_non_database_session_driver(): void
    {
        config(['session.driver' => 'array']);
        $this->app['session']->forgetDrivers();
        $user = User::factory()->create();

        $this->artisan('security:revoke-sessions', [
            'selector' => 'id:'.$user->getKey(),
            '--force' => true,
        ])
            ->expectsOutputToContain('Session revocation requires the database session driver.')
            ->assertFailed();

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_revocation_fails_safely_when_session_storage_is_unavailable(): void
    {
        $user = User::factory()->create();
        $this->mock(ManageDatabaseSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->once()->andReturnTrue();
            $mock->shouldReceive('storedSessionCount')->once()->andThrow(new RuntimeException('Unavailable'));
        });

        $this->artisan('security:revoke-sessions', [
            'selector' => 'id:'.$user->getKey(),
            '--force' => true,
        ])
            ->expectsOutputToContain('Session storage is unavailable. No sessions were revoked.')
            ->assertFailed();

        $this->assertDatabaseCount('activity_log', 0);
    }

    private function storeSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
