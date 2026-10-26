<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\VehicleMovement;
use App\Services\MovementService;
use App\Services\OperationService;
use App\Support\MovementPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationController extends ApiController
{
    public function __construct(
        private readonly OperationService $operations,
        private readonly MovementService $movements,
    ) {
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('operations.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view operations');
        }

        $query = VehicleMovement::query()
            ->with(['company:id,name', 'material:id,name'])
            ->where('current_stage', \App\Support\WorkflowStage::MATERIAL_OPERATION)
            ->latest('entry_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 15), 100)));
    }

    public function start(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:loading,unloading'],
            'location' => ['nullable', 'string', 'max:150'],
            'material_details' => ['nullable', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity_unit' => ['nullable', 'string', 'max:20'],
            'assigned_staff' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->operations->start($movement, $data, $this->user());

        return $this->created(MovementPresenter::detail($movement->fresh(['company', 'material', 'operations.startedByUser', 'histories.user'])), 'Operation started');
    }

    public function update(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'location' => ['nullable', 'string', 'max:150'],
            'material_details' => ['nullable', 'string', 'max:1000'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'quantity_unit' => ['nullable', 'string', 'max:20'],
            'assigned_staff' => ['nullable', 'string', 'max:150'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $operation = $this->operations->update($movement, $data, $this->user());

        return $this->ok($operation, 'Operation updated');
    }

    public function complete(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->operations->complete($movement, $data['remarks'] ?? null, $this->user());

        return $this->ok(MovementPresenter::detail($movement->fresh(['company', 'material', 'operations.completedByUser', 'weighments', 'histories.user'])), 'Operation completed');
    }

    public function skip(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $movement = $this->operations->skip($movement, $this->user(), $data['reason']);

        return $this->ok(MovementPresenter::detail($movement), 'Operation skipped');
    }

    public function show(VehicleMovement $movement): JsonResponse
    {
        $this->authorizeMovement($movement);

        if (! $this->user()->can('operations.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view operations');
        }

        return $this->ok(MovementPresenter::detail($movement));
    }
}
