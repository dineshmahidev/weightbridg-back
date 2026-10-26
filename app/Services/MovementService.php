<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Company;
use App\Models\MovementHistory;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMovement;
use App\Support\MovementStatus;
use App\Support\Purposes;
use App\Support\WeightUnits;
use App\Support\WorkflowStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MovementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PushNotificationService $notifications,
    ) {
    }

    public function authorizeCompany(User $user, int $companyId): Company
    {
        $company = Company::query()->whereKey($companyId)->first();

        if (! $company) {
            throw ApiException::notFound('Company not found');
        }

        if (! $user->belongsToCompany($companyId)) {
            throw ApiException::forbidden('You are not authorized for this company');
        }

        return $company;
    }

    public function authorizeMovement(User $user, VehicleMovement $movement): VehicleMovement
    {
        if (! $user->belongsToCompany($movement->company_id)) {
            throw ApiException::forbidden('You are not authorized for this vehicle movement');
        }

        return $movement;
    }

    public function generateRef(): string
    {
        $prefix = config('weighbridge.movement_ref_prefix', 'MV');
        $date = now()->format('Ymd');

        do {
            $ref = sprintf('%s-%s-%s', $prefix, $date, Str::upper(Str::random(5)));
        } while (VehicleMovement::query()->where('movement_ref', $ref)->exists());

        return $ref;
    }

    public function createEntry(User $user, array $data, bool $overrideDuplicate = false): VehicleMovement
    {
        $company = $this->authorizeCompany($user, (int) $data['company_id']);

        if (! $user->can('entries.create')) {
            throw ApiException::forbidden('You are not allowed to create gate entries');
        }

        $vehicleNumber = strtoupper(trim($data['vehicle_number']));
        $cleanNumber = preg_replace('/[^A-Z0-9]/', '', $vehicleNumber);

        $active = VehicleMovement::query()
            ->where('company_id', $company->id)
            ->whereIn('status', MovementStatus::OPEN)
            ->where(function ($q) use ($vehicleNumber, $cleanNumber) {
                $q->where('vehicle_number', $vehicleNumber);
                if (! empty($cleanNumber)) {
                    $q->orWhereRaw("REPLACE(REPLACE(vehicle_number, ' ', ''), '-', '') = ?", [$cleanNumber]);
                }
            })
            ->first();

        if ($active && ! $overrideDuplicate) {
            throw ApiException::conflict(
                'This vehicle already has an active movement ('.$active->movement_ref.'). Complete or cancel it first.'
            );
        }

        if ($overrideDuplicate && ! $user->can('entries.override')) {
            throw ApiException::forbidden('You are not allowed to override duplicate entry warnings');
        }

        if (! empty($data['department_id'])) {
            $department = $company->departments()->whereKey($data['department_id'])->first();
            if (! $department) {
                throw ApiException::unprocessable('Department does not belong to the selected company');
            }
        }

        if (! empty($data['material_id'])) {
            $material = $company->materials()->whereKey($data['material_id'])->first();
            if (! $material) {
                throw ApiException::unprocessable('Material does not belong to the selected company');
            }
        }

        return DB::transaction(function () use ($user, $company, $data, $vehicleNumber) {
            $vehicle = Vehicle::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'registration_number' => $vehicleNumber,
                ],
                [
                    'vehicle_type' => $data['vehicle_type'] ?? null,
                    'owner_name' => $data['driver_name'] ?? null,
                    'owner_phone' => $data['driver_mobile'] ?? null,
                ]
            );

            $purpose = $data['purpose'] ?? Purposes::OTHER;
            $mode = $data['weighing_mode']
                ?? (in_array($purpose, [Purposes::LOADING, Purposes::RAW_MATERIAL_PURCHASE], true)
                    ? 'empty_first'
                    : 'loaded_first');

            $movement = VehicleMovement::query()->create([
                'movement_ref' => $this->generateRef(),
                'company_id' => $company->id,
                'department_id' => $data['department_id'] ?? null,
                'material_id' => $data['material_id'] ?? null,
                'vehicle_id' => $vehicle->id,
                'vehicle_number' => $vehicleNumber,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_mobile' => $data['driver_mobile'] ?? null,
                'driver_license_no' => $data['driver_license_no'] ?? null,
                'supplier_or_customer' => $data['supplier_or_customer'] ?? null,
                'purpose' => $purpose,
                'weighing_mode' => $mode,
                'status' => MovementStatus::ENTERED,
                'current_stage' => WorkflowStage::MAIN_GATE_ENTRY,
                'security_remarks' => $data['security_remarks'] ?? null,
                'entry_at' => now(),
                'stage_started_at' => now(),
                'entered_by' => $user->id,
                'weight_unit' => config('weighbridge.default_weight_unit', 'kg'),
            ]);

            $this->recordHistory($movement, WorkflowStage::MAIN_GATE_ENTRY, null, MovementStatus::ENTERED, $user, 'Vehicle entered main gate');

            $this->proceedFrom($movement, WorkflowStage::MAIN_GATE_ENTRY, $user);

            return $movement->refresh(['company', 'department', 'material', 'qualityInspection', 'weighments', 'operations', 'exitApproval', 'histories']);
        });
    }

    /**
     * Move the movement to the next enabled workflow stage after $fromStage.
     */
    public function proceedFrom(VehicleMovement $movement, string $fromStage, ?User $user = null, ?string $remarks = null, array $meta = []): VehicleMovement
    {
        $company = $movement->company()->first();
        $config = $company->workflow();

        $next = $this->nextEnabledStage($config, $fromStage);

        return $this->enterStage($movement, $next, $user, $remarks, $meta);
    }

    /**
     * @param  array<string, array{enabled: bool, required: bool}>  $config
     */
    public function nextEnabledStage(array $config, string $fromStage): string
    {
        $order = WorkflowStage::ORDER;
        $index = array_search($fromStage, $order, true);

        if ($index === false) {
            return WorkflowStage::COMPLETED;
        }

        foreach (array_slice($order, $index + 1) as $stage) {
            if ($stage === WorkflowStage::NET_WEIGHT_CALCULATION) {
                // Automatic server-side stage, always processed.
                return $stage;
            }

            if ($config[$stage]['enabled'] ?? false) {
                return $stage;
            }
        }

        return WorkflowStage::COMPLETED;
    }

    public function enterStage(
        VehicleMovement $movement,
        string $stage,
        ?User $user = null,
        ?string $remarks = null,
        array $meta = [],
    ): VehicleMovement {
        if ($stage === WorkflowStage::NET_WEIGHT_CALCULATION) {
            return $this->handleNetWeightCalculation($movement, $user);
        }

        if ($stage === WorkflowStage::COMPLETED) {
            return $this->markCompleted($movement, $user, $remarks);
        }

        $status = $this->defaultStatusForStage($movement, $stage);

        return $this->transition($movement, $stage, $status, $user, $remarks, $meta);
    }

    public function defaultStatusForStage(VehicleMovement $movement, string $stage): string
    {
        return match ($stage) {
            WorkflowStage::QUALITY_CHECK => MovementStatus::QUALITY_PENDING,
            WorkflowStage::FIRST_WEIGHMENT => MovementStatus::FIRST_WEIGHMENT_PENDING,
            WorkflowStage::MATERIAL_OPERATION => MovementStatus::MATERIAL_OPERATION_PENDING,
            WorkflowStage::SECOND_WEIGHMENT => MovementStatus::SECOND_WEIGHMENT_PENDING,
            WorkflowStage::FINAL_VERIFICATION => MovementStatus::WEIGHT_VERIFICATION_PENDING,
            WorkflowStage::EXIT_APPROVAL => MovementStatus::EXIT_APPROVAL_PENDING,
            WorkflowStage::SECURITY_GATE_EXIT => MovementStatus::READY_FOR_EXIT,
            default => MovementStatus::ENTERED,
        };
    }

    public function transition(
        VehicleMovement $movement,
        string $stage,
        string $status,
        ?User $user = null,
        ?string $remarks = null,
        array $meta = [],
        string $action = 'stage_changed',
    ): VehicleMovement {
        $from = $movement->status;

        $movement->forceFill([
            'current_stage' => $stage,
            'status' => $status,
            'stage_started_at' => now(),
            'stage_completed_at' => null,
        ])->save();

        $this->recordHistory($movement, $stage, $from, $status, $user, $remarks, $meta, $action);

        // Notify next stage responsible personnel (Role-targeted Push Notification)
        $this->notifications->notifyStageTransition($movement, $stage, $user);

        return $movement->refresh();
    }

    public function setStatus(
        VehicleMovement $movement,
        string $status,
        ?User $user = null,
        string $action = 'status_changed',
        ?string $remarks = null,
        array $meta = [],
    ): VehicleMovement {
        $from = $movement->status;

        $movement->forceFill(['status' => $status])->save();

        $this->recordHistory($movement, $movement->current_stage, $from, $status, $user, $remarks, $meta, $action);

        return $movement->refresh();
    }

    public function recordHistory(
        VehicleMovement $movement,
        string $stage,
        ?string $fromStatus,
        string $toStatus,
        ?User $user,
        ?string $remarks = null,
        ?array $meta = null,
        string $action = 'stage_changed',
    ): MovementHistory {
        return MovementHistory::query()->create([
            'vehicle_movement_id' => $movement->id,
            'stage' => $stage,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $user?->id,
            'action' => $action,
            'remarks' => $remarks,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }

    private function handleNetWeightCalculation(VehicleMovement $movement, ?User $user): VehicleMovement
    {
        $weightService = app(WeightService::class);
        $weightService->calculateNetWeight($movement);

        return $this->proceedFrom($movement, WorkflowStage::NET_WEIGHT_CALCULATION, $user, 'Net weight calculated');
    }

    public function cancel(VehicleMovement $movement, User $user, string $reason): VehicleMovement
    {
        if (! $user->belongsToCompany($movement->company_id) || ! $user->can('movements.cancel')) {
            throw ApiException::forbidden('You are not allowed to cancel movements');
        }

        if (! $movement->isOpen()) {
            throw ApiException::unprocessable('Only open movements can be cancelled');
        }

        $from = $movement->status;

        $movement->forceFill([
            'status' => MovementStatus::CANCELLED,
            'current_stage' => WorkflowStage::COMPLETED,
            'stage_completed_at' => now(),
        ])->save();

        $this->recordHistory($movement, WorkflowStage::COMPLETED, $from, MovementStatus::CANCELLED, $user, $reason, [], 'cancelled');

        $this->audit->log('movement.cancelled', $movement, ['status' => $from], ['status' => MovementStatus::CANCELLED, 'reason' => $reason], $movement->company_id);

        return $movement->refresh();
    }

    public function markCompleted(VehicleMovement $movement, ?User $user = null, ?string $remarks = null): VehicleMovement
    {
        $from = $movement->status;

        $movement->forceFill([
            'status' => MovementStatus::COMPLETED,
            'current_stage' => WorkflowStage::COMPLETED,
            'stage_completed_at' => now(),
        ])->save();

        $this->recordHistory($movement, WorkflowStage::COMPLETED, $from, MovementStatus::COMPLETED, $user, $remarks, [], 'completed');

        return $movement->refresh();
    }

    /**
     * Company exit rules check — returns list of missing requirements.
     *
     * @return array<int, string>
     */
    public function exitBlockers(VehicleMovement $movement): array
    {
        $company = $movement->company()->first();
        $config = $company->workflow();
        $blockers = [];

        if (($config[WorkflowStage::QUALITY_CHECK]['enabled'] ?? false)
            && ($config[WorkflowStage::QUALITY_CHECK]['required'] ?? false)
            && $movement->quality_result !== 'PASS') {
            $blockers[] = 'Quality inspection is not passed';
        }

        if (($config[WorkflowStage::FIRST_WEIGHMENT]['enabled'] ?? false)
            && ($config[WorkflowStage::FIRST_WEIGHMENT]['required'] ?? false)
            && ! $movement->empty_weight && ! $movement->loaded_weight) {
            $blockers[] = 'First weighment is missing';
        }

        if (($config[WorkflowStage::SECOND_WEIGHMENT]['enabled'] ?? false)
            && ($config[WorkflowStage::SECOND_WEIGHMENT]['required'] ?? false)
            && (! $movement->empty_weight || ! $movement->loaded_weight)) {
            $blockers[] = 'Both weighments are required before exit';
        }

        if (($config[WorkflowStage::FINAL_VERIFICATION]['enabled'] ?? false)
            && ($config[WorkflowStage::FINAL_VERIFICATION]['required'] ?? false)
            && ! $movement->weight_verified_at) {
            $blockers[] = 'Final weight verification is pending';
        }

        if (($config[WorkflowStage::EXIT_APPROVAL]['enabled'] ?? false)
            && ($config[WorkflowStage::EXIT_APPROVAL]['required'] ?? false)) {
            $decision = $movement->exitApproval?->decision;
            if ($decision !== 'approved') {
                $blockers[] = 'Exit approval is pending';
            }
        }

        return $blockers;
    }

    public function weightSummary(VehicleMovement $movement): array
    {
        $weighments = $movement->weighments()->with('operator')->get();

        $first = $weighments->firstWhere('sequence', 1);
        $second = $weighments->firstWhere('sequence', 2);

        $empty = $weighments->firstWhere('weight_type', 'empty');
        $loaded = $weighments->firstWhere('weight_type', 'loaded');

        return [
            'first_weighment' => $first,
            'second_weighment' => $second,
            'empty_weighment' => $empty,
            'loaded_weighment' => $loaded,
            'empty_weight' => $movement->empty_weight,
            'loaded_weight' => $movement->loaded_weight,
            'net_weight' => $movement->net_weight,
            'weight_unit' => $movement->weight_unit,
            'verified_at' => $movement->weight_verified_at,
            'verified_by' => $movement->verifiedBy,
        ];
    }

    public static function supportsPurpose(string $purpose): bool
    {
        return array_key_exists($purpose, Purposes::all());
    }

    public static function supportedUnits(): array
    {
        return WeightUnits::all();
    }
}
