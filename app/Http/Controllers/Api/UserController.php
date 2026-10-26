<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\ApiController;
use App\Models\Company;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends ApiController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('users.view')) {
            throw ApiException::forbidden('You are not allowed to view users');
        }

        $query = User::query()->with(['role:id,name,slug', 'department:id,name,company_id', 'companies:id,name,code']);

        if (! $user->isSuperAdmin()) {
            $query->whereHas('companies', fn ($q) => $q->whereIn('companies.id', $user->accessibleCompanyIds()));
        }

        if ($request->filled('company_id')) {
            $companyId = (int) $request->query('company_id');
            $this->authorizeCompany($companyId);
            $query->whereHas('companies', fn ($q) => $q->where('companies.id', $companyId));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        if ($request->filled('role_id')) {
            $query->where('role_id', $request->query('role_id'));
        }

        if ($request->query('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('mobile', 'like', "%{$search}%"));
        }

        return $this->paginated($query->orderBy('name')->paginate(min((int) $request->query('per_page', 15), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('users.create')) {
            throw ApiException::forbidden('You are not allowed to create users');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8'],
            'role_id' => ['required', 'exists:roles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'company_ids' => ['required', 'array', 'min:1'],
            'company_ids.*' => ['integer', 'exists:companies,id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $role = Role::query()->findOrFail($data['role_id']);

        if (! $user->isSuperAdmin() && $role->slug === 'super_admin') {
            throw ApiException::forbidden('Only Super Admin can create Super Admin accounts');
        }

        $companyIds = $user->isSuperAdmin()
            ? $data['company_ids']
            : array_values(array_intersect($user->accessibleCompanyIds(), array_map('intval', $data['company_ids'])));

        if ($companyIds === []) {
            throw ApiException::forbidden('You cannot assign users to unauthorized companies');
        }

        if (! empty($data['department_id'])) {
            $department = Department::query()->findOrFail($data['department_id']);
            if (! in_array($department->company_id, $companyIds, true)) {
                throw ApiException::unprocessable('Department does not belong to the assigned companies');
            }
        }

        $created = DB::transaction(function () use ($data, $companyIds, $role) {
            $newUser = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile' => $data['mobile'] ?? null,
                'password' => $data['password'],
                'role_id' => $role->id,
                'department_id' => $data['department_id'] ?? null,
                'status' => $data['status'] ?? 'active',
            ]);

            $newUser->companies()->attach($companyIds, ['is_primary' => $companyIds[0] === (int) ($data['company_ids'][0] ?? null)]);

            return $newUser;
        });

        $this->audit('user.created', $created, null, ['email' => $created->email, 'role' => $role->slug, 'companies' => $companyIds], $companyIds[0] ?? null);

        return $this->created($created->load(['role', 'department', 'companies']), 'User created');
    }

    public function show(User $user): JsonResponse
    {
        $actor = $this->user();

        if (! $actor->can('users.view')) {
            throw ApiException::forbidden('You are not allowed to view users');
        }

        if (! $actor->isSuperAdmin()) {
            $shared = array_intersect($actor->accessibleCompanyIds(), $user->companies->pluck('id')->all());

            if ($shared === []) {
                throw ApiException::forbidden('You are not authorized for this user');
            }
        }

        return $this->ok($user->load(['role.permissions', 'department', 'companies']));
    }

    public function update(Request $request, User $target): JsonResponse
    {
        $actor = $this->user();

        if (! $actor->can('users.update')) {
            throw ApiException::forbidden('You are not allowed to update users');
        }

        if (! $actor->isSuperAdmin()) {
            $shared = array_intersect($actor->accessibleCompanyIds(), $target->companies->pluck('id')->all());
            if ($shared === []) {
                throw ApiException::forbidden('You are not authorized for this user');
            }
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'mobile' => ['nullable', 'string', 'max:30'],
            'role_id' => ['sometimes', 'exists:roles,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'company_ids' => ['sometimes', 'array', 'min:1'],
            'company_ids.*' => ['integer', 'exists:companies,id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (isset($data['role_id'])) {
            $role = Role::query()->findOrFail($data['role_id']);

            if (! $actor->isSuperAdmin() && $role->slug === 'super_admin') {
                throw ApiException::forbidden('Only Super Admin can assign Super Admin role');
            }
        }

        $old = $target->only(['name', 'mobile', 'role_id', 'department_id', 'status']);

        DB::transaction(function () use ($target, $data, $actor) {
            $target->fill(collect($data)->except('company_ids')->all())->save();

            if (isset($data['company_ids'])) {
                $ids = $actor->isSuperAdmin()
                    ? array_map('intval', $data['company_ids'])
                    : array_values(array_intersect($actor->accessibleCompanyIds(), array_map('intval', $data['company_ids'])));

                if ($ids === []) {
                    throw ApiException::forbidden('You cannot assign users to unauthorized companies');
                }

                $target->companies()->sync($ids);
            }
        });

        $this->audit('user.updated', $target, $old, $data);

        return $this->ok($target->fresh(['role', 'department', 'companies']), 'User updated');
    }

    public function resetPassword(Request $request, User $target): JsonResponse
    {
        $actor = $this->user();

        if (! $actor->can('users.manage')) {
            throw ApiException::forbidden('You are not allowed to reset user passwords');
        }

        if (! $actor->isSuperAdmin()) {
            $shared = array_intersect($actor->accessibleCompanyIds(), $target->companies->pluck('id')->all());
            if ($shared === []) {
                throw ApiException::forbidden('You are not authorized for this user');
            }
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8'],
        ]);

        $this->auth->setPassword($target, $data['password']);
        $this->auth->logoutAll($target);

        $this->audit('user.password_reset', $target, null, null);

        return $this->ok(null, 'Password reset. All sessions of this user were logged out.');
    }

    public function destroy(User $target): JsonResponse
    {
        $actor = $this->user();

        if (! $actor->can('users.manage')) {
            throw ApiException::forbidden('You are not allowed to deactivate users');
        }

        if ($target->id === $actor->id) {
            throw ApiException::unprocessable('You cannot deactivate your own account');
        }

        if (! $actor->isSuperAdmin()) {
            $shared = array_intersect($actor->accessibleCompanyIds(), $target->companies->pluck('id')->all());
            if ($shared === []) {
                throw ApiException::forbidden('You are not authorized for this user');
            }
        }

        $target->update(['status' => 'inactive']);
        $this->auth->logoutAll($target);

        $this->audit('user.deactivated', $target, ['status' => 'active'], ['status' => 'inactive']);

        return $this->ok(null, 'User deactivated');
    }
}
