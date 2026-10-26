<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\MaterialOperation;
use App\Models\User;
use App\Models\VehicleMovement;
use App\Support\MovementStatus;
use App\Support\WorkflowStage;
use Illuminate\Support\Facades\DB;

class OperationService
{
    public const TYPE_LOADING = 'loading';
    public const TYPE_UNLOADING = 'unloading';

    public function __construct(private readonly MovementService $movements, private readonly AuditLogger $audit)
    {
    }

    public function start(VehicleMovement $movement, array $data, User $user): MaterialOperation
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('operations.create')) {
            throw ApiException::forbidden('You are not allowed to start loading/unloading operations');
        }

        if ($movement->current_stage !== WorkflowStage::MATERIAL_OPERATION) {
            throw ApiException::unprocessable('This movement is not at the loading/unloading stage');
        }

        $type = $data['type'] ?? null;

        if (! in_array($type, [self::TYPE_LOADING, self::TYPE_UNLOADING], true)) {
            throw ApiException::unprocessable('Operation type must be "loading" or "unloading"');
        }

        $existing = $movement->operations()->where('status', '!=', 'cancelled')->first();

        if ($existing) {
            throw ApiException::conflict('An operation is already in progress for this movement');
        }

        return DB::transaction(function () use ($movement, $data, $user, $type) {
            $operation = MaterialOperation::query()->create([
                'vehicle_movement_id' => $movement->id,
                'type' => $type,
                'location' => $data['location'] ?? null,
                'material_details' => $data['material_details'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'quantity_unit' => $data['quantity_unit'] ?? null,
                'assigned_staff' => $data['assigned_staff'] ?? null,
                'started_at' => now(),
                'status' => 'in_progress',
                'remarks' => $data['remarks'] ?? null,
                'started_by' => $user->id,
            ]);

            $status = $type === self::TYPE_LOADING
                ? MovementStatus::LOADING_IN_PROGRESS
                : MovementStatus::UNLOADING_IN_PROGRESS;

            $this->movements->setStatus($movement, $status, $user, $type.'_started', $data['remarks'] ?? null);

            $this->audit->log('operation.started', $movement, null, [
                'type' => $type,
                'location' => $data['location'] ?? null,
                'quantity' => $data['quantity'] ?? null,
            ], $movement->company_id);

            return $operation->load(['startedByUser']);
        });
    }

    public function update(VehicleMovement $movement, array $data, User $user): MaterialOperation
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('operations.update') && ! $user->can('operations.create')) {
            throw ApiException::forbidden('You are not allowed to update loading/unloading operations');
        }

        $operation = $movement->operations()->where('status', 'in_progress')->first();

        if (! $operation) {
            throw ApiException::notFound('No operation in progress for this movement');
        }

        $operation->forceFill([
            'location' => $data['location'] ?? $operation->location,
            'material_details' => $data['material_details'] ?? $operation->material_details,
            'quantity' => $data['quantity'] ?? $operation->quantity,
            'quantity_unit' => $data['quantity_unit'] ?? $operation->quantity_unit,
            'assigned_staff' => $data['assigned_staff'] ?? $operation->assigned_staff,
            'remarks' => $data['remarks'] ?? $operation->remarks,
        ])->save();

        return $operation->refresh(['startedByUser']);
    }

    public function complete(VehicleMovement $movement, ?string $remarks, User $user): MaterialOperation
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('operations.update')) {
            throw ApiException::forbidden('You are not allowed to complete loading/unloading operations');
        }

        $operation = $movement->operations()->where('status', 'in_progress')->first();

        if (! $operation) {
            throw ApiException::notFound('No operation in progress for this movement');
        }

        return DB::transaction(function () use ($movement, $operation, $remarks, $user) {
            $operation->forceFill([
                'completed_at' => now(),
                'status' => 'completed',
                'remarks' => $remarks ?: $operation->remarks,
                'completed_by' => $user->id,
            ])->save();

            $this->movements->setStatus(
                $movement->fresh(),
                $operation->type.'_completed',
                $user,
                $operation->type.'_completed',
                $remarks
            );

            $this->audit->log('operation.completed', $movement, null, [
                'type' => $operation->type,
                'quantity' => $operation->quantity,
            ], $movement->company_id);

            $this->movements->proceedFrom($movement->fresh(), WorkflowStage::MATERIAL_OPERATION, $user, $remarks);

            return $operation->refresh(['startedByUser', 'completedByUser']);
        });
    }

    public function skip(VehicleMovement $movement, User $user, ?string $reason): VehicleMovement
    {
        $this->movements->authorizeMovement($user, $movement);

        $company = $movement->company()->first();

        if ($company->isStageRequired(WorkflowStage::MATERIAL_OPERATION) && ! $user->can('movements.cancel')) {
            throw ApiException::forbidden('Loading/unloading is mandatory for this company');
        }

        if (! $user->can('operations.update') && ! $user->can('weighments.create')) {
            throw ApiException::forbidden('You are not allowed to skip loading/unloading');
        }

        if ($movement->current_stage !== WorkflowStage::MATERIAL_OPERATION) {
            throw ApiException::unprocessable('This movement is not at the loading/unloading stage');
        }

        $this->movements->recordHistory(
            $movement,
            WorkflowStage::MATERIAL_OPERATION,
            $movement->status,
            $movement->status,
            $user,
            $reason ?: 'Operation skipped',
            [],
            'operation_skipped'
        );

        $this->audit->log('operation.skipped', $movement, null, ['reason' => $reason], $movement->company_id);

        return $this->movements->proceedFrom($movement, WorkflowStage::MATERIAL_OPERATION, $user, $reason);
    }
}
