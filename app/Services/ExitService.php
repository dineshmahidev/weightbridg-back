<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\ExitApproval;
use App\Models\User;
use App\Models\VehicleMovement;
use App\Support\MovementStatus;
use App\Support\WorkflowStage;
use Illuminate\Support\Facades\DB;

class ExitService
{
    public function __construct(private readonly MovementService $movements, private readonly AuditLogger $audit)
    {
    }

    public function requestApproval(VehicleMovement $movement, User $user, ?string $remarks = null): ExitApproval
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('exits.view') && ! $user->can('movements.view')) {
            throw ApiException::forbidden('You are not allowed to request exit approval');
        }

        if (! in_array($movement->status, [MovementStatus::WEIGHT_VERIFIED, MovementStatus::EXIT_REJECTED], true)
            && $movement->current_stage !== WorkflowStage::EXIT_APPROVAL) {
            throw ApiException::unprocessable('Movement is not ready for exit approval request');
        }

        $approval = ExitApproval::query()->firstOrNew(['vehicle_movement_id' => $movement->id]);

        $approval->forceFill([
            'decision' => 'pending',
            'requested_by' => $user->id,
            'requested_at' => now(),
            'approver_id' => null,
            'decided_at' => null,
            'remarks' => $remarks ?: $approval->remarks,
        ])->save();

        $this->movements->setStatus($movement, MovementStatus::EXIT_APPROVAL_PENDING, $user, 'exit_requested', $remarks);

        $this->audit->log('exit.requested', $movement, null, ['remarks' => $remarks], $movement->company_id);

        return $approval->refresh(['requester']);
    }

    public function decide(VehicleMovement $movement, User $user, bool $approve, ?string $remarks = null): ExitApproval
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('exits.approve')) {
            throw ApiException::forbidden('You are not allowed to approve or reject exits');
        }

        if (! in_array($movement->status, [MovementStatus::EXIT_APPROVAL_PENDING, MovementStatus::EXIT_REJECTED, MovementStatus::WEIGHT_VERIFIED], true)
            && $movement->current_stage !== WorkflowStage::EXIT_APPROVAL) {
            throw ApiException::unprocessable('Movement is not awaiting exit approval');
        }

        return DB::transaction(function () use ($movement, $user, $approve, $remarks) {
            $approval = ExitApproval::query()->firstOrNew(['vehicle_movement_id' => $movement->id]);

            $approval->forceFill([
                'decision' => $approve ? 'approved' : 'rejected',
                'requested_by' => $approval->requested_by ?: $user->id,
                'requested_at' => $approval->requested_at ?: now(),
                'approver_id' => $user->id,
                'decided_at' => now(),
                'remarks' => $remarks ?: $approval->remarks,
            ])->save();

            if ($approve) {
                $this->movements->setStatus($movement, MovementStatus::EXIT_APPROVED, $user, 'exit_approved', $remarks);
                $this->movements->proceedFrom($movement->fresh(), WorkflowStage::EXIT_APPROVAL, $user, $remarks);
            } else {
                $this->movements->setStatus($movement, MovementStatus::EXIT_REJECTED, $user, 'exit_rejected', $remarks);
            }

            $this->audit->log($approve ? 'exit.approved' : 'exit.rejected', $movement, null, ['remarks' => $remarks], $movement->company_id);

            return $approval->refresh(['requester', 'approver']);
        });
    }

    public function completeExit(VehicleMovement $movement, User $user, array $data = [], bool $force = false): VehicleMovement
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('exits.complete')) {
            throw ApiException::forbidden('You are not allowed to complete gate exit');
        }

        $openStatuses = [
            MovementStatus::READY_FOR_EXIT,
            MovementStatus::EXIT_APPROVED,
            MovementStatus::WEIGHT_VERIFIED,
        ];

        if (! in_array($movement->status, $openStatuses, true)) {
            throw ApiException::unprocessable('Movement is not ready for gate exit (current: '.$movement->status.')');
        }

        $blockers = $this->movements->exitBlockers($movement);

        if ($blockers && (! $force || ! $user->can('exits.override'))) {
            throw ApiException::unprocessable('Exit blocked: '.implode('; ', $blockers));
        }

        return DB::transaction(function () use ($movement, $user, $data, $force, $blockers) {
            $from = $movement->status;

            $movement->forceFill([
                'status' => MovementStatus::COMPLETED,
                'current_stage' => WorkflowStage::COMPLETED,
                'exit_at' => now(),
                'exit_by' => $user->id,
                'stage_completed_at' => now(),
                'security_remarks' => $data['remarks'] ?? $movement->security_remarks,
            ])->save();

            $this->movements->recordHistory(
                $movement,
                WorkflowStage::SECURITY_GATE_EXIT,
                $from,
                MovementStatus::COMPLETED,
                $user,
                $data['remarks'] ?? null,
                $force && $blockers ? ['forced' => true, 'blockers' => $blockers] : null,
                'security_exit'
            );

            $this->audit->log('exit.completed', $movement, ['status' => $from], [
                'status' => MovementStatus::COMPLETED,
                'forced' => (bool) $force,
                'remarks' => $data['remarks'] ?? null,
            ], $movement->company_id);

            return $movement->refresh(['company', 'material', 'qualityInspection', 'weighments', 'operations', 'exitApproval', 'histories']);
        });
    }
}
