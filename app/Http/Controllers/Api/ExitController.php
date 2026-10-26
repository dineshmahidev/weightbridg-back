<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\VehicleMovement;
use App\Services\ExitService;
use App\Services\MovementService;
use App\Support\MovementPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExitController extends ApiController
{
    public function __construct(
        private readonly ExitService $exits,
        private readonly MovementService $movements,
    ) {
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('exits.view') && ! $user->can('exits.complete')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view exit requests');
        }

        $query = VehicleMovement::query()
            ->with(['company:id,name', 'material:id,name', 'exitApproval', 'qualityInspection'])
            ->whereIn('status', array_merge(
                \App\Support\MovementStatus::PENDING_EXIT,
                [\App\Support\MovementStatus::WEIGHT_VERIFIED],
            ))
            ->latest('entry_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        if ($request->filled('decision')) {
            $query->whereHas('exitApproval', fn ($q) => $q->where('decision', $request->query('decision')));
        }

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 15), 100)));
    }

    public function requestApproval(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->exits->requestApproval($movement, $this->user(), $data['remarks'] ?? null);

        return $this->ok(MovementPresenter::detail($movement->fresh(['company', 'material', 'exitApproval.requester', 'histories.user'])), 'Exit approval requested');
    }

    public function decide(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->exits->decide($movement, $this->user(), $data['decision'] === 'approve', $data['remarks'] ?? null);

        return $this->ok(
            MovementPresenter::detail($movement->fresh(['company', 'material', 'exitApproval.approver', 'histories.user'])),
            $data['decision'] === 'approve' ? 'Exit approved' : 'Exit rejected'
        );
    }

    public function complete(Request $request, VehicleMovement $movement): JsonResponse
    {
        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $movement = $this->exits->completeExit($movement, $this->user(), $data, (bool) ($data['force'] ?? false));

        return $this->ok(MovementPresenter::detail($movement), 'Vehicle exited. Movement completed.');
    }

    public function blockers(VehicleMovement $movement): JsonResponse
    {
        $this->authorizeMovement($movement);

        return $this->ok([
            'blockers' => $this->movements->exitBlockers($movement),
            'workflow' => $movement->company()->first()->workflow(),
        ]);
    }
}
