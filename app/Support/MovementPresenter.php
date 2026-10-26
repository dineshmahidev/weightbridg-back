<?php

namespace App\Support;

use App\Models\VehicleMovement;

class MovementPresenter
{
    public static function list(VehicleMovement $movement): array
    {
        $company = $movement->relationLoaded('company') && $movement->company
            ? ['id' => $movement->company->id, 'name' => $movement->company->name]
            : null;

        $department = $movement->relationLoaded('department') && $movement->department
            ? ['id' => $movement->department->id, 'name' => $movement->department->name]
            : null;

        $material = $movement->relationLoaded('material') && $movement->material
            ? ['id' => $movement->material->id, 'name' => $movement->material->name, 'unit' => $movement->material->unit]
            : null;

        $emptyWeight = $movement->empty_weight !== null ? (float) $movement->empty_weight : null;
        $loadedWeight = $movement->loaded_weight !== null ? (float) $movement->loaded_weight : null;
        $netWeight = $movement->net_weight !== null ? (float) $movement->net_weight : null;
        $weightUnit = $movement->weight_unit;

        if ($movement->relationLoaded('weighments')) {
            if ($emptyWeight === null) {
                $emptyW = $movement->weighments->firstWhere('weight_type', 'empty');
                if ($emptyW) {
                    $emptyWeight = (float) $emptyW->weight;
                    $weightUnit = $weightUnit ?: $emptyW->unit;
                }
            }
            if ($loadedWeight === null) {
                $loadedW = $movement->weighments->firstWhere('weight_type', 'loaded');
                if ($loadedW) {
                    $loadedWeight = (float) $loadedW->weight;
                    $weightUnit = $weightUnit ?: $loadedW->unit;
                }
            }
            if ($netWeight === null && $emptyWeight !== null && $loadedWeight !== null) {
                $netWeight = round($loadedWeight - $emptyWeight, 3);
            }
        }

        return [
            'id' => $movement->id,
            'movement_ref' => $movement->movement_ref,
            'vehicle_number' => $movement->vehicle_number,
            'driver_name' => $movement->driver_name,
            'driver_mobile' => $movement->driver_mobile,
            'company_id' => $movement->company_id,
            'company' => $company,
            'department_id' => $movement->department_id,
            'department' => $department,
            'material_id' => $movement->material_id,
            'material' => $material,
            'purpose' => $movement->purpose,
            'purpose_label' => Purposes::label($movement->purpose),
            'weighing_mode' => $movement->weighing_mode,
            'status' => $movement->status,
            'status_label' => MovementStatus::label($movement->status),
            'current_stage' => $movement->current_stage,
            'current_stage_label' => WorkflowStage::label($movement->current_stage),
            'quality_result' => $movement->quality_result,
            'empty_weight' => $emptyWeight,
            'loaded_weight' => $loadedWeight,
            'net_weight' => $netWeight,
            'weight_unit' => $weightUnit ?? 'kg',
            'entry_at' => $movement->entry_at?->toIso8601String(),
            'exit_at' => $movement->exit_at?->toIso8601String(),
            'is_open' => $movement->isOpen(),
        ];
    }

    public static function detail(VehicleMovement $movement): array
    {
        $movement->loadMissing([
            'company', 'department', 'material', 'vehicle', 'enteredBy', 'exitedBy', 'verifiedBy',
            'qualityInspection.inspector', 'weighments.operator', 'weighments.correctedBy',
            'operations.startedByUser', 'operations.completedByUser', 'exitApproval.requester', 'exitApproval.approver',
            'histories.user',
        ]);

        $payload = self::list($movement);

        $payload['supplier_or_customer'] = $movement->supplier_or_customer;
        $payload['driver_license_no'] = $movement->driver_license_no;
        $payload['security_remarks'] = $movement->security_remarks;
        $payload['entered_by'] = $movement->enteredBy?->only(['id', 'name', 'email']);
        $payload['exited_by'] = $movement->exitedBy?->only(['id', 'name', 'email']);
        $payload['verified_by'] = $movement->verifiedBy?->only(['id', 'name', 'email']);
        $payload['weight_verified_at'] = $movement->weight_verified_at?->toIso8601String();
        $payload['stage_started_at'] = $movement->stage_started_at?->toIso8601String();
        $payload['stage_completed_at'] = $movement->stage_completed_at?->toIso8601String();

        $payload['quality_inspection'] = $movement->qualityInspection ? [
            'id' => $movement->qualityInspection->id,
            'result' => $movement->qualityInspection->result,
            'remarks' => $movement->qualityInspection->remarks,
            'rejection_reason' => $movement->qualityInspection->rejection_reason,
            'parameter_values' => $movement->qualityInspection->parameter_values,
            'inspector' => $movement->qualityInspection->inspector?->only(['id', 'name']),
            'inspected_at' => $movement->qualityInspection->inspected_at?->toIso8601String(),
        ] : null;

        $payload['weighments'] = $movement->weighments->map(fn ($w) => [
            'id' => $w->id,
            'sequence' => $w->sequence,
            'weight_type' => $w->weight_type,
            'weight' => (float) $w->weight,
            'unit' => $w->unit,
            'weight_in_kg' => (float) $w->weight_in_kg,
            'weighbridge_ref' => $w->weighbridge_ref,
            'operator' => $w->operator?->only(['id', 'name']),
            'weighed_at' => $w->weighed_at?->toIso8601String(),
            'remarks' => $w->remarks,
            'is_correction' => (bool) $w->is_correction,
            'corrected_by' => $w->correctedBy?->only(['id', 'name']),
        ])->values();

        $payload['operations'] = $movement->operations->map(fn ($op) => [
            'id' => $op->id,
            'type' => $op->type,
            'location' => $op->location,
            'material_details' => $op->material_details,
            'quantity' => $op->quantity !== null ? (float) $op->quantity : null,
            'quantity_unit' => $op->quantity_unit,
            'assigned_staff' => $op->assigned_staff,
            'status' => $op->status,
            'started_at' => $op->started_at?->toIso8601String(),
            'completed_at' => $op->completed_at?->toIso8601String(),
            'remarks' => $op->remarks,
            'started_by' => $op->startedByUser?->only(['id', 'name']),
            'completed_by' => $op->completedByUser?->only(['id', 'name']),
        ])->values();

        $payload['exit_approval'] = $movement->exitApproval ? [
            'id' => $movement->exitApproval->id,
            'decision' => $movement->exitApproval->decision,
            'requested_by' => $movement->exitApproval->requester?->only(['id', 'name']),
            'requested_at' => $movement->exitApproval->requested_at?->toIso8601String(),
            'approver' => $movement->exitApproval->approver?->only(['id', 'name']),
            'decided_at' => $movement->exitApproval->decided_at?->toIso8601String(),
            'remarks' => $movement->exitApproval->remarks,
        ] : null;

        $payload['timeline'] = $movement->histories->map(fn ($h) => [
            'id' => $h->id,
            'stage' => $h->stage,
            'stage_label' => WorkflowStage::label($h->stage),
            'from_status' => $h->from_status,
            'to_status' => $h->to_status,
            'to_status_label' => MovementStatus::label($h->to_status),
            'action' => $h->action,
            'remarks' => $h->remarks,
            'meta' => $h->meta,
            'user' => $h->user?->only(['id', 'name']),
            'occurred_at' => $h->occurred_at?->toIso8601String(),
        ])->values();

        $payload['workflow'] = WorkflowStage::config($movement->company?->workflow_config);

        $payload['exit_blockers'] = app(\App\Services\MovementService::class)->exitBlockers($movement);

        return $payload;
    }
}
