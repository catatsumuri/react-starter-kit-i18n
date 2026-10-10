<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_login_dispatches_login_after_authentication(): void
    {
        Event::fake([Login::class]);
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        Event::assertDispatched(Login::class, fn (Login $event): bool => $event->user->is($user));
    }

    public function test_invalid_password_dispatches_failed_without_login(): void
    {
        Event::fake([Failed::class, Login::class]);
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        Event::assertDispatched(Failed::class);
        Event::assertNotDispatched(Login::class);
    }

    public function test_throttled_password_login_does_not_dispatch_authentication_events(): void
    {
        Event::fake([Failed::class, Lockout::class, Login::class]);
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertTooManyRequests();

        Event::assertNotDispatched(Lockout::class);
        Event::assertNotDispatched(Failed::class);
        Event::assertNotDispatched(Login::class);
    }

    public function test_two_factor_challenge_is_not_a_completed_login(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        Event::fake([Login::class, TwoFactorAuthenticationChallenged::class]);
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        Event::assertDispatched(TwoFactorAuthenticationChallenged::class, fn (TwoFactorAuthenticationChallenged $event): bool => $event->user->is($user));
        Event::assertNotDispatched(Login::class);
        $this->assertGuest();
    }

    public function test_valid_recovery_code_completes_two_factor_login(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        Event::fake([Login::class, ValidTwoFactorAuthenticationCodeProvided::class]);

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'recovery-code-1',
        ])->assertRedirect(route('dashboard', absolute: false));

        Event::assertDispatched(ValidTwoFactorAuthenticationCodeProvided::class, fn (ValidTwoFactorAuthenticationCodeProvided $event): bool => $event->user->is($user));
        Event::assertDispatched(Login::class, fn (Login $event): bool => $event->user->is($user));
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_two_factor_code_dispatches_failure_without_login(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        Event::fake([Login::class, TwoFactorAuthenticationFailed::class]);

        $this->post(route('two-factor.login.store'), [
            'recovery_code' => 'invalid-recovery-code',
        ])->assertSessionHasErrors();

        Event::assertDispatched(TwoFactorAuthenticationFailed::class, fn (TwoFactorAuthenticationFailed $event): bool => $event->user->is($user));
        Event::assertNotDispatched(Login::class);
        $this->assertGuest();
    }
}
