<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\VehicleMovement;
use App\Services\WeightService;
use App\Support\MovementPresenter;
use App\Support\MovementStatus;
use App\Support\WorkflowStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeighbridgeController extends ApiController
{
    public function __construct(private readonly WeightService $weights, private readonly \App\Services\MovementService $movements)
    {
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('weighments.view') && ! $user->can('weighments.create')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view weighments');
        }

        $stage = $request->query('stage', WorkflowStage::FIRST_WEIGHMENT);

        $query = VehicleMovement::query()
            ->with(['company:id,name', 'material:id,name', 'vehicle'])
            ->where('current_stage', $stage)
            ->whereIn('status', array_merge(
                $stage === WorkflowStage::FIRST_WEIGHMENT ? MovementStatus::PENDING_FIRST_WEIGHMENT : [],
                $stage === WorkflowStage::SECOND_WEIGHMENT ? MovementStatus::PENDING_SECOND_WEIGHMENT : [],
            ))
            ->latest('entry_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 15), 100)));
    }

    public function record(Request $request, VehicleMovement $movement, int $sequence): JsonResponse
    {
        $data = $request->validate([
            'weight' => ['required', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'weight_type' => ['required', 'in:empty,loaded'],
            'weighbridge_ref' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->weights->recordWeighment($movement, $sequence, $data, $this->user());

        $movement = $movement->fresh(['company', 'department', 'material', 'qualityInspection', 'weighments.operator', 'operations', 'exitApproval', 'histories']);

        return $this->created(MovementPresenter::detail($movement), 'Weighment recorded');
    }

    public function showWeights(VehicleMovement $movement): JsonResponse
    {
        $this->authorizeMovement($movement);

        if (! $this->user()->can('weighments.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view weighments');
        }

        return $this->ok(array_merge(
            $this->movements->weightSummary($movement),
            ['movement' => MovementPresenter::list($movement->load(['company:id,name', 'material:id,name']))]
        ));
    }

    public function correct(Request $request, int $weighmentId): JsonResponse
    {
        $weighment = \App\Models\Weighment::query()->with('movement')->findOrFail($weighmentId);

        $data = $request->validate([
            'weight' => ['sometimes', 'numeric', 'gt:0'],
            'unit' => ['sometimes', 'string', 'max:20'],
            'weighbridge_ref' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->weights->correctWeighment($weighment, $data, $this->user());

        return $this->ok(MovementPresenter::detail($weighment->movement()->first()), 'Weighment corrected');
    }

    public function verify(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $movement = $this->weights->verify(
            $movement,
            $this->user(),
            $data['decision'] === 'approve',
            $data['remarks'] ?? null
        );

        return $this->ok(MovementPresenter::detail($movement), $data['decision'] === 'approve' ? 'Weight verified' : 'Weight verification rejected');
    }
}
