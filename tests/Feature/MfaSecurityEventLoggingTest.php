<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MfaSecurityEventLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_starting_two_factor_setup_is_not_recorded_until_it_is_confirmed(): void
    {
        $this->actingAsPasswordConfirmed()->post('/user/two-factor-authentication');

        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_confirming_two_factor_records_mfa_enabled(): void
    {
        $user = $this->enableAndConfirmTwoFactor();

        $event = Activity::inLog('security')->sole();

        $this->assertSame('mfa.enabled', $event->description);
        $this->assertTrue($event->subject->is($user));
        $this->assertTrue($event->causer->is($user));
    }

    public function test_disabling_confirmed_two_factor_records_mfa_disabled_as_active(): void
    {
        $user = $this->enableAndConfirmTwoFactor();
        Activity::inLog('security')->delete();

        $this->actingAsPasswordConfirmed($user)->delete('/user/two-factor-authentication');

        $event = Activity::inLog('security')->sole();

        $this->assertSame('mfa.disabled', $event->description);
        $this->assertTrue($event->getProperty('was_active'));
    }

    public function test_cancelling_an_unconfirmed_setup_is_recorded_as_not_active(): void
    {
        $user = User::factory()->create();
        $this->actingAsPasswordConfirmed($user)->post('/user/two-factor-authentication');
        $this->actingAsPasswordConfirmed($user)->delete('/user/two-factor-authentication');

        $event = Activity::inLog('security')->sole();

        $this->assertSame('mfa.disabled', $event->description);
        $this->assertFalse($event->getProperty('was_active'));
    }

    public function test_regenerating_recovery_codes_is_recorded_without_the_codes(): void
    {
        $user = $this->enableAndConfirmTwoFactor();
        Activity::inLog('security')->delete();

        $this->actingAsPasswordConfirmed($user)->post('/user/two-factor-recovery-codes');

        $event = Activity::inLog('security')->sole();

        $this->assertSame('mfa.recovery_codes.regenerated', $event->description);
        $this->assertStringNotContainsString($user->fresh()->recoveryCodes()[0], json_encode($event->properties));
    }

    public function test_a_wrong_confirmation_code_does_not_record_mfa_enabled(): void
    {
        $user = User::factory()->create();
        $this->actingAsPasswordConfirmed($user)->post('/user/two-factor-authentication');

        $this->actingAsPasswordConfirmed($user)->post('/user/confirmed-two-factor-authentication', ['code' => '000000']);

        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_cancelled_user_saves_do_not_record_mfa_events(): void
    {
        $user = $this->enableAndConfirmTwoFactor();
        Activity::inLog('security')->delete();
        $codesBefore = $user->recoveryCodes();

        User::updating(fn () => false);

        try {
            $this->actingAsPasswordConfirmed($user)->post('/user/two-factor-recovery-codes');
            $this->actingAsPasswordConfirmed($user)->delete('/user/two-factor-authentication');
        } finally {
            User::flushEventListeners();
        }

        $fresh = $user->fresh();

        $this->assertSame($codesBefore, $fresh->recoveryCodes());
        $this->assertNotNull($fresh->two_factor_confirmed_at);
        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_a_cancelled_confirmation_save_does_not_record_mfa_enabled(): void
    {
        $user = User::factory()->create();
        $this->actingAsPasswordConfirmed($user)->post('/user/two-factor-authentication');
        $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));

        User::updating(fn () => false);

        try {
            $this->actingAsPasswordConfirmed($user)->post('/user/confirmed-two-factor-authentication', ['code' => $code]);
        } finally {
            User::flushEventListeners();
        }

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_confirming_an_already_confirmed_two_factor_is_not_recorded_again(): void
    {
        $user = $this->enableAndConfirmTwoFactor();
        Activity::inLog('security')->delete();

        Cache::flush();
        $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
        $this->actingAsPasswordConfirmed($user)->post('/user/confirmed-two-factor-authentication', ['code' => $code]);

        $this->assertSame(0, Activity::inLog('security')->count());
    }

    public function test_a_cancelled_save_after_a_successful_one_on_the_same_instance_is_not_recorded(): void
    {
        $user = User::factory()->create();

        app(EnableTwoFactorAuthentication::class)($user);
        app(GenerateNewRecoveryCodes::class)($user);
        $recorded = Activity::inLog('security')->count();

        User::updating(fn () => false);

        try {
            app(GenerateNewRecoveryCodes::class)($user);
            app(DisableTwoFactorAuthentication::class)($user);
        } finally {
            User::flushEventListeners();
        }

        $this->assertSame($recorded, Activity::inLog('security')->count());
    }

    private function actingAsPasswordConfirmed(?User $user = null): static
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function enableAndConfirmTwoFactor(): User
    {
        $user = User::factory()->create();

        $this->actingAsPasswordConfirmed($user)->post('/user/two-factor-authentication');

        $secret = decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->actingAsPasswordConfirmed($user)->post('/user/confirmed-two-factor-authentication', ['code' => $code]);

        return $user->fresh();
    }
}
