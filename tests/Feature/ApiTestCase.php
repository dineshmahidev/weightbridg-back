<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Material;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function actingAsApi(User $user): static
    {
        $token = app(\App\Services\AuthService::class)->issueToken($user)[0];

        $this->withHeader('Authorization', 'Bearer '.$token);

        return $this;
    }

    protected function makeUser(string $roleSlug, ?Company $company = null, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'status' => 'active',
        ], $attributes));

        if ($company) {
            $user->companies()->attach($company->id, ['is_primary' => true]);

            if (! $user->department_id) {
                $department = Department::query()->where('company_id', $company->id)->first();

                if ($department) {
                    $user->update(['department_id' => $department->id]);
                }
            }
        }

        return $user->fresh(['role.permissions', 'companies']);
    }

    protected function makeCompany(string $name = 'Company X', string $code = 'CO-X'): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'code' => $code,
            'is_active' => true,
        ]);

        foreach (['Main Gate', 'Quality Check', 'Weighbridge', 'Loading', 'Unloading', 'Dispatch'] as $index => $dept) {
            Department::query()->create([
                'company_id' => $company->id,
                'name' => $dept,
                'sort_order' => $index,
            ]);
        }

        return $company;
    }

    protected function makeMaterial(Company $company, string $name = 'Material A'): Material
    {
        return Material::query()->create([
            'company_id' => $company->id,
            'name' => $name,
            'unit' => 'kg',
        ]);
    }

    protected function departmentId(Company $company, string $name): int
    {
        return (int) Department::query()->where('company_id', $company->id)->where('name', $name)->value('id');
    }

    protected function apiJson(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->call($method, '/api/v1/'.$uri, $data, [], [], $this->transformHeadersToServerVars($headers), json_encode($data));
    }
}
