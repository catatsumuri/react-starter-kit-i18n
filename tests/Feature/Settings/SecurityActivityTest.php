<?php

namespace Tests\Feature\Settings;

use App\Actions\Security\RecordSecurityActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Psr\Log\LoggerInterface;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SecurityActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_password_login_is_recorded_without_credentials(): void
    {
        $user = User::factory()->create();

        $this->withHeader('User-Agent', "Browser\nInjected")
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $activity = Activity::query()->where('event', 'authentication.login_succeeded')->sole();

        $this->assertSame($user->getKey(), (int) $activity->subject_id);
        $this->assertSame($user->getKey(), (int) $activity->causer_id);
        $this->assertSame('password', $activity->getProperty('auth_method'));
        $this->assertSame('BrowserInjected', $activity->getProperty('user_agent'));
        $this->assertFalse($activity->properties->has('password'));
        $this->assertStringNotContainsString('wrong-password', $activity->properties->toJson());
        $this->assertStringNotContainsString($user->email, $activity->properties->toJson());
    }

    public function test_failed_login_is_not_written_to_the_primary_database(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_failed_authentication_uses_the_dedicated_log_without_the_login_identifier(): void
    {
        $user = User::factory()->create();
        $logger = $this->mock(LoggerInterface::class);

        $logger->shouldReceive('warning')
            ->once()
            ->withArgs(function (string $event, array $context) use ($user): bool {
                return $event === 'authentication.login_failed'
                    && $context['target_user_id'] === $user->getKey()
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $user->email);
            });
        Log::shouldReceive('channel')->once()->with('security')->andReturn($logger);

        app(RecordSecurityActivity::class)->failure(
            'authentication.login_failed',
            $user,
            'password',
        );
    }

    public function test_two_factor_challenge_is_not_recorded_as_a_completed_login(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $this->assertDatabaseMissing('activity_log', [
            'event' => 'authentication.login_succeeded',
        ]);
    }

    public function test_password_change_is_recorded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseHas('activity_log', [
            'event' => 'account.password_changed',
            'subject_id' => $user->getKey(),
            'causer_id' => $user->getKey(),
        ]);
    }

    public function test_user_can_only_view_their_own_security_activity_with_masked_network_details(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        activity('security')
            ->event('account.password_changed')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties([
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0',
                'client_ip' => '203.0.113.42',
                'auth_method' => 'password',
            ])
            ->log('account.password_changed');
        app(RecordSecurityActivity::class)->success(
            'account.password_changed',
            $otherUser,
            $otherUser,
            'password',
        );
        app(RecordSecurityActivity::class)->success(
            'authentication.two_factor_enabled',
            $user,
            $user,
        );

        $this->actingAs($user)->get(route('security-activity.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security-activity')
                ->has('activities.data', 1)
                ->where('activities.data.0.event', 'account.password_changed')
                ->where('activities.data.0.device', 'Firefox')
                ->where('activities.data.0.platform', 'Windows')
                ->where('activities.data.0.ip_address', '203.0.113.x'),
            );
    }

    public function test_security_activity_hides_events_that_are_not_explicitly_user_visible(): void
    {
        $user = User::factory()->create();

        app(RecordSecurityActivity::class)->success(
            'account.email_verified',
            $user,
            $user,
        );

        $this->actingAs($user)->get(route('security-activity.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/security-activity')
                ->has('activities.data', 0),
            );
    }

    public function test_security_activity_requires_authentication_and_verification(): void
    {
        $this->get(route('security-activity.index'))->assertRedirect(route('login'));

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route('security-activity.index'))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_security_activity_cleanup_removes_only_expired_records(): void
    {
        $user = User::factory()->create();

        foreach ([now()->subDays(181), now()->subDays(179)] as $occurredAt) {
            activity('security')
                ->event('account.password_changed')
                ->performedOn($user)
                ->createdAt($occurredAt)
                ->log('account.password_changed');
        }

        $this->artisan('activitylog:clean', [
            'log' => 'security',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(1, Activity::query()->where('log_name', 'security')->count());
    }
}
