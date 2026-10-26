<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\ApiController;
use App\Models\Company;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        $query = Department::query()->with('company:id,name,code')->orderBy('sort_order')->orderBy('name');

        if ($request->filled('company_id')) {
            $companyId = (int) $request->query('company_id');
            $this->authorizeCompany($companyId);
            $query->where('company_id', $companyId);
        } elseif (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        }

        if ($request->query('status')) {
            $query->where('is_active', $request->query('status') === 'active');
        }

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 50), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();

        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $this->authorizeCompany((int) $data['company_id']);

        if (! $user->can('departments.create')) {
            throw ApiException::forbidden('You are not allowed to create departments');
        }

        $exists = Department::query()
            ->where('company_id', $data['company_id'])
            ->where('name', $data['name'])
            ->exists();

        if ($exists) {
            throw ApiException::conflict('A department with this name already exists in the company');
        }

        $department = Department::query()->create($data);

        $this->audit('department.created', $department, null, $department->toArray(), $department->company_id);

        return $this->created($department, 'Department created');
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $user = $this->user();
        $this->authorizeCompany($department->company_id);

        if (! $user->can('departments.update')) {
            throw ApiException::forbidden('You are not allowed to update departments');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $old = $department->only(array_keys($data));

        $department->fill($data)->save();

        $this->audit('department.updated', $department, $old, $data, $department->company_id);

        return $this->ok($department, 'Department updated');
    }

    public function destroy(Department $department): JsonResponse
    {
        $user = $this->user();
        $this->authorizeCompany($department->company_id);

        if (! $user->can('departments.update')) {
            throw ApiException::forbidden('You are not allowed to delete departments');
        }

        $department->delete();

        $this->audit('department.deleted', $department, null, null, $department->company_id);

        return $this->ok(null, 'Department deleted');
    }
}
