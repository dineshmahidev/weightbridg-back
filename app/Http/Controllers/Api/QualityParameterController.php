<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\ApiController;
use App\Models\QualityParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QualityParameterController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        $query = QualityParameter::query()
            ->with(['company:id,name', 'material:id,name'])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('company_id')) {
            $companyId = (int) $request->query('company_id');
            $this->authorizeCompany($companyId);
            $query->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
        } elseif (! $user->isSuperAdmin()) {
            $ids = $user->accessibleCompanyIds();
            $query->where(fn ($q) => $q->whereIn('company_id', $ids)->orWhereNull('company_id'));
        }

        if ($request->filled('material_id')) {
            $query->where(fn ($q) => $q->where('material_id', $request->query('material_id'))->orWhereNull('material_id'));
        }

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 50), 100)));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();

        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'material_id' => ['nullable', 'exists:materials,id'],
            'name' => ['required', 'string', 'max:120'],
            'data_type' => ['nullable', 'in:number,text,boolean,date'],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_required' => ['sometimes', 'boolean'],
            'min_value' => ['nullable', 'numeric', 'min:0'],
            'max_value' => ['nullable', 'numeric', 'min:0', 'gte:min_value'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $this->authorizeCompany((int) $data['company_id']);

        if (! $user->can('quality_params.create')) {
            throw ApiException::forbidden('You are not allowed to create quality parameters');
        }

        $parameter = QualityParameter::query()->create($data);

        $this->audit('quality_parameter.created', $parameter, null, $parameter->toArray(), $parameter->company_id);

        return $this->created($parameter, 'Quality parameter created');
    }

    public function update(Request $request, QualityParameter $qualityParameter): JsonResponse
    {
        $user = $this->user();

        if ($qualityParameter->company_id) {
            $this->authorizeCompany($qualityParameter->company_id);
        }

        if (! $user->can('quality_params.update')) {
            throw ApiException::forbidden('You are not allowed to update quality parameters');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'data_type' => ['nullable', 'in:number,text,boolean,date'],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_required' => ['sometimes', 'boolean'],
            'min_value' => ['nullable', 'numeric', 'min:0'],
            'max_value' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $old = $qualityParameter->only(array_keys($data));

        $qualityParameter->fill($data)->save();

        $this->audit('quality_parameter.updated', $qualityParameter, $old, $data, $qualityParameter->company_id);

        return $this->ok($qualityParameter, 'Quality parameter updated');
    }

    public function destroy(QualityParameter $qualityParameter): JsonResponse
    {
        $user = $this->user();

        if ($qualityParameter->company_id) {
            $this->authorizeCompany($qualityParameter->company_id);
        }

        if (! $user->can('quality_params.update')) {
            throw ApiException::forbidden('You are not allowed to delete quality parameters');
        }

        $qualityParameter->delete();

        $this->audit('quality_parameter.deleted', $qualityParameter, null, null, $qualityParameter->company_id);

        return $this->ok(null, 'Quality parameter deleted');
    }
}
