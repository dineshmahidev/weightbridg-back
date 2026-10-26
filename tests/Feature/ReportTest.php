<?php

namespace Tests\Feature;

use App\Services\ExitService;
use App\Services\MovementService;
use App\Services\OperationService;
use App\Services\WeightService;

class ReportTest extends ApiTestCase
{
    private function completedMovement(array $overrides = []): \App\Models\VehicleMovement
    {
        $company = $this->company ??= $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);
        $inspector = $this->makeUser('quality_inspector', $company);
        $operator = $this->makeUser('weighbridge_operator', $company);
        $loader = $this->makeUser('loading_staff', $company);
        $manager = $this->makeUser('manager', $company);
        $material = $this->material ??= $this->makeMaterial($company, 'Material A');

        $movement = app(MovementService::class)->createEntry($security, array_merge([
            'company_id' => $company->id,
            'material_id' => $material->id,
            'vehicle_number' => 'TN REP '.random_int(100, 999),
            'driver_name' => 'Report Driver',
            'purpose' => 'raw_material_purchase',
            'weighing_mode' => 'empty_first',
        ], $overrides));

        $this->actingAsApi($inspector)->apiJson('POST', 'quality/'.$movement->id.'/inspect', [
            'result' => 'PASS',
        ])->assertStatus(201);

        app(WeightService::class)->recordWeighment($movement->fresh(), 1, [
            'weight' => 8000, 'unit' => 'kg', 'weight_type' => 'empty',
        ], $operator);

        app(OperationService::class)->start($movement->fresh(), ['type' => 'loading'], $loader);
        app(OperationService::class)->complete($movement->fresh(), null, $loader);

        app(WeightService::class)->recordWeighment($movement->fresh(), 2, [
            'weight' => 25000, 'unit' => 'kg', 'weight_type' => 'loaded',
        ], $operator);

        app(WeightService::class)->verify($movement->fresh(), $manager, true, null);
        app(ExitService::class)->decide($movement->fresh(), $manager, true, null);
        app(ExitService::class)->completeExit($movement->fresh(), $security, ['remarks' => 'done'], false);

        return $movement->fresh();
    }

    private ?\App\Models\Company $company = null;
    private ?\App\Models\Material $material = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = null;
        $this->material = null;
    }

    public function test_company_wise_report_counts_movements_and_net_weight(): void
    {
        $company = $this->makeCompany('Company A', 'CO-A');
        $this->company = $company;
        $this->material = $this->makeMaterial($company, 'Material A');

        $this->completedMovement();
        $this->completedMovement();

        $manager = $this->makeUser('manager', $company);

        $response = $this->actingAsApi($manager)->apiJson('GET', 'reports/company-wise?company_id='.$company->id);

        $response->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('company_id', $company->id);

        $this->assertNotNull($row);
        $this->assertSame(2, $row['completed_movements']);
        $this->assertSame(34000.0, (float) $row['total_net_weight']);
    }

    public function test_material_wise_report_aggregates_verified_net_weight(): void
    {
        $company = $this->makeCompany('Company A', 'CO-A');
        $this->company = $company;
        $material = $this->material = $this->makeMaterial($company, 'Material B');

        $this->completedMovement(['material_id' => $material->id]);

        $manager = $this->makeUser('manager', $company);

        $response = $this->actingAsApi($manager)->apiJson('GET', 'reports/material-wise?company_id='.$company->id);

        $response->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('material_id', $material->id);

        $this->assertNotNull($row);
        $this->assertSame('Material B', $row['material_name']);
        $this->assertSame(17000.0, (float) $row['total_net_weight']);
    }

    public function test_date_wise_report_and_register(): void
    {
        $company = $this->makeCompany('Company A', 'CO-A');
        $this->company = $company;
        $this->material = $this->makeMaterial($company);

        $this->completedMovement();

        $manager = $this->makeUser('manager', $company);

        $this->actingAsApi($manager)
            ->apiJson('GET', 'reports/date-wise?company_id='.$company->id.'&preset=today')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['date', 'total_movements', 'completed_movements', 'total_net_weight']]]);

        $this->actingAsApi($manager)
            ->apiJson('GET', 'reports/register?company_id='.$company->id.'&preset=today')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_report_access_is_restricted_by_role(): void
    {
        $company = $this->makeCompany();
        $security = $this->makeUser('security_officer', $company);

        $this->actingAsApi($security)->apiJson('GET', 'reports/company-wise')->assertStatus(403);
    }

    public function test_dashboard_returns_role_stats(): void
    {
        $company = $this->makeCompany('Company A', 'CO-A');
        $this->company = $company;
        $this->material = $this->makeMaterial($company);

        $this->completedMovement();

        $manager = $this->makeUser('manager', $company);

        $response = $this->actingAsApi($manager)->apiJson('GET', 'dashboard?company_id='.$company->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.stats.completed_today', 1)
            ->assertJsonPath('data.stats.net_weight_today', fn ($value) => (float) $value === 17000.0);

        $this->assertNotEmpty($response->json('data.recent_movements'));
    }

    public function test_super_admin_dashboard_covers_all_companies(): void
    {
        $companyA = $this->makeCompany('Company A', 'CO-A');
        $this->company = $companyA;
        $this->material = $this->makeMaterial($companyA);
        $this->completedMovement();

        $companyB = $this->makeCompany('Company B', 'CO-B');
        $this->company = $companyB;
        $this->material = $this->makeMaterial($companyB, 'Material B');
        $this->completedMovement();

        $super = $this->makeUser('super_admin');

        $response = $this->actingAsApi($super)->apiJson('GET', 'dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.stats.total_companies', 2)
            ->assertJsonPath('data.stats.completed_today', 2);
    }
}
