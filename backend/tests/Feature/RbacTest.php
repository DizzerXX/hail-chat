<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_resolves_permissions_through_role(): void
    {
        $permission = Permission::factory()->create(['name' => 'faqs.manage']);
        $role = Role::factory()->create();
        $role->permissions()->attach($permission);

        $user = User::factory()->for($role)->create();

        $this->assertTrue($user->hasPermission('faqs.manage'));
        $this->assertFalse($user->hasPermission('webhooks.manage'));
    }

    public function test_seeder_grants_expected_permissions_per_role(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $admin = Role::where('name', 'admin')->first();
        $supervisor = Role::where('name', 'supervisor')->first();
        $agent = Role::where('name', 'agent')->first();

        $this->assertSame(Permission::count(), $admin->permissions()->count());
        $this->assertTrue($supervisor->permissions->pluck('name')->contains('conversations.view_all'));
        $this->assertFalse($supervisor->permissions->pluck('name')->contains('webhooks.manage'));
        $this->assertTrue($agent->permissions->pluck('name')->contains('messages.send'));
        $this->assertFalse($agent->permissions->pluck('name')->contains('faqs.manage'));
    }

    public function test_permission_middleware_enforces_access(): void
    {
        Route::middleware('permission:faqs.manage')
            ->get('/_test/protected', fn () => response()->json(['ok' => true]));

        $permission = Permission::factory()->create(['name' => 'faqs.manage']);
        $grantedRole = Role::factory()->create();
        $grantedRole->permissions()->attach($permission);

        $granted = User::factory()->for($grantedRole)->create();
        $denied = User::factory()->create();
        $deactivated = User::factory()->for($grantedRole)->create(['is_active' => false]);

        $this->getJson('/_test/protected')->assertUnauthorized();
        $this->actingAs($denied)->getJson('/_test/protected')->assertForbidden();
        $this->actingAs($deactivated)->getJson('/_test/protected')->assertForbidden();
        $this->actingAs($granted)->getJson('/_test/protected')->assertOk();
    }
}
