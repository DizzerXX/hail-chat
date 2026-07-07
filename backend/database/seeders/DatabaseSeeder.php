<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        User::firstOrCreate(
            ['email' => 'admin@tdechat.test'],
            [
                'name' => 'Administrator',
                'password' => Hash::make('password'),
                'role_id' => Role::where('name', 'admin')->firstOrFail()->id,
            ],
        );
    }
}
