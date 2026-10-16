<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'current_password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_cannot_be_changed_without_the_current_password()
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'attacker@example.com',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_email_cannot_be_changed_with_an_incorrect_password()
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'attacker@example.com',
                'current_password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_current_password_cannot_be_brute_forced_through_the_profile_update()
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->actingAs($user)->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'attacker@example.com',
                'current_password' => 'wrong-password',
            ]);
        }

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'attacker@example.com',
                'current_password' => 'password',
            ])
            ->assertTooManyRequests();

        $this->assertNotSame('attacker@example.com', $user->refresh()->email);
    }

    public function test_profile_update_throttling_does_not_affect_other_users()
    {
        $throttledUser = User::factory()->create();
        $otherUser = User::factory()->create();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->actingAs($throttledUser)->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'attacker@example.com',
                'current_password' => 'wrong-password',
            ]);
        }

        $this
            ->actingAs($otherUser)
            ->patch(route('profile.update'), [
                'name' => 'Other User',
                'email' => 'other@example.com',
                'current_password' => 'password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('other@example.com', $otherUser->refresh()->email);
    }

    public function test_email_cannot_be_changed_by_letter_case_without_the_current_password()
    {
        $user = User::factory()->create(['email' => 'Alice@example.com']);

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'alice@example.com',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame('Alice@example.com', $user->refresh()->email);
    }

    public function test_array_email_is_rejected_with_a_validation_error()
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => ['attacker@example.com'],
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('profile.edit'));

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_empty_email_is_rejected_with_a_validation_error()
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => '',
                'current_password' => 'password',
            ])
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('profile.edit'));

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_array_current_password_is_rejected_with_a_validation_error()
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'attacker@example.com',
                'current_password' => ['password'],
            ])
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(route('profile.edit'));

        $this->assertSame($originalEmail, $user->refresh()->email);
    }

    public function test_email_is_not_changed_by_extra_request_fields()
    {
        $user = User::factory()->create();
        $originalEmail = $user->email;

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
                'password' => 'hijacked-password',
                'email_verified_at' => null,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame($originalEmail, $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_name_can_be_changed_without_the_current_password()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'New Name',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('New Name', $user->refresh()->name);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
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
}
