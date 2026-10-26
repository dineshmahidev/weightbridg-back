<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Material;
use App\Models\QualityParameter;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Sample multi-company data. Company names below are generic test data
     * only — real deployments must manage companies through the application.
     */
    public function run(): void
    {
        $companies = [
            ['name' => 'Company A', 'code' => 'CO-A'],
            ['name' => 'Company B', 'code' => 'CO-B'],
            ['name' => 'Company C', 'code' => 'CO-C'],
        ];

        $departments = [
            'Main Gate', 'Quality Check', 'Weighbridge', 'Loading', 'Unloading', 'Dispatch',
        ];

        $materials = ['Material A', 'Material B', 'Material C'];

        $roleMap = [
            'admin' => 'company_admin',
            'security' => 'security_officer',
            'quality' => 'quality_inspector',
            'weighbridge' => 'weighbridge_operator',
            'loading' => 'loading_staff',
            'manager' => 'manager',
        ];

        foreach ($companies as $index => $info) {
            $company = Company::query()->firstOrCreate(
                ['code' => $info['code']],
                [
                    'name' => $info['name'],
                    'address' => 'Industrial Estate, Tamil Nadu',
                    'phone' => '+91-900000000'.$index,
                    'email' => strtolower($info['code']).'@example.test',
                    'is_active' => true,
                ]
            );

            foreach ($departments as $dIndex => $deptName) {
                Department::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $deptName],
                    [
                        'code' => strtoupper(str_replace(' ', '-', substr($deptName, 0, 12))),
                        'sort_order' => $dIndex,
                        'is_active' => true,
                    ]
                );
            }

            foreach ($materials as $mIndex => $materialName) {
                $material = Material::query()->firstOrCreate(
                    ['company_id' => $company->id, 'name' => $materialName],
                    [
                        'code' => strtoupper($info['code']).'-M'.($mIndex + 1),
                        'unit' => 'kg',
                        'is_active' => true,
                    ]
                );

                QualityParameter::query()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'material_id' => $material->id,
                        'name' => 'Moisture Content',
                    ],
                    [
                        'data_type' => 'number',
                        'unit' => '%',
                        'is_required' => true,
                        'min_value' => 0,
                        'max_value' => 30,
                        'sort_order' => 1,
                    ]
                );

                QualityParameter::query()->firstOrCreate(
                    [
                        'company_id' => $company->id,
                        'material_id' => $material->id,
                        'name' => 'Visual Condition',
                    ],
                    [
                        'data_type' => 'text',
                        'is_required' => false,
                        'sort_order' => 2,
                    ]
                );
            }

            QualityParameter::query()->firstOrCreate(
                ['company_id' => $company->id, 'material_id' => null, 'name' => 'Contamination Check'],
                [
                    'data_type' => 'boolean',
                    'is_required' => false,
                    'sort_order' => 3,
                ]
            );

            foreach ($roleMap as $key => $roleSlug) {
                $suffix = chr(65 + $index); // A, B, C
                $email = $key.'.'.strtolower($info['code']).'@example.test';

                $user = User::query()->firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => ucfirst($key).' '.$info['name'],
                        'mobile' => '+91-91111111'.$index,
                        'password' => 'password',
                        'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
                        'department_id' => Department::query()
                            ->where('company_id', $company->id)
                            ->when($key === 'security', fn ($q) => $q->where('name', 'Main Gate'))
                            ->when($key === 'quality', fn ($q) => $q->where('name', 'Quality Check'))
                            ->when($key === 'weighbridge', fn ($q) => $q->where('name', 'Weighbridge'))
                            ->when($key === 'loading', fn ($q) => $q->where('name', 'Loading'))
                            ->when($key === 'manager', fn ($q) => $q->where('name', 'Dispatch'))
                            ->when($key === 'admin', fn ($q) => $q->where('name', 'Dispatch'))
                            ->value('id'),
                        'status' => 'active',
                    ]
                );

                $user->companies()->syncWithoutDetaching([
                    $company->id => ['is_primary' => $key === 'admin'],
                ]);
            }
        }
    }
}
