<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\VehicleMovement;
use App\Models\Weighment;
use App\Support\MovementStatus;
use App\Support\WeightUnits;
use App\Support\WorkflowStage;
use Illuminate\Support\Facades\DB;

class WeightService
{
    public const TYPE_EMPTY = 'empty';
    public const TYPE_LOADED = 'loaded';

    public function __construct(private readonly MovementService $movements, private readonly AuditLogger $audit)
    {
    }

    public function recordWeighment(VehicleMovement $movement, int $sequence, array $data, User $user): Weighment
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('weighments.create')) {
            throw ApiException::forbidden('You are not allowed to record weighments');
        }

        if (! in_array($sequence, [1, 2], true)) {
            throw ApiException::unprocessable('Weighment sequence must be 1 or 2');
        }

        $requiredStage = $sequence === 1 ? WorkflowStage::FIRST_WEIGHMENT : WorkflowStage::SECOND_WEIGHMENT;

        if ($movement->current_stage !== $requiredStage) {
            throw ApiException::unprocessable(
                'This movement is not at the '.str_replace('_', ' ', $requiredStage).' stage (current: '.$movement->status.')'
            );
        }

        $unit = WeightUnits::normalize($data['unit'] ?? 'kg');

        if (! WeightUnits::isSupported($unit)) {
            throw ApiException::unprocessable('Unsupported weight unit');
        }

        $weight = round((float) $data['weight'], 3);

        if ($weight <= 0) {
            throw ApiException::unprocessable('Weight must be greater than zero');
        }

        $weightType = $data['weight_type'] ?? null;

        if (! in_array($weightType, [self::TYPE_EMPTY, self::TYPE_LOADED], true)) {
            throw ApiException::unprocessable('Weight type must be "empty" or "loaded"');
        }

        $mode = $movement->weighing_mode;

        // Mode A: empty arrives first. Mode B: loaded arrives first.
        $expectedType = $sequence === 1
            ? ($mode === 'empty_first' ? self::TYPE_EMPTY : self::TYPE_LOADED)
            : ($mode === 'empty_first' ? self::TYPE_LOADED : self::TYPE_EMPTY);

        if ($weightType !== $expectedType) {
            throw ApiException::unprocessable(
                'For weighing mode "'.$mode.'", sequence '.$sequence.' must be "'.$expectedType.'" weight'
            );
        }

        $existing = Weighment::query()
            ->where('vehicle_movement_id', $movement->id)
            ->where('sequence', $sequence)
            ->first();

        if ($existing) {
            throw ApiException::conflict('Sequence '.$sequence.' weighment already exists for this movement');
        }

        $conflictingType = Weighment::query()
            ->where('vehicle_movement_id', $movement->id)
            ->where('weight_type', $weightType)
            ->exists();

        if ($conflictingType) {
            throw ApiException::conflict('A "'.$weightType.'" weighment is already recorded for this movement');
        }

        return DB::transaction(function () use ($movement, $sequence, $data, $user, $weight, $unit, $weightType) {
            $weighment = Weighment::query()->create([
                'vehicle_movement_id' => $movement->id,
                'sequence' => $sequence,
                'weight_type' => $weightType,
                'weight' => $weight,
                'unit' => $unit,
                'weight_in_kg' => WeightUnits::toKg($weight, $unit),
                'weighbridge_ref' => $data['weighbridge_ref'] ?? null,
                'operator_id' => $user->id,
                'weighed_at' => now(),
                'remarks' => $data['remarks'] ?? null,
            ]);

            // Immediately update empty_weight or loaded_weight on movement so it appears in summary
            if ($weightType === self::TYPE_EMPTY) {
                $movement->forceFill([
                    'empty_weight' => $weight,
                    'weight_unit' => $movement->weight_unit ?: $unit,
                ])->saveQuietly();
            } elseif ($weightType === self::TYPE_LOADED) {
                $movement->forceFill([
                    'loaded_weight' => $weight,
                    'weight_unit' => $movement->weight_unit ?: $unit,
                ])->saveQuietly();
            }

            $this->audit->log('weighment.recorded', $movement, null, [
                'sequence' => $sequence,
                'weight_type' => $weightType,
                'weight' => $weight,
                'unit' => $unit,
            ], $movement->company_id);

            $this->movements->recordHistory(
                $movement,
                $movement->current_stage,
                $movement->status,
                $movement->status,
                $user,
                $data['remarks'] ?? null,
                ['weighment_id' => $weighment->id, 'weight' => $weight, 'unit' => $unit, 'weight_type' => $weightType],
                $sequence === 1 ? 'first_weighment_recorded' : 'second_weighment_recorded'
            );

            if ($sequence === 1) {
                $this->movements->transition(
                    $movement,
                    WorkflowStage::FIRST_WEIGHMENT,
                    MovementStatus::FIRST_WEIGHMENT_COMPLETED,
                    $user,
                    null,
                    [],
                    'first_weighment_completed'
                );

                $this->movements->proceedFrom($movement, WorkflowStage::FIRST_WEIGHMENT, $user);
            } else {
                $this->movements->transition(
                    $movement,
                    WorkflowStage::SECOND_WEIGHMENT,
                    MovementStatus::SECOND_WEIGHMENT_COMPLETED,
                    $user,
                    null,
                    [],
                    'second_weighment_completed'
                );

                // Net weight calculation happens automatically inside proceedFrom.
                $this->movements->proceedFrom($movement, WorkflowStage::SECOND_WEIGHMENT, $user);
            }

            return $weighment->refresh(['operator']);
        });
    }

    public function calculateNetWeight(VehicleMovement $movement): VehicleMovement
    {
        $weighments = $movement->weighments()->get();

        $empty = $weighments->firstWhere('weight_type', self::TYPE_EMPTY);
        $loaded = $weighments->firstWhere('weight_type', self::TYPE_LOADED);

        if (! $empty || ! $loaded) {
            throw ApiException::unprocessable('Both empty and loaded weighments are required to calculate net weight');
        }

        $netKg = round($loaded->weight_in_kg - $empty->weight_in_kg, 3);

        if ($netKg < 0) {
            throw ApiException::unprocessable('Loaded weight cannot be less than empty weight. Please correct the weighments.');
        }

        // Use the unit of the first weighment as the movement's display unit.
        $first = $weighments->firstWhere('sequence', 1) ?? $empty;
        $unit = WeightUnits::normalize($first->unit);

        $movement->forceFill([
            'empty_weight' => WeightUnits::fromKg($empty->weight_in_kg, $unit),
            'loaded_weight' => WeightUnits::fromKg($loaded->weight_in_kg, $unit),
            'net_weight' => WeightUnits::fromKg($netKg, $unit),
            'weight_unit' => $unit,
        ])->save();

        $this->audit->log('weight.net_calculated', $movement, null, [
            'empty_weight' => $movement->empty_weight,
            'loaded_weight' => $movement->loaded_weight,
            'net_weight' => $movement->net_weight,
            'unit' => $unit,
        ], $movement->company_id);

        return $movement->refresh();
    }

    public function correctWeighment(Weighment $weighment, array $data, User $user): Weighment
    {
        $movement = $weighment->movement;

        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('weighments.correct')) {
            throw ApiException::forbidden('You are not allowed to correct weighments');
        }

        $unit = WeightUnits::normalize($data['unit'] ?? $weighment->unit);
        $weight = round((float) ($data['weight'] ?? $weighment->weight), 3);

        if ($weight <= 0) {
            throw ApiException::unprocessable('Weight must be greater than zero');
        }

        $old = [
            'weight' => (float) $weighment->weight,
            'unit' => $weighment->unit,
            'weighbridge_ref' => $weighment->weighbridge_ref,
            'remarks' => $weighment->remarks,
        ];

        return DB::transaction(function () use ($weighment, $data, $user, $unit, $weight, $old, $movement) {
            $weighment->forceFill([
                'weight' => $weight,
                'unit' => $unit,
                'weight_in_kg' => WeightUnits::toKg($weight, $unit),
                'weighbridge_ref' => $data['weighbridge_ref'] ?? $weighment->weighbridge_ref,
                'remarks' => $data['remarks'] ?? $weighment->remarks,
                'is_correction' => true,
                'corrected_by' => $user->id,
            ])->save();

            // Recalculate cached weights if both weighments exist, otherwise update single weighment
            $both = $movement->weighments()->count() >= 2;

            if ($both) {
                $this->calculateNetWeight($movement->fresh());
            } else {
                if ($weighment->weight_type === self::TYPE_EMPTY) {
                    $movement->forceFill([
                        'empty_weight' => $weight,
                        'weight_unit' => $movement->weight_unit ?: $unit,
                    ])->saveQuietly();
                } elseif ($weighment->weight_type === self::TYPE_LOADED) {
                    $movement->forceFill([
                        'loaded_weight' => $weight,
                        'weight_unit' => $movement->weight_unit ?: $unit,
                    ])->saveQuietly();
                }
            }

            $this->audit->log('weighment.corrected', $movement, $old, [
                'weight' => $weight,
                'unit' => $unit,
                'remarks' => $data['remarks'] ?? null,
            ], $movement->company_id);

            $this->movements->recordHistory(
                $movement,
                $movement->current_stage,
                $movement->status,
                $movement->status,
                $user,
                $data['remarks'] ?? 'Weighment corrected',
                ['weighment_id' => $weighment->id, 'old' => $old, 'new' => ['weight' => $weight, 'unit' => $unit]],
                'weighment_corrected'
            );

            return $weighment->refresh(['operator', 'correctedBy']);
        });
    }

    public function verify(VehicleMovement $movement, User $user, bool $approve, ?string $remarks = null): VehicleMovement
    {
        $this->movements->authorizeMovement($user, $movement);

        if (! $user->can('weighments.verify')) {
            throw ApiException::forbidden('You are not allowed to verify weights');
        }

        if ($movement->current_stage !== WorkflowStage::FINAL_VERIFICATION
            || $movement->status !== MovementStatus::WEIGHT_VERIFICATION_PENDING) {
            throw ApiException::unprocessable('This movement is not awaiting final weight verification');
        }

        if (! $movement->empty_weight || ! $movement->loaded_weight || $movement->net_weight === null) {
            throw ApiException::unprocessable('Net weight is not calculated yet');
        }

        return DB::transaction(function () use ($movement, $user, $approve, $remarks) {
            if (! $approve) {
                $this->movements->setStatus(
                    $movement,
                    MovementStatus::WEIGHT_VERIFICATION_PENDING,
                    $user,
                    'weight_verification_rejected',
                    $remarks ?: 'Weight verification rejected'
                );

                $this->audit->log('weight.verification_rejected', $movement, null, ['remarks' => $remarks], $movement->company_id);

                return $movement->refresh();
            }

            $movement->forceFill([
                'weight_verified_by' => $user->id,
                'weight_verified_at' => now(),
            ])->save();

            $this->audit->log('weight.verified', $movement, null, [
                'net_weight' => $movement->net_weight,
                'unit' => $movement->weight_unit,
            ], $movement->company_id);

            $this->movements->recordHistory(
                $movement,
                WorkflowStage::FINAL_VERIFICATION,
                $movement->status,
                MovementStatus::WEIGHT_VERIFIED,
                $user,
                $remarks,
                [],
                'weight_verified'
            );

            $movement->forceFill(['status' => MovementStatus::WEIGHT_VERIFIED])->save();

            $this->movements->proceedFrom($movement, WorkflowStage::FINAL_VERIFICATION, $user, $remarks);

            return $movement->refresh();
        });
    }
}
