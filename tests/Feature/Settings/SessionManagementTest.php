<?php

namespace Tests\Feature\Settings;

use App\Actions\Sessions\ManageDatabaseSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $current = $this->signIn($user);
        $this->storeSession('other-session', $user, '203.0.113.42', 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0');
        $this->storeSession('expired-session', $user, '203.0.113.50', 'Expired', now()->subHours(3)->timestamp);
        $this->storeSession('foreign-session', $otherUser, '203.0.113.60', 'Foreign');

        $this->asClient($current['cookie'])
            ->get(route('sessions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/sessions')
                ->where('available', true)
                ->where('currentSession.is_current', true)
                ->where('currentSession.fingerprint', app(ManageDatabaseSessions::class)->fingerprint($user, $current['id']))
                ->has('otherSessions.data', 1)
                ->where('otherSessions.data.0.device', 'Firefox')
                ->where('otherSessions.data.0.ip_address', '203.0.113.x')
                ->where('otherSessions.data.0.is_current', false),
            );
    }

    public function test_guests_cannot_access_session_management(): void
    {
        $this->get(route('sessions.index'))->assertRedirect(route('login'));
        $this->delete(route('sessions.destroy-others'))->assertRedirect(route('login'));
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

    public function test_user_can_revoke_another_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->storeSession('other-session', $user);

        $fingerprint = app(ManageDatabaseSessions::class)->fingerprint($user, 'other-session');

        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete(route('sessions.destroy', $fingerprint))
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
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

    public function test_user_can_revoke_all_other_sessions(): void
    {
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

    public function test_deleting_a_database_row_invalidates_every_copy_of_the_session_id(): void
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
        $user = User::factory()->create();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->actingAs($user)
            ->get(route('dashboard'));
        $this->storeSession('other-session', $user);

        $this->delete(route('sessions.destroy-all'))
            ->assertRedirect(route('home'));

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertGuest();
    }

    /**
     * Passkey re-authentication is not exercised here: the passkey confirmation
     * endpoint sets the same `auth.password_confirmed_at` session value that these
     * tests set. Covering it needs a test key pair or a virtual WebAuthn
     * authenticator to sign a valid assertion, so it is tracked as a follow-up
     * rather than covered by this branch.
     *
     * @return array<string, array{string, string}>
     */
    public static function revocationEndpoints(): array
    {
        return [
            'individual' => ['sessions.destroy', 'invalid-fingerprint'],
            'other sessions' => ['sessions.destroy-others', ''],
            'all sessions' => ['sessions.destroy-all', ''],
        ];
    }

    #[DataProvider('revocationEndpoints')]
    public function test_revocation_is_unavailable_for_non_database_session_drivers(string $route, string $parameter): void
    {
        config(['session.driver' => 'array']);
        $this->app['session']->forgetDrivers();

        $this->actingAs(User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete($this->revocationUrl($route, $parameter))
            ->assertNotFound();

        $this->assertAuthenticated();
    }

    #[DataProvider('revocationEndpoints')]
    public function test_revocation_requires_password_confirmation_within_fifteen_minutes(string $route, string $parameter): void
    {
        $user = User::factory()->create();
        $this->storeSession('other-session', $user);

        // Stricter than the three hour `auth.password_timeout` default.
        foreach ([16, 60, 179] as $minutes) {
            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->subMinutes($minutes)->timestamp])
                ->delete($this->revocationUrl($route, $parameter))
                ->assertRedirect(route('password.confirm'));
        }

        $this->assertAuthenticated();
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    #[DataProvider('revocationEndpoints')]
    public function test_revocation_without_any_confirmation_is_redirected_to_confirm_password(string $route, string $parameter): void
    {
        $user = User::factory()->create();
        $this->storeSession('other-session', $user);

        $this->actingAs($user)
            ->delete($this->revocationUrl($route, $parameter))
            ->assertRedirect(route('password.confirm'));

        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }

    public function test_revocation_is_allowed_within_the_confirmation_window(): void
    {
        $user = User::factory()->create();
        $this->storeSession('other-session', $user);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->subMinutes(14)->timestamp])
            ->delete(route('sessions.destroy-others'))
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
    }

    #[DataProvider('revocationEndpoints')]
    public function test_revocation_endpoints_are_rate_limited(string $route, string $parameter): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $response = $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->delete($this->revocationUrl($route, $parameter));

            $this->assertNotSame(429, $response->getStatusCode(), 'Attempt '.($attempt + 1).' was throttled.');
        }

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->delete($this->revocationUrl($route, $parameter))
            ->assertTooManyRequests();
    }

    public function test_unknown_and_foreign_fingerprints_get_the_same_response(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->storeSession('foreign-session', $otherUser);
        $foreign = app(ManageDatabaseSessions::class)->fingerprint($otherUser, 'foreign-session');
        $forged = app(ManageDatabaseSessions::class)->fingerprint($user, 'foreign-session');

        $responses = [];

        foreach ([$foreign, $forged, 'unknown', str_repeat('a', 5000), 'é%00'] as $fingerprint) {
            $responses[] = $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->delete(route('sessions.destroy', $fingerprint));
        }

        foreach ($responses as $response) {
            $response->assertNotFound();
            $this->assertSame($responses[0]->getContent(), $response->getContent());
        }

        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session', 'user_id' => $otherUser->id]);
    }

    public function test_session_ids_are_not_exposed_to_the_frontend(): void
    {
        $user = User::factory()->create();
        $this->storeSession('secret-other-session-id', $user);

        $content = $this->actingAs($user)
            ->get(route('sessions.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('secret-other-session-id', $content);
    }

    public function test_storage_failure_while_revoking_reports_an_error_and_does_not_sign_the_user_out(): void
    {
        $this->mock(ManageDatabaseSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isAvailable')->andReturnTrue();
            $mock->shouldReceive('revokeOtherSessions')->andThrow(new RuntimeException('Storage unavailable'));
            $mock->shouldReceive('revokeAllSessions')->andThrow(new RuntimeException('Storage unavailable'));
            $mock->shouldReceive('revoke')->andThrow(new RuntimeException('Storage unavailable'));
        });

        $this->withoutExceptionHandling([RuntimeException::class]);
        $user = User::factory()->create();

        foreach ([
            route('sessions.destroy-others'),
            route('sessions.destroy-all'),
            route('sessions.destroy', 'any-fingerprint'),
        ] as $url) {
            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->delete($url)
                ->assertRedirect()
                ->assertSessionHas('inertia.flash_data.toast.type', 'error')
                ->assertSessionHas(
                    'inertia.flash_data.toast.message',
                    __('Sessions are temporarily unavailable. Please try again.'),
                );

            $this->assertAuthenticated();
        }
    }

    public function test_current_session_cannot_be_revoked_through_the_individual_endpoint(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $this->confirmPassword($current['cookie']);

        $fingerprint = app(ManageDatabaseSessions::class)->fingerprint($user, $current['id']);

        $this->asClient($current['cookie'])
            ->delete(route('sessions.destroy', $fingerprint))
            ->assertNotFound();

        $this->assertDatabaseHas('sessions', ['id' => $current['id']]);
        $this->asClient($current['cookie'])->get(route('dashboard'))->assertOk();
    }

    public function test_revoking_another_session_signs_out_the_original_and_copied_cookie(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $attacker = $this->signIn($user);
        $copiedCookie = $attacker['cookie'];

        $this->asClient($attacker['cookie'])->get(route('dashboard'))->assertOk();
        $this->asClient($copiedCookie)->get(route('dashboard'))->assertOk();

        $this->confirmPassword($current['cookie']);
        $this->asClient($current['cookie'])
            ->delete(route('sessions.destroy', app(ManageDatabaseSessions::class)->fingerprint($user, $attacker['id'])))
            ->assertRedirect();

        $this->asClient($attacker['cookie'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asClient($copiedCookie)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asClient($current['cookie'])->get(route('dashboard'))->assertOk();
    }

    public function test_revoking_other_sessions_signs_out_every_other_client_but_keeps_the_caller(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $others = [$this->signIn($user), $this->signIn($user)];
        $bystander = $this->signIn(User::factory()->create());

        $this->confirmPassword($current['cookie']);
        $this->asClient($current['cookie'])
            ->delete(route('sessions.destroy-others'))
            ->assertRedirect();

        foreach ($others as $other) {
            $this->asClient($other['cookie'])->get(route('dashboard'))->assertRedirect(route('login'));
        }

        $this->asClient($current['cookie'])->get(route('dashboard'))->assertOk();
        $this->asClient($bystander['cookie'])->get(route('dashboard'))->assertOk();
    }

    public function test_revoking_all_sessions_signs_out_every_client_including_the_caller(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $other = $this->signIn($user);
        $bystander = $this->signIn(User::factory()->create());

        $this->confirmPassword($current['cookie']);
        $this->asClient($current['cookie'])
            ->delete(route('sessions.destroy-all'))
            ->assertRedirect(route('home'));

        $this->asClient($current['cookie'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asClient($other['cookie'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asClient($bystander['cookie'])->get(route('dashboard'))->assertOk();
    }

    public function test_a_revoked_client_cannot_revoke_anything(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $attacker = $this->signIn($user);

        $this->confirmPassword($attacker['cookie']);
        $this->confirmPassword($current['cookie']);
        $this->asClient($current['cookie'])->delete(route('sessions.destroy-others'))->assertRedirect();

        $this->asClient($attacker['cookie'])
            ->delete(route('sessions.destroy-all'))
            ->assertRedirect(route('login'));

        $this->asClient($current['cookie'])->get(route('dashboard'))->assertOk();
    }

    public function test_revoking_other_sessions_also_invalidates_other_remember_me_cookies(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $attacker = $this->signInRemembered($user);
        $originalToken = $user->fresh()->remember_token;

        $this->asClient(null, $attacker['remember'])->get(route('dashboard'))->assertOk();

        $this->confirmPassword($current['cookie']);
        $this->asClient($current['cookie'])->delete(route('sessions.destroy-others'))->assertRedirect();

        $this->asClient($attacker['cookie'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asClient(null, $attacker['remember'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertNotSame($originalToken, $user->fresh()->remember_token);
        $this->asClient($current['cookie'])->get(route('dashboard'))->assertOk();
    }

    public function test_revoking_other_sessions_invalidates_remember_me_cookies_even_when_no_other_session_is_stored(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $attacker = $this->signInRemembered($user);
        DB::table('sessions')->where('id', $attacker['id'])->delete();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $current['id'])->count());

        $this->confirmPassword($current['cookie']);
        $this->asClient($current['cookie'])->delete(route('sessions.destroy-others'))->assertRedirect();

        $this->asClient(null, $attacker['remember'])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->asClient($current['cookie'])->get(route('dashboard'))->assertOk();
    }

    public function test_revoking_all_sessions_also_invalidates_remember_me_cookies(): void
    {
        $user = User::factory()->create();
        $current = $this->signInRemembered($user);
        $other = $this->signInRemembered($user);
        $bystander = $this->signInRemembered(User::factory()->create());

        $this->confirmPassword($current['cookie']);
        $this->asClient($current['cookie'], $current['remember'])
            ->delete(route('sessions.destroy-all'))
            ->assertRedirect(route('home'));

        foreach ([$current, $other] as $client) {
            $this->asClient(null, $client['remember'])->get(route('dashboard'))->assertRedirect(route('login'));
        }

        $this->asClient(null, $bystander['remember'])->get(route('dashboard'))->assertOk();
    }

    public function test_failing_to_reset_the_remember_token_does_not_report_success_or_sign_the_user_out(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $this->storeSession('other-session', $user);
        $this->confirmPassword($current['cookie']);

        $this->partialMock(ManageDatabaseSessions::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resetRememberToken')->andThrow(new RuntimeException('Users unavailable'));
        });

        foreach (['sessions.destroy-others', 'sessions.destroy-all'] as $route) {
            $this->asClient($current['cookie'])
                ->delete(route($route))
                ->assertRedirect()
                ->assertSessionHas('inertia.flash_data.toast.type', 'error');
        }

        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
        $this->asClient($current['cookie'])->get(route('dashboard'))->assertOk();
    }

    /**
     * @return array{cookie: string, id: string}
     */
    private function signIn(User $user): array
    {
        $this->unencryptedCookies = [];
        $this->startNewClient();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();

        return [
            'cookie' => $response->getCookie(config('session.cookie'), false)->getValue(),
            'id' => $response->getCookie(config('session.cookie'))->getValue(),
        ];
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

    /**
     * @param  array{name: string, value: string}|null  $remember
     */
    private function asClient(?string $cookie, ?array $remember = null): static
    {
        $this->startNewClient();
        $this->unencryptedCookies = [];

        if ($cookie !== null) {
            $this->withUnencryptedCookie(config('session.cookie'), $cookie);
        }

        if ($remember !== null) {
            $this->withUnencryptedCookie($remember['name'], $remember['value']);
        }

        return $this;
    }

    /**
     * @return array{cookie: string, id: string, remember: array{name: string, value: string}}
     */
    private function signInRemembered(User $user): array
    {
        $this->unencryptedCookies = [];
        $this->startNewClient();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertRedirect();

        $name = Auth::guard('web')->getRecallerName();
        $remember = $response->getCookie($name, false);
        $this->assertNotNull($remember, 'No remember me cookie was issued.');

        return [
            'cookie' => $response->getCookie(config('session.cookie'), false)->getValue(),
            'id' => $response->getCookie(config('session.cookie'))->getValue(),
            'remember' => ['name' => $name, 'value' => $remember->getValue()],
        ];
    }

    private function confirmPassword(string $cookie): void
    {
        $this->asClient($cookie)
            ->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertRedirect();
    }

    private function revocationUrl(string $route, string $parameter): string
    {
        return $parameter === '' ? route($route) : route($route, $parameter);
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
