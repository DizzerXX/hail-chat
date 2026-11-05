<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserListPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'admin')->first())->create(['name' => 'Aaa Admin']));
        User::factory()->count(30)->for(Role::where('name', 'agent')->first())->create();
    }

    public function test_the_list_is_paged_and_reports_the_total(): void
    {
        $this->getJson('/api/v1/admin/users?page=2&per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 31)
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_page_size_is_bounded(): void
    {
        $this->getJson('/api/v1/admin/users?per_page=500')->assertUnprocessable();
        $this->getJson('/api/v1/admin/users?per_page=5')->assertUnprocessable();
    }

    public function test_the_default_page_size_is_unchanged(): void
    {
        $this->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.per_page', 25);
    }
}
