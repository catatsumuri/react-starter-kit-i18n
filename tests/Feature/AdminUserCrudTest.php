<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminUserCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_admin_users_can_visit_create_and_edit_pages(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->get(route('admin.users.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/create'));

        $this->actingAs($admin)
            ->get(route('admin.users.edit', $user))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/edit')
                ->where('user.email', $user->email));
    }

    public function test_admin_users_can_create_users(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Managed User',
            'email' => 'managed@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_admin' => '1',
        ]);

        $user = User::query()->where('email', 'managed@example.com')->firstOrFail();

        $response->assertRedirect(route('admin.users.show', $user));

        $this->assertSame('Managed User', $user->name);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_admin_users_can_update_users(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create([
            'password' => 'original-password',
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $response = $this->actingAs($admin)->patch(route('admin.users.update', $user), [
            'name' => 'Updated User',
            'email' => 'updated@example.com',
            'password' => '',
            'password_confirmation' => '',
            'is_admin' => '0',
        ]);

        $user->refresh();

        $response->assertRedirect(route('admin.users.show', $user));

        $this->assertSame('Updated User', $user->name);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertTrue(Hash::check('original-password', $user->password));
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_admin_users_must_confirm_passwords_when_creating_users(): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Managed User',
            'email' => 'managed@example.com',
            'password' => 'password',
            'password_confirmation' => 'different-password',
            'is_admin' => '0',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'managed@example.com']);
    }

    public function test_admin_users_must_confirm_passwords_when_updating_passwords(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create([
            'password' => 'original-password',
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'changed-password',
            'password_confirmation' => 'different-password',
            'is_admin' => '0',
        ]);

        $response->assertSessionHasErrors('password');

        $user->refresh();

        $this->assertTrue(Hash::check('original-password', $user->password));
    }

    public function test_admin_users_can_update_passwords_with_confirmation(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create([
            'password' => 'original-password',
        ]);

        $response = $this->actingAs($admin)->patch(route('admin.users.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'changed-password',
            'password_confirmation' => 'changed-password',
            'is_admin' => '0',
        ]);

        $user->refresh();

        $response->assertRedirect(route('admin.users.show', $user));

        $this->assertTrue(Hash::check('changed-password', $user->password));
    }

    public function test_admin_users_can_delete_other_users(): void
    {
        $admin = $this->adminUser();
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->delete(route('admin.users.destroy', $user));

        $response->assertRedirect(route('admin.users.index'));
        $this->assertModelMissing($user);
    }

    public function test_admin_users_cannot_delete_themselves(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertForbidden();

        $this->assertModelExists($admin);
    }

    public function test_non_admin_users_cannot_manage_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('admin.users.create'))
            ->assertForbidden();
    }

    private function adminUser(): User
    {
        $admin = User::factory()->create();

        $admin->assignRole(Role::findOrCreate('admin', 'web'));

        return $admin;
    }
}
