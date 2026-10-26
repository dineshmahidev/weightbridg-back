<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $level = [
            'super_admin' => 100,
            'company_admin' => 80,
            'manager' => 60,
            'security_officer' => 40,
            'quality_inspector' => 40,
            'weighbridge_operator' => 40,
            'loading_staff' => 40,
        ];

        $names = [
            'super_admin' => 'Super Admin',
            'company_admin' => 'Company Admin',
            'manager' => 'Manager',
            'security_officer' => 'Security Officer',
            'quality_inspector' => 'Quality Inspector',
            'weighbridge_operator' => 'Weighbridge Operator',
            'loading_staff' => 'Loading / Unloading Staff',
        ];

        foreach (PermissionCatalog::ALL as $slug => [$name, $group]) {
            Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'group' => $group]
            );
        }

        foreach ($names as $slug => $name) {
            $role = Role::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'is_system' => true, 'level' => $level[$slug]]
            );

            if ($slug === 'super_admin') {
                continue;
            }

            $permissionIds = Permission::query()
                ->whereIn('slug', PermissionCatalog::ROLE_PERMISSIONS[$slug] ?? [])
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
