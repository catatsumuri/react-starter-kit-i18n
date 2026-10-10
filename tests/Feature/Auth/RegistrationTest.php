<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register()
    {
        $response = $this->post(route('register.store'), [
            'name' => 'Test User',
            'username' => ' Test.User-Name_1 ',
            'email' => ' Test@Example.COM ',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'username' => 'test.user-name_1',
            'email' => 'test@example.com',
        ]);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_username_is_required()
    {
        $this->post(route('register.store'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_username_must_be_unique()
    {
        $existingUser = User::factory()->create([
            'username' => 'existing-user',
        ]);

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'username' => strtoupper($existingUser->username),
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_email_must_be_unique_after_normalization()
    {
        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $this->post(route('register.store'), [
            'name' => 'Test User',
            'username' => 'test-user',
            'email' => strtoupper($existingUser->email),
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_username_must_use_the_supported_format()
    {
        foreach (['ab', str_repeat('a', 33), 'user@example', 'user name', 'ユーザー'] as $index => $username) {
            $this->post(route('register.store'), [
                'name' => 'Test User',
                'username' => $username,
                'email' => "invalid{$index}@example.com",
                'password' => 'password',
                'password_confirmation' => 'password',
            ])->assertSessionHasErrors('username');
        }

        $this->assertGuest();
    }

    public function test_user_identifiers_are_normalized_when_saved_directly()
    {
        $user = User::factory()->create([
            'username' => ' Direct.User ',
            'email' => ' Direct@Example.COM ',
        ]);

        $this->assertSame('direct.user', $user->username);
        $this->assertSame('direct@example.com', $user->email);
    }
}
