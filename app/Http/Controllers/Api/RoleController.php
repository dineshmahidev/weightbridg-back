<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Http\JsonResponse;

class RoleController extends ApiController
{
    public function index(): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('roles.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view roles');
        }

        $roles = Role::query()
            ->with('permissions:id,name,slug,group')
            ->orderByDesc('level')
            ->orderBy('name')
            ->get();

        return $this->ok($roles);
    }

    public function permissions(): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('roles.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view permissions');
        }

        return $this->ok(collect(PermissionCatalog::all())->map(
            fn ($item, $slug) => ['slug' => $slug, 'name' => $item['label'], 'group' => $item['group']]
        )->values());
    }

    public function updatePermissions(\Illuminate\Http\Request $request, Role $role): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('roles.update')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to update role permissions');
        }

        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $slugs = array_values(array_intersect(array_keys(PermissionCatalog::ALL), $data['permissions']));

        if ($role->slug === 'super_admin') {
            throw \App\Exceptions\ApiException::unprocessable('Super Admin permissions cannot be modified');
        }

        $old = $role->permissions()->pluck('slug')->all();

        $role->permissions()->sync($slugs);

        $this->audit('role.permissions_updated', $role, ['permissions' => $old], ['permissions' => $slugs]);

        return $this->ok($role->fresh('permissions'), 'Role permissions updated');
    }
}
