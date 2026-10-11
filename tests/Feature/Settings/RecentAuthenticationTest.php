<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\PurposeBoundAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RecentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_authentication_timeout_defaults_to_fifteen_minutes(): void
    {
        $this->assertSame(900, config('auth.password_timeout'));
    }

    public function test_two_factor_management_rejects_an_expired_confirmation(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSecuritySession(now()->subMinutes(16)->timestamp))
            ->delete(route('two-factor.disable'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_recovery_code_regeneration_rejects_an_expired_confirmation(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSecuritySession(now()->subMinutes(16)->timestamp))
            ->post(route('two-factor.regenerate-recovery-codes'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_passkey_management_rejects_an_expired_confirmation(): void
    {
        $this->skipUnlessFortifyHas(Features::passkeys());
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSecuritySession(now()->subMinutes(16)->timestamp))
            ->get(route('passkey.registration-options'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_security_management_accepts_a_recent_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSecuritySession(now()->subMinutes(14)->timestamp))
            ->get(route('security.edit'))
            ->assertOk();
    }

    public function test_email_change_confirmation_cannot_authorize_security_management(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                'auth.password_confirmed_at' => now()->timestamp,
                PurposeBoundAuthentication::ConfirmedPurpose => PurposeBoundAuthentication::EmailChange,
            ])
            ->get(route('security.edit'))
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHas(
                PurposeBoundAuthentication::PendingPurpose,
                PurposeBoundAuthentication::SecuritySettings,
            );
    }

    public function test_successful_password_confirmation_is_bound_to_the_pending_purpose(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                PurposeBoundAuthentication::PendingPurpose => PurposeBoundAuthentication::EmailChange,
            ])
            ->post(route('password.confirm.store'), ['password' => 'password'])
            ->assertSessionHas(
                PurposeBoundAuthentication::ConfirmedPurpose,
                PurposeBoundAuthentication::EmailChange,
            )
            ->assertSessionMissing(PurposeBoundAuthentication::PendingPurpose);
    }

    public function test_failed_password_confirmation_does_not_grant_the_pending_purpose(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                PurposeBoundAuthentication::PendingPurpose => PurposeBoundAuthentication::EmailChange,
            ])
            ->post(route('password.confirm.store'), ['password' => 'incorrect-password'])
            ->assertSessionHas(PurposeBoundAuthentication::PendingPurpose)
            ->assertSessionMissing(PurposeBoundAuthentication::ConfirmedPurpose);
    }

    public function test_two_factor_setup_keeps_confirmation_until_setup_is_confirmed(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSecuritySession(now()->timestamp))
            ->post(route('two-factor.enable'))
            ->assertSessionHas(
                PurposeBoundAuthentication::ConfirmedPurpose,
                PurposeBoundAuthentication::SecuritySettings,
            );

        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    public function test_successful_security_change_consumes_the_confirmation(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedSecuritySession(now()->timestamp))
            ->delete(route('two-factor.disable'))
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertSessionMissing(PurposeBoundAuthentication::ConfirmedPurpose);

        $this->assertNull($user->fresh()->two_factor_secret);

        $this->post(route('two-factor.enable'))
            ->assertRedirect(route('password.confirm'));
    }

    /** @return array<string, int|string> */
    private function confirmedSecuritySession(int $confirmedAt): array
    {
        return [
            'auth.password_confirmed_at' => $confirmedAt,
            PurposeBoundAuthentication::ConfirmedPurpose => PurposeBoundAuthentication::SecuritySettings,
        ];
    }
}
