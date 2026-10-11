<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\PurposeBoundAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withSession($this->confirmedEmailChangeSession(now()->timestamp))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => ' Test@Example.COM ',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_change_requires_recent_password_or_passkey_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'changed@example.com',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertDatabaseMissing('activity_log', ['event' => 'account.email_changed']);
    }

    public function test_expired_confirmation_does_not_allow_an_email_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedEmailChangeSession(now()->subMinutes(16)->timestamp))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'changed@example.com',
            ])
            ->assertRedirect(route('password.confirm'));

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_recent_confirmation_allows_an_email_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession($this->confirmedEmailChangeSession(now()->subMinutes(14)->timestamp))
            ->patch(route('profile.update'), [
                'name' => $user->name,
                'email' => 'changed@example.com',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertSessionMissing(PurposeBoundAuthentication::ConfirmedPurpose);

        $this->assertSame('changed@example.com', $user->fresh()->email);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'account.email_changed',
            'subject_id' => $user->getKey(),
        ]);
    }

    public function test_email_change_returns_password_confirmation_required_for_json_requests(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson(route('profile.update'), [
                'name' => $user->name,
                'email' => 'changed@example.com',
            ])
            ->assertStatus(423)
            ->assertJson(['message' => 'Password confirmation required.']);

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => strtoupper($user->email),
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }

    /** @return array<string, int|string> */
    private function confirmedEmailChangeSession(int $confirmedAt): array
    {
        return [
            'auth.password_confirmed_at' => $confirmedAt,
            PurposeBoundAuthentication::ConfirmedPurpose => PurposeBoundAuthentication::EmailChange,
        ];
    }
}
