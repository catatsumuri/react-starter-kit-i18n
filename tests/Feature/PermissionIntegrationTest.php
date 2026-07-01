<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_users_receive_permissions_through_roles(): void
    {
        $permission = Permission::create(['name' => 'manage users']);
        $role = Role::create(['name' => 'admin']);
        $user = User::factory()->create();

        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $this->assertTrue($user->can('manage users'));
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_seeded_users_include_regular_user_and_admin_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $testUser = User::query()->where('email', 'test@example.com')->first();
        $adminUser = User::query()->where('email', 'admin@example.com')->first();

        $this->assertInstanceOf(User::class, $testUser);
        $this->assertFalse($testUser->hasRole('admin'));

        $this->assertInstanceOf(User::class, $adminUser);
        $this->assertTrue($adminUser->hasRole('admin'));
    }

    public function test_permission_middleware_alias_authorizes_routes(): void
    {
        $permission = Permission::create(['name' => 'manage users']);
        $role = Role::create(['name' => 'admin']);
        $authorizedUser = User::factory()->create();
        $unauthorizedUser = User::factory()->create();

        $role->givePermissionTo($permission);
        $authorizedUser->assignRole($role);

        Route::middleware(['web', 'permission:manage users'])->get(
            '/_test/permission',
            fn () => response()->noContent(),
        );

        $this->actingAs($authorizedUser)
            ->get('/_test/permission')
            ->assertNoContent();

        $this->actingAs($unauthorizedUser)
            ->get('/_test/permission')
            ->assertForbidden();
    }
}
