<?php

namespace App\Support;

class PermissionCatalog
{
    /**
     * slug => [name, group]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ALL = [
        'companies.view' => ['View companies', 'Companies'],
        'companies.create' => ['Create companies', 'Companies'],
        'companies.update' => ['Update companies', 'Companies'],
        'companies.manage' => ['Activate/deactivate companies', 'Companies'],
        'departments.view' => ['View departments', 'Departments'],
        'departments.create' => ['Create departments', 'Departments'],
        'departments.update' => ['Update departments', 'Departments'],
        'users.view' => ['View users', 'Users'],
        'users.create' => ['Create users', 'Users'],
        'users.update' => ['Update users', 'Users'],
        'users.manage' => ['Activate/deactivate and reset users', 'Users'],
        'roles.view' => ['View roles', 'Roles'],
        'roles.update' => ['Manage role permissions', 'Roles'],
        'materials.view' => ['View materials', 'Materials'],
        'materials.create' => ['Create materials', 'Materials'],
        'materials.update' => ['Update materials', 'Materials'],
        'quality_params.view' => ['View quality parameters', 'Quality'],
        'quality_params.create' => ['Create quality parameters', 'Quality'],
        'quality_params.update' => ['Update quality parameters', 'Quality'],
        'entries.view' => ['View gate entries', 'Gate'],
        'entries.create' => ['Create gate entries', 'Gate'],
        'entries.override' => ['Override duplicate entry warnings', 'Gate'],
        'movements.view' => ['View vehicle movements', 'Movements'],
        'movements.cancel' => ['Cancel vehicle movements', 'Movements'],
        'quality.view' => ['View quality inspections', 'Quality'],
        'quality.create' => ['Perform quality inspections', 'Quality'],
        'quality.update' => ['Update quality inspections', 'Quality'],
        'weighments.view' => ['View weighments', 'Weighbridge'],
        'weighments.create' => ['Record weighments', 'Weighbridge'],
        'weighments.correct' => ['Correct weighments', 'Weighbridge'],
        'weighments.verify' => ['Verify final weights', 'Weighbridge'],
        'operations.view' => ['View loading/unloading', 'Operations'],
        'operations.create' => ['Start loading/unloading', 'Operations'],
        'operations.update' => ['Complete loading/unloading', 'Operations'],
        'exits.view' => ['View exit requests', 'Exit'],
        'exits.approve' => ['Approve/reject exits', 'Exit'],
        'exits.complete' => ['Complete security gate exit', 'Exit'],
        'exits.override' => ['Override exit rules', 'Exit'],
        'dashboards.view' => ['View dashboards', 'Dashboard'],
        'reports.view' => ['View reports', 'Reports'],
        'reports.export' => ['Export reports', 'Reports'],
        'audit.view' => ['View audit logs', 'Audit'],
    ];

    /**
     * Role slug => permission slugs.
     *
     * @var array<string, array<int, string>>
     */
    public const ROLE_PERMISSIONS = [
        'super_admin' => [],
        'company_admin' => [
            'companies.view',
            'departments.view', 'departments.create', 'departments.update',
            'users.view', 'users.create', 'users.update', 'users.manage',
            'roles.view', 'roles.update',
            'materials.view', 'materials.create', 'materials.update',
            'quality_params.view', 'quality_params.create', 'quality_params.update',
            'entries.view',
            'movements.view', 'movements.cancel',
            'quality.view',
            'weighments.view', 'weighments.verify',
            'operations.view',
            'exits.view', 'exits.approve',
            'dashboards.view',
            'reports.view', 'reports.export',
            'audit.view',
        ],
        'security_officer' => [
            'entries.view', 'entries.create', 'entries.override',
            'movements.view',
            'exits.view', 'exits.complete', 'exits.override',
            'dashboards.view',
        ],
        'quality_inspector' => [
            'movements.view',
            'quality.view', 'quality.create', 'quality.update',
            'quality_params.view',
            'dashboards.view',
        ],
        'weighbridge_operator' => [
            'movements.view',
            'weighments.view', 'weighments.create',
            'dashboards.view',
        ],
        'loading_staff' => [
            'movements.view',
            'operations.view', 'operations.create', 'operations.update',
            'dashboards.view',
        ],
        'manager' => [
            'movements.view',
            'weighments.view', 'weighments.verify',
            'operations.view',
            'quality.view',
            'exits.view', 'exits.approve',
            'dashboards.view',
            'reports.view', 'reports.export',
        ],
    ];

    /**
     * @return array<string, array{label: string, group: string}>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::ALL as $slug => [$name, $group]) {
            $out[$slug] = ['label' => $name, 'group' => $group];
        }

        return $out;
    }
}
