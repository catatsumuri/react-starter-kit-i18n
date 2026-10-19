<?php

namespace Tests\Feature\Console;

use App\Actions\Sessions\ManageDatabaseSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RevokeUserSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSelectors(): array
    {
        return [
            'bare number' => ['1'],
            'bare email' => ['user@example.com'],
            'unknown type' => ['name:alice'],
            'empty value' => ['id:'],
            'blank value' => ['email:   '],
            'zero id' => ['id:0'],
            'negative id' => ['id:-1'],
            'non numeric id' => ['id:1x'],
        ];
    }

    #[DataProvider('invalidSelectors')]
    public function test_a_selector_must_state_its_type_and_a_valid_value(string $selector): void
    {
        $user = User::factory()->create();
        $this->storeSession('session-one', $user);

        $this->artisan('security:revoke-sessions', ['selector' => $selector, '--force' => true])
            ->expectsOutputToContain('Use id:123 or email:user@example.com.')
            ->assertFailed();

        $this->assertDatabaseHas('sessions', ['id' => 'session-one']);
    }

    public function test_an_unknown_user_fails_without_touching_other_sessions(): void
    {
        $user = User::factory()->create();
        $this->storeSession('session-one', $user);

        $this->artisan('security:revoke-sessions', ['selector' => 'id:999999', '--force' => true])
            ->expectsOutputToContain('The specified user was not found.')
            ->assertFailed();

        $this->artisan('security:revoke-sessions', ['selector' => 'email:nobody@example.com', '--force' => true])
            ->expectsOutputToContain('The specified user was not found.')
            ->assertFailed();

        $this->assertDatabaseHas('sessions', ['id' => 'session-one']);
    }

    public function test_declining_the_confirmation_preserves_every_session(): void
    {
        $user = User::factory()->create();
        $this->storeSession('session-one', $user);
        $this->storeSession('session-two', $user);

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey()])
            ->expectsConfirmation($this->confirmationQuestion($user, 2), 'no')
            ->expectsOutputToContain('No sessions were revoked.')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    public function test_confirming_revokes_every_session_of_the_user(): void
    {
        $user = User::factory()->create();
        $this->storeSession('session-one', $user);
        $this->storeSession('session-two', $user);

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey()])
            ->expectsConfirmation($this->confirmationQuestion($user, 2), 'yes')
            ->expectsOutputToContain('Revoked 2 session(s) for user ID '.$user->getKey().'.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_force_revokes_only_the_target_users_sessions_including_expired_rows(): void
    {
        $user = User::factory()->create(['email' => 'target@example.com']);
        $otherUser = User::factory()->create();
        $this->storeSession('active-session', $user);
        $this->storeSession('expired-session', $user, now()->subDay()->timestamp);
        $this->storeSession('other-users-session', $otherUser);
        $this->storeSession('guest-session', null);

        $this->artisan('security:revoke-sessions', ['selector' => 'email:  Target@Example.com ', '--force' => true])
            ->expectsOutputToContain('Revoked 2 session(s) for user ID '.$user->getKey().'.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('sessions', ['id' => 'active-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'expired-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-users-session', 'user_id' => $otherUser->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'guest-session']);
    }

    public function test_a_user_without_stored_sessions_is_reported_without_prompting(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->storeSession('other-users-session', $otherUser);

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey()])
            ->expectsOutputToContain('No sessions are stored for')
            ->assertSuccessful();

        $this->assertDatabaseHas('sessions', ['id' => 'other-users-session']);
    }

    public function test_revocation_signs_out_a_logged_in_browser(): void
    {
        $user = User::factory()->create();
        $bystander = User::factory()->create();
        $cookie = $this->signIn($user);
        $bystanderCookie = $this->signIn($bystander);

        $this->asClient($cookie)->get(route('dashboard'))->assertOk();

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey(), '--force' => true])
            ->assertSuccessful();

        $this->asClient($cookie)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asClient($bystanderCookie)->get(route('dashboard'))->assertOk();
    }

    public function test_it_fails_safely_for_a_non_database_session_driver(): void
    {
        $user = User::factory()->create();
        $this->storeSession('session-one', $user);

        config(['session.driver' => 'array']);

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey(), '--force' => true])
            ->expectsOutputToContain('Session revocation requires the database session driver.')
            ->assertFailed();

        $this->assertDatabaseHas('sessions', ['id' => 'session-one']);
    }

    public function test_it_fails_without_claiming_success_when_counting_sessions_fails(): void
    {
        $user = User::factory()->create();

        $this->mock(ManageDatabaseSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('storedSessionCount')->andThrow(new RuntimeException('Storage unavailable'));
            $mock->shouldNotReceive('revokeAllSessions');
        });

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey(), '--force' => true])
            ->expectsOutputToContain('Session storage is unavailable. No sessions were revoked.')
            ->assertFailed();
    }

    public function test_it_fails_without_claiming_success_when_deleting_sessions_fails(): void
    {
        $user = User::factory()->create();

        $this->mock(ManageDatabaseSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('storedSessionCount')->andReturn(2);
            $mock->shouldReceive('revokeAllSessions')->andThrow(new RuntimeException('Storage unavailable'));
        });

        $this->artisan('security:revoke-sessions', ['selector' => 'id:'.$user->getKey(), '--force' => true])
            ->expectsOutputToContain('Session storage is unavailable. No sessions were revoked.')
            ->doesntExpectOutputToContain('Revoked')
            ->assertFailed();
    }

    private function confirmationQuestion(User $user, int $count): string
    {
        return "Revoke {$count} session(s) for {$user->name} ({$user->email}, ID {$user->getKey()})?";
    }

    private function signIn(User $user): string
    {
        $this->unencryptedCookies = [];
        $this->startNewClient();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();

        return $response->getCookie(config('session.cookie'), false)->getValue();
    }

    private function asClient(string $cookie): static
    {
        $this->startNewClient();

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie);
    }

    /**
     * Drop every per-client instance so the next request is served as a new
     * browser, the way a separate PHP process would serve it.
     */
    private function startNewClient(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth.driver');
    }

    private function storeSession(string $id, ?User $user, ?int $lastActivity = null): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user?->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Mozilla/5.0 (Linux) Chrome/130.0 Safari/537.36',
            'payload' => '',
            'last_activity' => $lastActivity ?? now()->timestamp,
        ]);
    }
}
