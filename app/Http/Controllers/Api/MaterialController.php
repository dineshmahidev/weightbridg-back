<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\ApiController;
use App\Models\Material;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        $query = Material::query()->with('company:id,name,code')->orderBy('name');

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

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
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
            'unit' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $this->authorizeCompany((int) $data['company_id']);

        if (! $user->can('materials.create')) {
            throw ApiException::forbidden('You are not allowed to create materials');
        }

        $material = Material::query()->create($data);

        $this->audit('material.created', $material, null, $material->toArray(), $material->company_id);

        return $this->created($material, 'Material created');
    }

    public function update(Request $request, Material $material): JsonResponse
    {
        $user = $this->user();
        $this->authorizeCompany($material->company_id);

        if (! $user->can('materials.update')) {
            throw ApiException::forbidden('You are not allowed to update materials');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:40'],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $material->only(array_keys($data));

        $material->fill($data)->save();

        $this->audit('material.updated', $material, $old, $data, $material->company_id);

        return $this->ok($material, 'Material updated');
    }

    public function destroy(Material $material): JsonResponse
    {
        $user = $this->user();
        $this->authorizeCompany($material->company_id);

        if (! $user->can('materials.update')) {
            throw ApiException::forbidden('You are not allowed to delete materials');
        }

        $material->delete();

        $this->audit('material.deleted', $material, null, null, $material->company_id);

        return $this->ok(null, 'Material deleted');
    }
}
