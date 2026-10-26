<?php

namespace Tests\Feature;

use App\Models\VehicleMovement;
use App\Services\ExitService;
use App\Services\MovementService;
use App\Services\OperationService;
use App\Services\WeightService;
use App\Support\MovementStatus;
use App\Support\WorkflowStage;

class MovementWorkflowTest extends ApiTestCase
{
    public function test_gate_entry_creates_movement_and_advances_to_quality(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);
        $material = $this->makeMaterial($company);

        $this->actingAsApi($security)->apiJson('POST', 'movements', [
            'company_id' => $company->id,
            'department_id' => $this->departmentId($company, 'Main Gate'),
            'material_id' => $material->id,
            'vehicle_number' => 'TN 38 XY 1111',
            'driver_name' => 'Test Driver',
            'driver_mobile' => '+91-9000000001',
            'purpose' => 'raw_material_purchase',
            'weighing_mode' => 'empty_first',
        ])->assertStatus(201)
            ->assertJsonPath('data.status', MovementStatus::QUALITY_PENDING)
            ->assertJsonPath('data.current_stage', WorkflowStage::QUALITY_CHECK);

        $this->assertDatabaseHas('vehicle_movements', [
            'vehicle_number' => 'TN 38 XY 1111',
            'company_id' => $company->id,
        ]);

        $this->assertDatabaseHas('movement_histories', [
            'action' => 'stage_changed',
            'to_status' => MovementStatus::ENTERED,
        ]);
    }

    public function test_duplicate_active_entry_is_rejected(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);

        $payload = [
            'company_id' => $company->id,
            'vehicle_number' => 'TN 38 DUP 222',
            'driver_name' => 'Dup Driver',
            'purpose' => 'loading',
            'weighing_mode' => 'empty_first',
        ];

        $this->actingAsApi($security)->apiJson('POST', 'movements', $payload)->assertStatus(201);
        $this->actingAsApi($security)->apiJson('POST', 'movements', $payload)->assertStatus(409);

        // Override with permission (security officer has entries.override).
        $this->actingAsApi($security)
            ->apiJson('POST', 'movements', array_merge($payload, ['override_duplicate' => true]))
            ->assertStatus(201);

        $this->assertSame(2, VehicleMovement::query()->where('vehicle_number', 'TN 38 DUP 222')->count());
    }

    public function test_same_vehicle_can_have_multiple_completed_visits(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);

        foreach ([1, 2] as $i) {
            $movement = app(MovementService::class)->createEntry($security, [
                'company_id' => $company->id,
                'vehicle_number' => 'TN MULTI 001',
                'driver_name' => 'Driver '.$i,
                'purpose' => 'internal_transfer',
                'weighing_mode' => 'empty_first',
            ]);

            app(MovementService::class)->markCompleted($movement, $security, 'closed');
        }

        $this->assertSame(2, VehicleMovement::query()->where('vehicle_number', 'TN MULTI 001')->count());
        $this->assertSame(2, VehicleMovement::query()->where('vehicle_number', 'TN MULTI 001')->where('status', 'completed')->count());
    }

    public function test_company_isolation_is_enforced(): void
    {
        $companyA = $this->makeCompany('Company A', 'CO-A');
        $companyB = $this->makeCompany('Company B', 'CO-B');

        $securityB = $this->makeUser('security_officer', $companyB);
        $movementA = app(MovementService::class)->createEntry($this->makeUser('security_officer', $companyA), [
            'company_id' => $companyA->id,
            'vehicle_number' => 'TN ISO 1',
            'driver_name' => 'A',
            'purpose' => 'loading',
        ]);

        // IDOR attempt: user from company B tries to read company A movement.
        $this->actingAsApi($securityB)
            ->apiJson('GET', 'movements/'.$movementA->id)
            ->assertStatus(403);

        $this->actingAsApi($securityB)
            ->apiJson('POST', 'movements', [
                'company_id' => $companyA->id,
                'vehicle_number' => 'TN ISO 2',
                'purpose' => 'loading',
            ])
            ->assertStatus(403);

        // Company B list must not include company A movements.
        $response = $this->actingAsApi($securityB)->apiJson('GET', 'movements');
        $response->assertStatus(200);

        foreach ($response->json('data') as $row) {
            $this->assertSame($companyB->id, $row['company_id']);
        }
    }

    public function test_quality_fail_blocks_progression_and_pass_advances(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);
        $inspector = $this->makeUser('quality_inspector', $company);
        $operator = $this->makeUser('weighbridge_operator', $company);

        $movement = app(MovementService::class)->createEntry($security, [
            'company_id' => $company->id,
            'vehicle_number' => 'TN QF 1',
            'driver_name' => 'Q',
            'purpose' => 'raw_material_purchase',
            'weighing_mode' => 'empty_first',
        ]);

        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movement->id.'/inspect', [
            'result' => 'FAIL',
            'rejection_reason' => 'High moisture',
        ])->assertStatus(201)
            ->assertJsonPath('data.movement.status', MovementStatus::QUALITY_FAILED);

        // Weighment must not be allowed after a failed inspection.
        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/1', [
            'weight' => 8000,
            'unit' => 'kg',
            'weight_type' => 'empty',
        ])->assertStatus(422);

        // Authorized re-inspection can pass the vehicle.
        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movement->id.'/inspect', [
            'result' => 'PASS',
            'remarks' => 'Rechecked',
        ])->assertStatus(201)
            ->assertJsonPath('data.movement.status', MovementStatus::FIRST_WEIGHMENT_PENDING);
    }

    public function test_empty_first_full_cycle_with_correct_net_weight(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);
        $inspector = $this->makeUser('quality_inspector', $company);
        $operator = $this->makeUser('weighbridge_operator', $company);
        $loader = $this->makeUser('loading_staff', $company);
        $manager = $this->makeUser('manager', $company);

        $movement = app(MovementService::class)->createEntry($security, [
            'company_id' => $company->id,
            'vehicle_number' => 'TN 38 AB 1234',
            'driver_name' => 'Ravi',
            'purpose' => 'raw_material_purchase',
            'weighing_mode' => 'empty_first',
        ]);

        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movement->id.'/inspect', [
            'result' => 'PASS',
        ])->assertStatus(201);

        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/1', [
            'weight' => 8000,
            'unit' => 'kg',
            'weight_type' => 'empty',
        ])->assertStatus(201);

        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/start', [
            'type' => 'loading',
            'location' => 'Bay 1',
        ])->assertStatus(201);

        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/complete', [
            'remarks' => 'Loaded fully',
        ])->assertStatus(200);

        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/2', [
            'weight' => 25000,
            'unit' => 'kg',
            'weight_type' => 'loaded',
        ])->assertStatus(201)
            ->assertJsonPath('data.net_weight', fn ($v) => (float) $v === 17000.0)
            ->assertJsonPath('data.empty_weight', fn ($v) => (float) $v === 8000.0)
            ->assertJsonPath('data.loaded_weight', fn ($v) => (float) $v === 25000.0)
            ->assertJsonPath('data.status', MovementStatus::WEIGHT_VERIFICATION_PENDING);

        $this->actingAsApi($manager)->apiJson('POST', 'weighbridge/'.$movement->id.'/verify', [
            'decision' => 'approve',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', MovementStatus::EXIT_APPROVAL_PENDING);

        $this->actingAsApi($manager)->apiJson('POST', 'exits/'.$movement->id.'/decide', [
            'decision' => 'approve',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', MovementStatus::READY_FOR_EXIT);

        $this->actingAsApi($security)->apiJson('POST', 'exits/'.$movement->id.'/complete', [
            'remarks' => 'All clear',
        ])->assertStatus(200)
            ->assertJsonPath('data.status', MovementStatus::COMPLETED);

        $movement = $movement->fresh();

        $this->assertSame(MovementStatus::COMPLETED, $movement->status);
        $this->assertSame('17000.000', (string) $movement->net_weight);
        $this->assertNotNull($movement->exit_at);

        // Audit trail entries exist.
        $this->assertDatabaseHas('audit_logs', ['action' => 'quality.inspected']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'weighment.recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'weight.verified']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'exit.completed']);
    }

    public function test_loaded_first_workflow_calculates_same_net_weight(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);
        $inspector = $this->makeUser('quality_inspector', $company);
        $operator = $this->makeUser('weighbridge_operator', $company);
        $loader = $this->makeUser('loading_staff', $company);
        $manager = $this->makeUser('manager', $company);

        $movement = app(MovementService::class)->createEntry($security, [
            'company_id' => $company->id,
            'vehicle_number' => 'TN LF 2',
            'driver_name' => 'Suresh',
            'purpose' => 'unloading',
            'weighing_mode' => 'loaded_first',
        ]);

        app(\App\Services\WeightService::class);

        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movement->id.'/inspect', ['result' => 'PASS'])->assertStatus(201);

        // Mode B: first weight is LOADED.
        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/1', [
            'weight' => 25000, 'unit' => 'kg', 'weight_type' => 'loaded',
        ])->assertStatus(201);

        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/start', [
            'type' => 'unloading',
        ])->assertStatus(201);

        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/complete')->assertStatus(200);

        // Mode B: second weight is EMPTY.
        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/2', [
            'weight' => 8000, 'unit' => 'kg', 'weight_type' => 'empty',
        ])->assertStatus(201)
            ->assertJsonPath('data.net_weight', fn ($v) => (float) $v === 17000.0)
            ->assertJsonPath('data.empty_weight', fn ($v) => (float) $v === 8000.0)
            ->assertJsonPath('data.loaded_weight', fn ($v) => (float) $v === 25000.0);

        // Wrong weight type for mode must be rejected.
        $movementB = app(MovementService::class)->createEntry($security, [
            'company_id' => $company->id,
            'vehicle_number' => 'TN LF 3',
            'purpose' => 'unloading',
            'weighing_mode' => 'loaded_first',
        ]);

        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movementB->id.'/inspect', ['result' => 'PASS'])->assertStatus(201);

        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movementB->id.'/weights/1', [
            'weight' => 1000, 'unit' => 'kg', 'weight_type' => 'empty',
        ])->assertStatus(422);
    }

    public function test_unit_conversion_between_tonne_and_kg(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);
        $inspector = $this->makeUser('quality_inspector', $company);
        $operator = $this->makeUser('weighbridge_operator', $company);
        $loader = $this->makeUser('loading_staff', $company);

        $movement = app(MovementService::class)->createEntry($security, [
            'company_id' => $company->id,
            'vehicle_number' => 'TN UNIT 1',
            'purpose' => 'loading',
            'weighing_mode' => 'empty_first',
        ]);

        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movement->id.'/inspect', ['result' => 'PASS'])->assertStatus(201);

        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/1', [
            'weight' => 8, 'unit' => 'tonne', 'weight_type' => 'empty',
        ])->assertStatus(201);

        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/start', ['type' => 'loading'])->assertStatus(201);
        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/complete')->assertStatus(200);

        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/2', [
            'weight' => 25, 'unit' => 'tonne', 'weight_type' => 'loaded',
        ])->assertStatus(201)
            ->assertJsonPath('data.net_weight', fn ($v) => (float) $v === 17.0)
            ->assertJsonPath('data.weight_unit', 'tonne');
    }

    public function test_exit_is_blocked_without_required_approvals(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);

        $movement = app(MovementService::class)->createEntry($security, [
            'company_id' => $company->id,
            'vehicle_number' => 'TN BLOCK 1',
            'purpose' => 'loading',
            'weighing_mode' => 'empty_first',
        ]);

        $this->actingAsApi($security)->apiJson('POST', 'exits/'.$movement->id.'/complete', [
            'remarks' => 'Trying early exit',
        ])->assertStatus(422);

        $blockers = app(MovementService::class)->exitBlockers($movement->fresh());
        $this->assertNotEmpty($blockers);
    }

    public function test_weight_verification_requires_permission(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);
        $inspector = $this->makeUser('quality_inspector', $company);
        $operator = $this->makeUser('weighbridge_operator', $company);
        $loader = $this->makeUser('loading_staff', $company);

        $movement = app(MovementService::class)->createEntry($security, [
            'company_id' => $company->id,
            'vehicle_number' => 'TN PERM 1',
            'purpose' => 'loading',
            'weighing_mode' => 'empty_first',
        ]);

        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movement->id.'/inspect', ['result' => 'PASS'])->assertStatus(201);
        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/1', [
            'weight' => 5000, 'unit' => 'kg', 'weight_type' => 'empty',
        ])->assertStatus(201);
        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/start', ['type' => 'loading'])->assertStatus(201);
        $this->actingAsApi($loader)->apiJson('POST', 'operations/'.$movement->id.'/complete')->assertStatus(200);
        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/weights/2', [
            'weight' => 9000, 'unit' => 'kg', 'weight_type' => 'loaded',
        ])->assertStatus(201);

        // Weighbridge operator cannot verify final weights.
        $this->actingAsApi($operator)->apiJson('POST', 'weighbridge/'.$movement->id.'/verify', [
            'decision' => 'approve',
        ])->assertStatus(403);
    }
}
