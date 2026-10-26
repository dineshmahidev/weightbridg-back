<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\VehicleMovement;
use App\Services\MovementService;
use App\Support\MovementPresenter;
use App\Support\MovementStatus;
use App\Support\Purposes;
use App\Support\WeightUnits;
use App\Support\WorkflowStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MovementController extends ApiController
{
    public function __construct(private readonly MovementService $movements)
    {
    }

    public function reference(): JsonResponse
    {
        return $this->ok([
            'purposes' => collect(Purposes::all())->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'weight_units' => WeightUnits::all(),
            'weighing_modes' => [
                ['value' => 'empty_first', 'label' => 'Empty vehicle arrives first'],
                ['value' => 'loaded_first', 'label' => 'Loaded vehicle arrives first'],
            ],
            'statuses' => collect(MovementStatus::ALL)->map(fn ($s) => ['value' => $s, 'label' => MovementStatus::label($s)])->values(),
            'stages' => collect(WorkflowStage::ORDER)->map(fn ($s) => ['value' => $s, 'label' => WorkflowStage::label($s)])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'material_id' => ['nullable', 'exists:materials,id'],
            'vehicle_number' => ['required', 'string', 'max:30'],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'driver_mobile' => ['nullable', 'string', 'max:30'],
            'driver_license_no' => ['nullable', 'string', 'max:40'],
            'supplier_or_customer' => ['nullable', 'string', 'max:150'],
            'purpose' => ['required', 'in:'.implode(',', array_keys(Purposes::all()))],
            'weighing_mode' => ['nullable', 'in:empty_first,loaded_first'],
            'security_remarks' => ['nullable', 'string', 'max:1000'],
            'override_duplicate' => ['sometimes', 'boolean'],
        ]);

        $movement = $this->movements->createEntry($this->user(), $data, (bool) ($data['override_duplicate'] ?? false));

        $this->audit('movement.created', $movement, null, [
            'movement_ref' => $movement->movement_ref,
            'vehicle_number' => $movement->vehicle_number,
        ], $movement->company_id);

        return $this->created(MovementPresenter::detail($movement), 'Vehicle entry recorded. Movement reference: '.$movement->movement_ref);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('movements.view') && ! $user->can('entries.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view vehicle movements');
        }

        $query = VehicleMovement::query()
            ->with(['company:id,name,code', 'department:id,name', 'material:id,name,unit'])
            ->latest('entry_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        if ($request->filled('company_id') && ! $user->isSuperAdmin()) {
            $this->authorizeCompany((int) $request->query('company_id'));
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->query('department_id'));
        }

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->query('material_id'));
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(fn ($q) => $q
                ->where('vehicle_number', 'like', "%{$search}%")
                ->orWhere('movement_ref', 'like', "%{$search}%")
                ->orWhere('driver_name', 'like', "%{$search}%")
                ->orWhere('driver_mobile', 'like', "%{$search}%"));
        }

        if ($request->filled('status')) {
            $statuses = explode(',', (string) $request->query('status'));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('current_stage')) {
            $query->where('current_stage', $request->query('current_stage'));
        }

        if ($request->filled('quality_result')) {
            $query->where('quality_result', $request->query('quality_result'));
        }

        if ($request->query('entry_status') === 'inside') {
            $query->whereIn('status', MovementStatus::OPEN);
        } elseif ($request->query('entry_status') === 'exited') {
            $query->where('status', MovementStatus::COMPLETED);
        }

        if ($request->filled('date_from')) {
            $query->where('entry_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('entry_at', '<=', $request->query('date_to').' 23:59:59');
        }

        $paginator = $query->paginate(min((int) $request->query('per_page', 15), 100));

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => collect($paginator->items())->map(fn (VehicleMovement $m) => MovementPresenter::list($m)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(VehicleMovement $movement): JsonResponse
    {
        $this->authorizeMovement($movement);

        if (! $this->user()->can('movements.view') && ! $this->user()->can('entries.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view vehicle movements');
        }

        return $this->ok(MovementPresenter::detail($movement));
    }

    public function history(VehicleMovement $movement): JsonResponse
    {
        $this->authorizeMovement($movement);

        return $this->ok(MovementPresenter::detail($movement)['timeline']);
    }

    public function cancel(Request $request, VehicleMovement $movement): JsonResponse
    {
        $this->authorizeMovement($movement);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $movement = $this->movements->cancel($movement, $this->user(), $data['reason']);

        return $this->ok(MovementPresenter::detail($movement), 'Movement cancelled');
    }

    public function duplicateWarning(Request $request): JsonResponse
    {
        $user = $this->user();

        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'vehicle_number' => ['required', 'string', 'max:30'],
        ]);

        $this->authorizeCompany((int) $data['company_id']);

        $vehicleNumber = strtoupper(trim($data['vehicle_number']));
        $cleanNumber = preg_replace('/[^A-Z0-9]/', '', $vehicleNumber);

        $active = VehicleMovement::query()
            ->with('company:id,name')
            ->where('company_id', $data['company_id'])
            ->whereIn('status', MovementStatus::OPEN)
            ->where(function ($q) use ($vehicleNumber, $cleanNumber) {
                $q->where('vehicle_number', $vehicleNumber);
                if (! empty($cleanNumber)) {
                    $q->orWhereRaw("REPLACE(REPLACE(vehicle_number, ' ', ''), '-', '') = ?", [$cleanNumber]);
                }
            })
            ->first();

        return $this->ok([
            'has_duplicate' => (bool) $active,
            'movement' => $active ? MovementPresenter::list($active) : null,
        ]);
    }

    public function vehicleHistory(string $vehicleNumber): JsonResponse
    {
        $user = $this->user();

        $query = VehicleMovement::query()
            ->with(['company:id,name', 'material:id,name'])
            ->where('vehicle_number', strtoupper(trim($vehicleNumber)))
            ->latest('entry_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        }

        return $this->paginated($query->paginate(20));
    }
}
