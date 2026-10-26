<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use App\Models\VehicleMovement;
use App\Support\MovementStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('companies.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view companies');
        }

        $query = Company::query()->withCount(['departments', 'materials']);

        if (! $user->isSuperAdmin()) {
            $query->whereHas('users', fn ($q) => $q->whereKey($user->id));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }

        if ($request->query('status')) {
            $query->where('is_active', $request->query('status') === 'active');
        }

        $companies = $query->orderBy('name')->paginate(min((int) $request->query('per_page', 15), 100));

        return $this->paginated($companies);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('companies.create')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to create companies');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:40', 'unique:companies,code'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'workflow_config' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'admin_id' => ['sometimes', 'nullable', 'exists:users,id'],
        ]);

        $company = DB::transaction(function () use ($data) {
            $company = Company::query()->create($data);

            foreach (\App\Support\WorkflowStage::ORDER as $index => $stage) {
                if ($stage === \App\Support\WorkflowStage::MAIN_GATE_ENTRY || $stage === \App\Support\WorkflowStage::NET_WEIGHT_CALCULATION) {
                    continue;
                }

                Department::query()->create([
                    'company_id' => $company->id,
                    'name' => \App\Support\WorkflowStage::label($stage),
                    'code' => strtoupper(str_replace('_', '-', substr($stage, 0, 12))),
                    'sort_order' => $index,
                ]);
            }

            if (! empty($data['admin_id'])) {
                $admin = User::query()->whereKey($data['admin_id'])->first();
                if ($admin && $admin->belongsToCompany($company->id)) {
                    $company->users()->attach($admin->id, ['is_primary' => true]);
                }
            }

            return $company;
        });

        $this->audit('company.created', $company, null, $company->toArray(), $company->id);

        return $this->created($company->load('departments'), 'Company created');
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorizeCompany($company->id);

        $company->load([
            'departments',
            'materials' => fn ($q) => $q->where('is_active', true),
            'users:id,name,email,role_id',
        ]);

        $payload = $company->toArray();
        $payload['workflow_config'] = $company->workflow();

        return $this->ok($payload);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $user = $this->user();
        $this->authorizeCompany($company->id);

        if (! $user->can('companies.update')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to update companies');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'code' => ['sometimes', 'string', 'max:40', 'unique:companies,code,'.$company->id],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'gstin' => ['nullable', 'string', 'max:20'],
            'workflow_config' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
        ]);

        $old = $company->only(array_keys($data));

        $company->fill($data)->save();

        $this->audit('company.updated', $company, $old, $data, $company->id);

        return $this->ok($company->fresh('departments'), 'Company updated');
    }

    public function toggleStatus(Company $company): JsonResponse
    {
        $user = $this->user();
        $this->authorizeCompany($company->id);

        if (! $user->can('companies.manage')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to change company status');
        }

        $old = ['is_active' => $company->is_active];

        $company->update(['is_active' => ! $company->is_active]);

        $this->audit('company.status_changed', $company, $old, ['is_active' => $company->is_active], $company->id);

        return $this->ok($company, 'Company status updated');
    }

    public function stats(Company $company): JsonResponse
    {
        $this->authorizeCompany($company->id);

        $today = now()->startOfDay();

        $base = VehicleMovement::query()->where('company_id', $company->id);

        return $this->ok([
            'total_movements' => (clone $base)->count(),
            'vehicles_inside' => (clone $base)->whereIn('status', MovementStatus::OPEN)->count(),
            'completed_today' => (clone $base)->where('status', MovementStatus::COMPLETED)->where('exit_at', '>=', $today)->count(),
            'pending_quality' => (clone $base)->where('status', MovementStatus::QUALITY_PENDING)->count(),
            'pending_weighment' => (clone $base)->whereIn('status', array_merge(MovementStatus::PENDING_FIRST_WEIGHMENT, MovementStatus::PENDING_SECOND_WEIGHMENT))->count(),
            'pending_exit' => (clone $base)->whereIn('status', MovementStatus::PENDING_EXIT)->count(),
            'net_weight_today' => (float) (clone $base)->where('status', MovementStatus::COMPLETED)->where('exit_at', '>=', $today)->sum('net_weight'),
        ]);
    }

    public function assignAdmin(Request $request, Company $company): JsonResponse
    {
        $user = $this->user();
        $this->authorizeCompany($company->id);

        if (! $user->can('users.manage')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to assign company administrators');
        }

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $target = User::query()->findOrFail($data['user_id']);

        if (! $target->belongsToCompany($company->id)) {
            throw \App\Exceptions\ApiException::unprocessable('User is not assigned to this company');
        }

        DB::transaction(function () use ($company) {
            $company->users()->updateExistingPivot($company->users->pluck('id')->all(), ['is_primary' => false]);
        });

        $company->users()->updateExistingPivot($target->id, ['is_primary' => true]);

        $this->audit('company.admin_assigned', $company, null, ['user_id' => $target->id], $company->id);

        return $this->ok($company->load('users'), 'Company administrator assigned');
    }
}
