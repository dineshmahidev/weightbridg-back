<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Material;
use App\Models\User;
use App\Services\ExitService;
use App\Services\MovementService;
use App\Services\OperationService;
use App\Services\WeightService;
use Illuminate\Database\Seeder;

class DemoMovementSeeder extends Seeder
{
    public function run(): void
    {
        $movements = app(MovementService::class);
        $weights = app(WeightService::class);
        $operations = app(OperationService::class);
        $exits = app(ExitService::class);

        $company = Company::query()->where('code', 'CO-A')->first();

        if (! $company) {
            return;
        }

        $security = User::query()->where('email', 'security.co-a@example.test')->first();
        $quality = User::query()->where('email', 'quality.co-a@example.test')->first();
        $weighbridge = User::query()->where('email', 'weighbridge.co-a@example.test')->first();
        $loading = User::query()->where('email', 'loading.co-a@example.test')->first();
        $manager = User::query()->where('email', 'manager.co-a@example.test')->first();

        if (! $security || ! $quality || ! $weighbridge || ! $loading || ! $manager) {
            return;
        }

        $material = Material::query()->where('company_id', $company->id)->first();

        $scenarios = [
            // Completed purchase trip: empty arrives, loaded exits.
            [
                'vehicle_number' => 'TN 38 AB 1234',
                'purpose' => 'raw_material_purchase',
                'weighing_mode' => 'empty_first',
                'driver_name' => 'Ravi Kumar',
                'driver_mobile' => '+91-9876500001',
                'first_weight' => 8000,
                'second_weight' => 25000,
                'operation' => 'loading',
                'full_cycle' => true,
            ],
            // Completed sales trip: loaded arrives, empty exits.
            [
                'vehicle_number' => 'TN 38 CD 5678',
                'purpose' => 'material_sales',
                'weighing_mode' => 'loaded_first',
                'driver_name' => 'Suresh Babu',
                'driver_mobile' => '+91-9876500002',
                'first_weight' => 30000,
                'second_weight' => 9500,
                'operation' => 'unloading',
                'full_cycle' => true,
            ],
            // Pending first weighment.
            [
                'vehicle_number' => 'TN 45 EF 9012',
                'purpose' => 'raw_material_purchase',
                'weighing_mode' => 'empty_first',
                'driver_name' => 'Mohan Raj',
                'driver_mobile' => '+91-9876500003',
                'full_cycle' => false,
                'stop_after' => 'quality',
            ],
            // Quality failed vehicle.
            [
                'vehicle_number' => 'TN 45 GH 3456',
                'purpose' => 'raw_material_purchase',
                'weighing_mode' => 'empty_first',
                'driver_name' => 'Arun Prasad',
                'driver_mobile' => '+91-9876500004',
                'full_cycle' => false,
                'stop_after' => 'quality_fail',
            ],
            // Waiting at loading bay.
            [
                'vehicle_number' => 'TN 09 JK 7890',
                'purpose' => 'raw_material_purchase',
                'weighing_mode' => 'empty_first',
                'driver_name' => 'Karthik S',
                'driver_mobile' => '+91-9876500005',
                'first_weight' => 7500,
                'full_cycle' => false,
                'stop_after' => 'loading',
            ],
        ];

        foreach ($scenarios as $scenario) {
            $exists = \App\Models\VehicleMovement::query()
                ->where('vehicle_number', $scenario['vehicle_number'])
                ->exists();

            if ($exists) {
                continue;
            }

            $movement = $movements->createEntry($security, [
                'company_id' => $company->id,
                'department_id' => $company->departments()->where('name', 'Main Gate')->value('id'),
                'material_id' => $material?->id,
                'vehicle_number' => $scenario['vehicle_number'],
                'driver_name' => $scenario['driver_name'],
                'driver_mobile' => $scenario['driver_mobile'],
                'driver_license_no' => 'TN-'.rand(1000000, 9999999),
                'supplier_or_customer' => 'Sample Trading Partner',
                'purpose' => $scenario['purpose'],
                'weighing_mode' => $scenario['weighing_mode'],
                'security_remarks' => 'Demo entry created by seeder',
            ], false);

            if (($scenario['stop_after'] ?? null) === 'quality_fail') {
                $this->inspect($movement, $quality, 'FAIL', null, 'Material failed moisture standard');

                continue;
            }

            $this->inspect($movement, $quality, 'PASS');

            if (($scenario['stop_after'] ?? null) === 'quality') {
                continue;
            }

            $weights->recordWeighment($movement->fresh(), 1, [
                'weight' => $scenario['first_weight'],
                'unit' => 'kg',
                'weight_type' => $scenario['weighing_mode'] === 'empty_first' ? 'empty' : 'loaded',
                'weighbridge_ref' => 'WB-DEMO-1',
                'remarks' => 'Demo first weighment',
            ], $weighbridge);

            if (($scenario['stop_after'] ?? null) === 'loading') {
                $operations->start($movement->fresh(), [
                    'type' => $scenario['operation'] ?? 'loading',
                    'location' => 'Bay 1',
                    'quantity' => 15000,
                    'quantity_unit' => 'kg',
                    'assigned_staff' => 'Dock Team 1',
                    'remarks' => 'Demo operation started',
                ], $loading);

                continue;
            }

            $operations->start($movement->fresh(), [
                'type' => $scenario['operation'],
                'location' => 'Bay 1',
                'quantity' => abs($scenario['second_weight'] - $scenario['first_weight']),
                'quantity_unit' => 'kg',
                'assigned_staff' => 'Dock Team 1',
            ], $loading);

            $operations->complete($movement->fresh(), 'Demo operation completed', $loading);

            $weights->recordWeighment($movement->fresh(), 2, [
                'weight' => $scenario['second_weight'],
                'unit' => 'kg',
                'weight_type' => $scenario['weighing_mode'] === 'empty_first' ? 'loaded' : 'empty',
                'weighbridge_ref' => 'WB-DEMO-2',
                'remarks' => 'Demo second weighment',
            ], $weighbridge);

            $weights->verify($movement->fresh(), $manager, true, 'Demo verification');

            $exits->decide($movement->fresh(), $manager, true, 'Demo exit approval');

            $exits->completeExit($movement->fresh(), $security, ['remarks' => 'Demo gate exit'], false);
        }

        // A couple of completed trips for Company B so multi-company reports have data.
        $companyB = Company::query()->where('code', 'CO-B')->first();

        if ($companyB) {
            $securityB = User::query()->where('email', 'security.co-b@example.test')->first();
            $qualityB = User::query()->where('email', 'quality.co-b@example.test')->first();
            $weighbridgeB = User::query()->where('email', 'weighbridge.co-b@example.test')->first();
            $loadingB = User::query()->where('email', 'loading.co-b@example.test')->first();
            $managerB = User::query()->where('email', 'manager.co-b@example.test')->first();
            $materialB = Material::query()->where('company_id', $companyB->id)->first();

            if ($securityB && $qualityB && $weighbridgeB && $loadingB && $managerB
                && ! \App\Models\VehicleMovement::query()->where('vehicle_number', 'TN 09 LM 1122')->exists()) {
                $movement = $movements->createEntry($securityB, [
                    'company_id' => $companyB->id,
                    'department_id' => $companyB->departments()->where('name', 'Main Gate')->value('id'),
                    'material_id' => $materialB?->id,
                    'vehicle_number' => 'TN 09 LM 1122',
                    'driver_name' => 'Vignesh M',
                    'driver_mobile' => '+91-9876500006',
                    'purpose' => 'raw_material_purchase',
                    'weighing_mode' => 'empty_first',
                ], false);

                $this->inspect($movement, $qualityB, 'PASS');

                $weights->recordWeighment($movement->fresh(), 1, [
                    'weight' => 8500, 'unit' => 'kg', 'weight_type' => 'empty',
                ], $weighbridgeB);

                $operations->start($movement->fresh(), ['type' => 'loading', 'location' => 'Bay 2'], $loadingB);
                $operations->complete($movement->fresh(), null, $loadingB);

                $weights->recordWeighment($movement->fresh(), 2, [
                    'weight' => 22000, 'unit' => 'kg', 'weight_type' => 'loaded',
                ], $weighbridgeB);

                $weights->verify($movement->fresh(), $managerB, true, null);
                $exits->decide($movement->fresh(), $managerB, true, null);
                $exits->completeExit($movement->fresh(), $securityB, ['remarks' => 'Exited'], false);
            }
        }
    }

    private function inspect(\App\Models\VehicleMovement $movement, User $inspector, string $result, ?array $values = null, ?string $reason = null): void
    {
        $movement = $movement->fresh();

        app(\App\Services\MovementService::class)->transition(
            $movement,
            'quality_check',
            $result === 'PASS' ? 'quality_passed' : 'quality_failed',
            $inspector,
            $reason,
            [],
            $result === 'PASS' ? 'quality_passed' : 'quality_failed'
        );

        \App\Models\QualityInspection::query()->updateOrCreate(
            ['vehicle_movement_id' => $movement->id],
            [
                'result' => $result,
                'remarks' => 'Demo inspection',
                'rejection_reason' => $reason,
                'parameter_values' => $values,
                'inspector_id' => $inspector->id,
                'inspected_at' => now(),
            ]
        );

        $movement->forceFill(['quality_result' => $result])->save();

        if ($result === 'PASS') {
            app(\App\Services\MovementService::class)->proceedFrom($movement->fresh(), 'quality_check', $inspector);
        }
    }
}
