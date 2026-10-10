<?php

namespace Tests\Feature\Settings;

use App\Actions\Sessions\ManageDatabaseSessions;
use App\Events\AuthenticatedSessionRevoked;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
    }

    public function test_user_can_view_their_current_and_other_active_sessions(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh) Chrome/130.0 Safari/537.36')
            ->get(route('dashboard'));

        $this->storeSession('other-session', $user, '203.0.113.42', 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0');
        $this->storeSession('expired-session', $user, '203.0.113.50', 'Expired', now()->subHours(3)->timestamp);
        $this->storeSession('foreign-session', $otherUser, '203.0.113.60', 'Foreign');

        $response = $this->get(route('sessions.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('settings/sessions')
            ->where('available', true)
            ->has('otherSessions.data'),
        );
    }

    public function test_session_presenter_marks_the_current_session_and_masks_its_details(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->storeSession(
            'current-session',
            $user,
            '2001:db8:abcd:12:1234:5678:90ab:cdef',
            'Mozilla/5.0 (Macintosh) Chrome/130.0 Safari/537.36',
        );
        $this->storeSession(
            'other-session',
            $user,
            '203.0.113.42',
            'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0',
        );
        $this->storeSession(
            'expired-session',
            $user,
            lastActivity: now()->subHours(3)->timestamp,
        );
        $this->storeSession('foreign-session', $otherUser);

        $sessions = app(ManageDatabaseSessions::class)->sessions($user, 'current-session');

        $this->assertIsArray($sessions['currentSession']);
        $this->assertSame([
            'fingerprint' => app(ManageDatabaseSessions::class)->fingerprint($user, 'current-session'),
            'device' => 'Chrome',
            'platform' => 'macOS',
            'ip_address' => '2001:db8:abcd:12::/64',
            'last_activity' => $sessions['currentSession']['last_activity'],
            'is_current' => true,
        ], $sessions['currentSession']);
        $this->assertSame(1, $sessions['otherSessions']->total());
        $this->assertSame('Firefox', $sessions['otherSessions']->items()[0]['device']);
        $this->assertSame('Windows', $sessions['otherSessions']->items()[0]['platform']);
        $this->assertSame('203.0.113.x', $sessions['otherSessions']->items()[0]['ip_address']);
    }

    public function test_session_page_is_safe_when_database_sessions_are_not_available(): void
    {
        config(['session.driver' => 'array']);
        $this->app['session']->forgetDrivers();

        $this->actingAs(User::factory()->create())
            ->get(route('sessions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/sessions')
                ->where('available', false)
                ->where('currentSession', null)
                ->where('otherSessions', null),
            );
    }

    public function test_session_page_is_safe_when_session_storage_is_inaccessible(): void
    {
        $this->mock(ManageDatabaseSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->once()->andReturnTrue();
            $mock->shouldReceive('sessions')->once()->andThrow(new RuntimeException('Storage unavailable'));
        });

        $this->actingAs(User::factory()->create())
            ->get(route('sessions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/sessions')
                ->where('available', false)
                ->where('currentSession', null)
                ->where('otherSessions', null),
            );
    }

    public function test_other_sessions_are_paginated(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 21) as $index) {
            $this->storeSession('other-session-'.$index, $user);
        }

        $sessions = app(ManageDatabaseSessions::class)->sessions($user, 'current-session');

        $this->assertCount(20, $sessions['otherSessions']->items());
        $this->assertSame(21, $sessions['otherSessions']->total());
        $this->assertSame(2, $sessions['otherSessions']->lastPage());
    }

    public function test_revocation_is_unavailable_for_non_database_session_drivers(): void
    {
        config(['session.driver' => 'array']);
        $this->app['session']->forgetDrivers();

        $this->actingAs(User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete(route('sessions.destroy-others'))
            ->assertNotFound();
    }

    public function test_user_can_revoke_another_session(): void
    {
        Event::fake([AuthenticatedSessionRevoked::class]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->storeSession('other-session', $user);

        $fingerprint = app(ManageDatabaseSessions::class)->fingerprint($user, 'other-session');

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete(route('sessions.destroy', $fingerprint))
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        Event::assertDispatched(
            AuthenticatedSessionRevoked::class,
            fn (AuthenticatedSessionRevoked $event): bool => $event->user->is($user)
                && $event->scope === AuthenticatedSessionRevoked::Individual
                && $event->revokedCount === 1
                && $event->sessionFingerprint === $fingerprint,
        );
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user);
        $this->storeSession('foreign-session', $otherUser);

        $fingerprint = app(ManageDatabaseSessions::class)->fingerprint($otherUser, 'foreign-session');

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete(route('sessions.destroy', $fingerprint))
            ->assertNotFound();

        $this->assertDatabaseHas('sessions', [
            'id' => 'foreign-session',
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_user_cannot_revoke_the_current_session_through_the_individual_endpoint(): void
    {
        $user = User::factory()->create();
        $this->storeSession('current-session', $user);
        $sessions = app(ManageDatabaseSessions::class);

        $revoked = $sessions->revoke(
            $user,
            'current-session',
            $sessions->fingerprint($user, 'current-session'),
        );

        $this->assertFalse($revoked);
        $this->assertDatabaseHas('sessions', ['id' => 'current-session']);
    }

    public function test_user_can_revoke_all_other_sessions(): void
    {
        Event::fake([AuthenticatedSessionRevoked::class]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $this->storeSession('other-session-one', $user);
        $this->storeSession('other-session-two', $user);

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete(route('sessions.destroy-others'))
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-two']);
        $this->assertAuthenticatedAs($user);

        Event::assertDispatched(
            AuthenticatedSessionRevoked::class,
            fn (AuthenticatedSessionRevoked $event): bool => $event->scope === AuthenticatedSessionRevoked::OtherSessions
                && $event->revokedCount === 2,
        );
    }

    public function test_revoking_other_sessions_preserves_the_supplied_current_session(): void
    {
        $user = User::factory()->create();
        $this->storeSession('current-session', $user);
        $this->storeSession('other-session', $user);

        $revokedCount = app(ManageDatabaseSessions::class)
            ->revokeOtherSessions($user, 'current-session');

        $this->assertSame(1, $revokedCount);
        $this->assertDatabaseHas('sessions', ['id' => 'current-session']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    public function test_deleting_a_database_session_invalidates_the_original_and_copied_cookie(): void
    {
        $user = User::factory()->create();
        $sessionId = str_repeat('a', 40);
        $handler = new DatabaseSessionHandler(
            DB::connection(),
            'sessions',
            (int) config('session.lifetime'),
            $this->app,
        );
        $session = new Store(
            (string) config('session.cookie'),
            $handler,
            $sessionId,
            'json',
        );
        $authKey = Auth::guard('web')->getName();
        $session->put($authKey, $user->id);
        $session->save();

        $copiedSession = new Store(
            (string) config('session.cookie'),
            new DatabaseSessionHandler(
                DB::connection(),
                'sessions',
                (int) config('session.lifetime'),
                $this->app,
            ),
            $sessionId,
            'json',
        );
        $copiedSession->start();

        $this->assertSame($user->id, $copiedSession->get($authKey));

        DB::table('sessions')->where('id', $sessionId)->delete();

        foreach (range(1, 2) as $copy) {
            $revokedSession = new Store(
                (string) config('session.cookie').$copy,
                new DatabaseSessionHandler(
                    DB::connection(),
                    'sessions',
                    (int) config('session.lifetime'),
                    $this->app,
                ),
                $sessionId,
                'json',
            );
            $revokedSession->start();

            $this->assertFalse($revokedSession->has($authKey));
        }
    }

    public function test_user_can_revoke_all_sessions_including_the_current_session(): void
    {
        Event::fake([AuthenticatedSessionRevoked::class]);

        $user = User::factory()->create();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->actingAs($user)
            ->get(route('dashboard'));
        $this->storeSession('other-session', $user);

        $this->delete(route('sessions.destroy-all'))
            ->assertRedirect(route('home'));

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertGuest();

        Event::assertDispatched(
            AuthenticatedSessionRevoked::class,
            fn (AuthenticatedSessionRevoked $event): bool => $event->scope === AuthenticatedSessionRevoked::AllSessions
                && $event->revokedCount === 2,
        );
    }

    public function test_revocation_requires_recent_password_or_passkey_confirmation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->storeSession('other-session', $user);

        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp])
            ->delete(route('sessions.destroy-others'))
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    public function test_revocation_endpoints_are_rate_limited(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->delete(route('sessions.destroy', 'invalid-fingerprint'))
                ->assertNotFound();
        }

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete(route('sessions.destroy', 'invalid-fingerprint'))
            ->assertTooManyRequests();
    }

    private function storeSession(
        string $id,
        User $user,
        string $ipAddress = '2001:db8:abcd:12:1234:5678:90ab:cdef',
        string $userAgent = 'Mozilla/5.0 (Linux) Chrome/130.0 Safari/537.36',
        ?int $lastActivity = null,
    ): void {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => '',
            'last_activity' => $lastActivity ?? now()->timestamp,
        ]);
    }
}
