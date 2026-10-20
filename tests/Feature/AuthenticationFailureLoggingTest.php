<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuthenticationFailureLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wrong_password_records_a_failed_login_for_the_account(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);

        $event = Activity::inLog('security')->sole();

        $this->assertSame('login.failed', $event->description);
        $this->assertSame('failure', $event->getProperty('result'));
        $this->assertTrue($event->subject->is($user));
        $this->assertNull($event->causer);
        $this->assertStringNotContainsString('wrong-password', json_encode($event->properties));
        $this->assertStringNotContainsString($user->email, json_encode($event->properties));
    }

    public function test_an_unknown_account_records_a_failed_login_without_a_subject(): void
    {
        $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $event = Activity::inLog('security')->sole();

        $this->assertSame('login.failed', $event->description);
        $this->assertNull($event->subject_id);
        $this->assertStringNotContainsString('nobody@example.com', json_encode($event->properties));
    }

    public function test_a_successful_login_does_not_record_a_failure(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_a_wrong_two_factor_code_records_a_failed_mfa_attempt(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
        ]);

        $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.login.store'), ['code' => '000000']);

        $event = Activity::inLog('security')->sole();

        $this->assertSame('mfa.failed', $event->description);
        $this->assertSame('failure', $event->getProperty('result'));
        $this->assertSame('totp', $event->getProperty('method'));
        $this->assertTrue($event->subject->is($user));
        $this->assertStringNotContainsString('000000', json_encode($event->properties));
    }

    public function test_a_wrong_recovery_code_records_the_recovery_code_method(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->withTwoFactor()->create();

        $this->withSession(['login.id' => $user->id])
            ->post(route('two-factor.login.store'), ['recovery_code' => 'not-a-real-code']);

        $event = Activity::inLog('security')->sole();

        $this->assertSame('mfa.failed', $event->description);
        $this->assertSame('recovery_code', $event->getProperty('method'));
        $this->assertStringNotContainsString('not-a-real-code', json_encode($event->properties));
    }
}
