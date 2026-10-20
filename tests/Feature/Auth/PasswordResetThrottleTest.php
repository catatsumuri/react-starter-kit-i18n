<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PasswordResetThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_resets_within_the_limit_are_all_recorded(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->attemptReset($user->email)->assertSessionHasErrors('email');
        }

        $this->assertSame(5, Activity::inLog('security')->where('event', 'password.reset_failed')->count());
    }

    public function test_repeated_attempts_for_one_account_are_throttled_without_more_log_rows(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->attemptReset($user->email);
        }

        $this->attemptReset($user->email)->assertTooManyRequests();
        $this->attemptReset($user->email)->assertTooManyRequests();

        $this->assertSame(5, Activity::inLog('security')->count());
        $this->assertTrue(password_verify('password', $user->fresh()->password));
    }

    public function test_one_address_cannot_flood_the_log_by_varying_the_email(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->attemptReset("nobody{$attempt}@example.com")->assertSessionHasErrors('email');
        }

        $this->attemptReset('nobody11@example.com')->assertTooManyRequests();

        $this->assertSame(10, Activity::inLog('security')->count());
    }

    public function test_a_throttled_account_does_not_block_other_accounts(): void
    {
        $blocked = User::factory()->create();
        $other = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->attemptReset($blocked->email);
        }

        $this->attemptReset($other->email)->assertSessionHasErrors('email');
    }

    public function test_requesting_a_reset_link_is_not_affected_by_the_reset_limit(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 6) as $attempt) {
            $this->attemptReset($user->email);
        }

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();
    }

    private function attemptReset(string $email)
    {
        return $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $email,
            'password' => 'new-secret-value',
            'password_confirmation' => 'new-secret-value',
        ]);
    }
}
