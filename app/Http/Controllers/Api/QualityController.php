<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\ApiController;
use App\Models\QualityInspection;
use App\Models\QualityParameter;
use App\Models\VehicleMovement;
use App\Services\MovementService;
use App\Support\MovementStatus;
use App\Support\WorkflowStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QualityController extends ApiController
{
    public function __construct(private readonly MovementService $movements)
    {
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('quality.view')) {
            throw ApiException::forbidden('You are not allowed to view quality inspections');
        }

        $query = VehicleMovement::query()
            ->with(['company:id,name', 'material:id,name', 'vehicle'])
            ->where('current_stage', WorkflowStage::QUALITY_CHECK)
            ->whereIn('status', [MovementStatus::QUALITY_PENDING, MovementStatus::QUALITY_FAILED])
            ->latest('entry_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 15), 100)));
    }

    public function parameters(Request $request): JsonResponse
    {
        $user = $this->user();

        $query = QualityParameter::query()->where('is_active', true)->orderBy('sort_order');

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

        return $this->ok($query->get());
    }

    public function store(Request $request, VehicleMovement $movement): JsonResponse
    {
        $user = $this->user();
        $this->authorizeMovement($movement);

        if (! $user->can('quality.create')) {
            throw ApiException::forbidden('You are not allowed to perform quality inspections');
        }

        $company = $movement->company()->first();

        if (! $company->isStageEnabled(WorkflowStage::QUALITY_CHECK)) {
            throw ApiException::unprocessable('Quality check is disabled for this company');
        }

        $expectedStatuses = [MovementStatus::QUALITY_PENDING];

        if ($user->can('quality.update')) {
            $expectedStatuses[] = MovementStatus::QUALITY_FAILED;
            $expectedStatuses[] = MovementStatus::QUALITY_PASSED;
        }

        if (! in_array($movement->status, $expectedStatuses, true) || $movement->current_stage !== WorkflowStage::QUALITY_CHECK) {
            throw ApiException::unprocessable('This movement is not awaiting quality inspection');
        }

        $data = $request->validate([
            'result' => ['required', 'in:PASS,FAIL'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'rejection_reason' => ['nullable', 'string', 'max:1000', 'required_if:result,FAIL'],
            'parameter_values' => ['nullable', 'array'],
        ]);

        $requiredParams = QualityParameter::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->where(fn ($q) => $q->where('company_id', $movement->company_id)->orWhereNull('company_id'))
            ->where(fn ($q) => $q->where('material_id', $movement->material_id)->orWhereNull('material_id'))
            ->get();

        $values = $data['parameter_values'] ?? [];

        foreach ($requiredParams as $param) {
            $value = collect($values)->firstWhere('parameter_id', $param->id);

            if ($value === null || $value === '') {
                throw ApiException::unprocessable('Required quality parameter missing: '.$param->name);
            }

            if ($param->data_type === 'number' && is_numeric($value['value'] ?? null)) {
                $numeric = (float) $value['value'];

                if ($param->min_value !== null && $numeric < (float) $param->min_value) {
                    throw ApiException::unprocessable($param->name.' must be at least '.$param->min_value.' '.$param->unit);
                }

                if ($param->max_value !== null && $numeric > (float) $param->max_value) {
                    throw ApiException::unprocessable($param->name.' must be at most '.$param->max_value.' '.$param->unit);
                }
            }
        }

        $inspection = DB::transaction(function () use ($movement, $data, $user) {
            $inspection = QualityInspection::query()->updateOrCreate(
                ['vehicle_movement_id' => $movement->id],
                [
                    'result' => $data['result'],
                    'remarks' => $data['remarks'] ?? null,
                    'rejection_reason' => $data['rejection_reason'] ?? null,
                    'parameter_values' => $data['parameter_values'] ?? null,
                    'inspector_id' => $user->id,
                    'inspected_at' => now(),
                ]
            );

            $movement->forceFill(['quality_result' => $data['result']])->save();

            if ($data['result'] === 'PASS') {
                $this->movements->transition(
                    $movement,
                    WorkflowStage::QUALITY_CHECK,
                    MovementStatus::QUALITY_PASSED,
                    $user,
                    $data['remarks'] ?? null,
                    ['inspection_id' => $inspection->id],
                    'quality_passed'
                );

                $this->movements->proceedFrom($movement->fresh(), WorkflowStage::QUALITY_CHECK, $user);
            } else {
                $this->movements->transition(
                    $movement,
                    WorkflowStage::QUALITY_CHECK,
                    MovementStatus::QUALITY_FAILED,
                    $user,
                    $data['rejection_reason'] ?? $data['remarks'] ?? null,
                    ['inspection_id' => $inspection->id],
                    'quality_failed'
                );
            }

            return $inspection->refresh(['inspector']);
        });

        $this->audit('quality.inspected', $movement, null, [
            'result' => $data['result'],
            'rejection_reason' => $data['rejection_reason'] ?? null,
        ], $movement->company_id);

        return $this->created([
            'inspection' => $inspection,
            'movement' => $movement->fresh(['company', 'material', 'qualityInspection', 'weighments', 'operations', 'exitApproval', 'histories']),
        ], 'Quality inspection recorded');
    }

    public function show(VehicleMovement $movement): JsonResponse
    {
        $this->authorizeMovement($movement);

        if (! $this->user()->can('quality.view')) {
            throw ApiException::forbidden('You are not allowed to view quality inspections');
        }

        return $this->ok($movement->load(['qualityInspection.inspector', 'company:id,name', 'material:id,name']));
    }
}
