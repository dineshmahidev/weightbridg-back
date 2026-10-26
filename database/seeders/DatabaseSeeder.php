<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        User::query()->firstOrCreate(
            ['email' => 'super@weighbridge.test'],
            [
                'name' => 'Super Admin',
                'mobile' => '+91-9000000000',
                'password' => 'password',
                'role_id' => \App\Models\Role::query()->where('slug', 'super_admin')->value('id'),
                'status' => 'active',
            ]
        );

        $this->call(CompanySeeder::class);
        // DemoMovementSeeder disabled as mock data was removed
    }
}
