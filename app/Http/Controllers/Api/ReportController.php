<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Company;
use App\Models\Material;
use App\Models\VehicleMovement;
use App\Support\MovementStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportController extends ApiController
{
    public function companyWise(Request $request): JsonResponse
    {
        $this->requirePermission('reports.view');

        $data = $this->validateFilters($request);
        $companyIds = $this->scopeCompanyIds($request);

        $rows = Company::query()
            ->withCount([
                'movements',
                'movements as completed_count' => fn ($q) => $q->where('status', MovementStatus::COMPLETED),
            ])
            ->whereIn('id', $companyIds)
            ->orderBy('name')
            ->get()
            ->map(function (Company $company) use ($data) {
                $base = $this->movementQuery($data)->where('company_id', $company->id);

                return [
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'company_code' => $company->code,
                    'total_movements' => (clone $base)->count(),
                    'completed_movements' => (clone $base)->where('status', MovementStatus::COMPLETED)->count(),
                    'vehicles_inside' => (clone $base)->whereIn('status', MovementStatus::OPEN)->count(),
                    'pending_inspections' => (clone $base)->where('status', MovementStatus::QUALITY_PENDING)->count(),
                    'pending_weighments' => (clone $base)->whereIn('status', array_merge(
                        MovementStatus::PENDING_FIRST_WEIGHMENT,
                        MovementStatus::PENDING_SECOND_WEIGHMENT,
                    ))->count(),
                    'loading_operations' => (clone $base)->whereHas('operations', fn ($q) => $q->where('type', 'loading'))->count(),
                    'unloading_operations' => (clone $base)->whereHas('operations', fn ($q) => $q->where('type', 'unloading'))->count(),
                    'total_net_weight' => round((float) (clone $base)->where('status', MovementStatus::COMPLETED)->sum('net_weight'), 3),
                ];
            });

        return $this->ok($rows->values());
    }

    public function materialWise(Request $request): JsonResponse
    {
        $this->requirePermission('reports.view');

        $data = $this->validateFilters($request);
        $companyIds = $this->scopeCompanyIds($request);

        $query = $this->movementQuery($data)
            ->whereIn('company_id', $companyIds)
            ->whereNotNull('material_id');

        if ($request->filled('material_id')) {
            $query->where('material_id', $request->query('material_id'));
        }

        if ($request->filled('vehicle_number')) {
            $query->where('vehicle_number', 'like', '%'.$request->query('vehicle_number').'%');
        }

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->query('status')));
        }

        $rows = (clone $query)
            ->select(
                'material_id',
                'company_id',
                DB::raw('count(*) as total_movements'),
                DB::raw("sum(case when status = 'completed' then 1 else 0 end) as completed_movements"),
                DB::raw('sum(coalesce(net_weight, 0)) as total_net_weight'),
                DB::raw("sum(case when exists (select 1 from material_operations mo where mo.vehicle_movement_id = vehicle_movements.id and mo.type = 'loading') then 1 else 0 end) as loading_operations"),
                DB::raw("sum(case when exists (select 1 from material_operations mo where mo.vehicle_movement_id = vehicle_movements.id and mo.type = 'unloading') then 1 else 0 end) as unloading_operations"),
            )
            ->groupBy('material_id', 'company_id')
            ->get();

        $materials = Material::query()->whereIn('id', $rows->pluck('material_id')->unique()->all())->get()->keyBy('id');
        $companies = Company::query()->whereIn('id', $rows->pluck('company_id')->unique()->all())->get()->keyBy('id');

        $out = $rows->map(fn ($row) => [
            'material_id' => $row->material_id,
            'material_name' => $materials[$row->material_id]->name ?? 'Unknown',
            'company_id' => $row->company_id,
            'company_name' => $companies[$row->company_id]->name ?? 'Unknown',
            'total_movements' => (int) $row->total_movements,
            'completed_movements' => (int) $row->completed_movements,
            'total_net_weight' => round((float) $row->total_net_weight, 3),
            'loading_operations' => (int) $row->loading_operations,
            'unloading_operations' => (int) $row->unloading_operations,
        ])->values();

        return $this->ok($out);
    }

    public function dateWise(Request $request): JsonResponse
    {
        $this->requirePermission('reports.view');

        $data = $this->validateFilters($request);
        $companyIds = $this->scopeCompanyIds($request);

        $rows = $this->movementQuery($data)
            ->whereIn('company_id', $companyIds)
            ->select(
                DB::raw('date(entry_at) as report_date'),
                DB::raw('count(*) as total_movements'),
                DB::raw("sum(case when status = 'completed' then 1 else 0 end) as completed_movements"),
                DB::raw("sum(case when status not in ('completed','cancelled') then 1 else 0 end) as open_movements"),
                DB::raw('sum(coalesce(net_weight, 0)) as total_net_weight'),
            )
            ->groupBy(DB::raw('date(entry_at)'))
            ->orderByDesc(DB::raw('date(entry_at)'))
            ->limit(366)
            ->get();

        return $this->ok($rows->map(fn ($row) => [
            'date' => (string) $row->report_date,
            'total_movements' => (int) $row->total_movements,
            'completed_movements' => (int) $row->completed_movements,
            'open_movements' => (int) $row->open_movements,
            'total_net_weight' => round((float) $row->total_net_weight, 3),
        ]));
    }

    public function register(Request $request): JsonResponse
    {
        $this->requirePermission('reports.view');

        $data = $this->validateFilters($request);
        $companyIds = $this->scopeCompanyIds($request);

        $paginator = $this->movementQuery($data)
            ->whereIn('company_id', $companyIds)
            ->with(['company:id,name,code', 'material:id,name', 'enteredBy:id,name', 'exitedBy:id,name'])
            ->orderBy('entry_at')
            ->paginate(min((int) $request->query('per_page', 50), 100));

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => collect($paginator->items())->map(fn (VehicleMovement $m) => [
                'movement_ref' => $m->movement_ref,
                'vehicle_number' => $m->vehicle_number,
                'driver_name' => $m->driver_name,
                'driver_mobile' => $m->driver_mobile,
                'company_name' => $m->company?->name,
                'material_name' => $m->material?->name,
                'purpose' => $m->purpose,
                'entry_at' => $m->entry_at?->toDateTimeString(),
                'exit_at' => $m->exit_at?->toDateTimeString(),
                'entry_by' => $m->enteredBy?->name,
                'exit_by' => $m->exitedBy?->name,
                'status' => $m->status,
                'status_label' => $m->statusLabel(),
                'empty_weight' => $m->empty_weight !== null ? (float) $m->empty_weight : null,
                'loaded_weight' => $m->loaded_weight !== null ? (float) $m->loaded_weight : null,
                'net_weight' => $m->net_weight !== null ? (float) $m->net_weight : null,
                'weight_unit' => $m->weight_unit,
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->requirePermission('reports.view');

        $data = $this->validateFilters($request);
        $companyIds = $this->scopeCompanyIds($request);

        $base = $this->movementQuery($data)->whereIn('company_id', $companyIds);

        return $this->ok([
            'total_movements' => (clone $base)->count(),
            'completed_movements' => (clone $base)->where('status', MovementStatus::COMPLETED)->count(),
            'cancelled_movements' => (clone $base)->where('status', MovementStatus::CANCELLED)->count(),
            'open_movements' => (clone $base)->whereIn('status', MovementStatus::OPEN)->count(),
            'total_net_weight' => round((float) (clone $base)->where('status', MovementStatus::COMPLETED)->sum('net_weight'), 3),
            'total_loading' => (clone $base)->whereHas('operations', fn ($q) => $q->where('type', 'loading'))->count(),
            'total_unloading' => (clone $base)->whereHas('operations', fn ($q) => $q->where('type', 'unloading'))->count(),
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $this->requirePermission('reports.export|reports.view');

        $data = $this->validateFilters($request);
        $companyIds = $this->scopeCompanyIds($request);
        $type = $request->query('type', 'register');

        $rows = match ($type) {
            'company' => $this->companyWiseRows($request, $companyIds, $data),
            'material' => $this->materialWiseRows($request, $companyIds, $data),
            'daily' => $this->dateWiseRows($companyIds, $data),
            default => $this->registerRows($companyIds, $data),
        };

        $this->audit('report.exported', null, null, ['type' => $type, 'filters' => $data]);

        return $this->ok([
            'type' => $type,
            'generated_at' => now()->toIso8601String(),
            'filters' => $data,
            'rows' => $rows,
        ], 'Report exported');
    }

    private function companyWiseRows(Request $request, array $companyIds, array $data): Collection
    {
        $request->query->set('company_id', null);

        // Reuse the same logic as the JSON endpoint by calling it and extracting rows.
        $response = $this->companyWise(new Request(['query' => array_merge($data, ['per_page' => 1000])]));

        return collect(json_decode($response->getContent(), true)['data'] ?? []);
    }

    private function materialWiseRows(Request $request, array $companyIds, array $data): Collection
    {
        $response = $this->materialWise(new Request(['query' => array_merge($data, ['per_page' => 1000])]));

        return collect(json_decode($response->getContent(), true)['data'] ?? []);
    }

    private function dateWiseRows(array $companyIds, array $data): Collection
    {
        $response = $this->dateWise(new Request(['query' => $data]));

        return collect(json_decode($response->getContent(), true)['data'] ?? []);
    }

    private function registerRows(array $companyIds, array $data): Collection
    {
        return $this->movementQuery($data)
            ->whereIn('company_id', $companyIds)
            ->with(['company:id,name', 'material:id,name'])
            ->orderBy('entry_at')
            ->limit(5000)
            ->get()
            ->map(fn (VehicleMovement $m) => [
                'movement_ref' => $m->movement_ref,
                'vehicle_number' => $m->vehicle_number,
                'driver_name' => $m->driver_name,
                'company_name' => $m->company?->name,
                'material_name' => $m->material?->name,
                'entry_at' => $m->entry_at?->toDateTimeString(),
                'exit_at' => $m->exit_at?->toDateTimeString(),
                'status' => $m->statusLabel(),
                'empty_weight' => $m->empty_weight,
                'loaded_weight' => $m->loaded_weight,
                'net_weight' => $m->net_weight,
                'weight_unit' => $m->weight_unit,
            ]);
    }

    private function requirePermission(string $permission): void
    {
        $user = $this->user();

        $allowed = collect(explode('|', $permission))->contains(fn ($slug) => $user->can($slug));

        if (! $allowed) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view reports');
        }
    }

    /**
     * @return array{date_from: ?string, date_to: ?string, preset: ?string}
     */
    private function validateFilters(Request $request): array
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'gte:date_from'],
            'preset' => ['nullable', 'in:today,yesterday,last_7_days,last_30_days,current_month,custom'],
        ]);

        $preset = $request->query('preset');

        $from = $request->query('date_from');
        $to = $request->query('date_to');

        match ($preset) {
            'today' => [$from, $to] = [now()->toDateString(), now()->toDateString()],
            'yesterday' => [$from, $to] = [now()->subDay()->toDateString(), now()->subDay()->toDateString()],
            'last_7_days' => [$from, $to] = [now()->subDays(6)->toDateString(), now()->toDateString()],
            'last_30_days' => [$from, $to] = [now()->subDays(29)->toDateString(), now()->toDateString()],
            'current_month' => [$from, $to] = [now()->startOfMonth()->toDateString(), now()->toDateString()],
            default => null,
        };

        return [
            'date_from' => $from ?: null,
            'date_to' => $to ?: null,
            'preset' => $preset,
        ];
    }

    private function scopeCompanyIds(Request $request): array
    {
        $user = $this->user();
        $ids = $user->accessibleCompanyIds();

        if ($request->filled('company_id')) {
            $companyId = (int) $request->query('company_id');
            $this->authorizeCompany($companyId);

            return array_values(array_intersect($ids, [$companyId]));
        }

        return $ids;
    }

    private function movementQuery(array $data)
    {
        $query = VehicleMovement::query();

        if (! empty($data['date_from'])) {
            $query->where('entry_at', '>=', $data['date_from'].' 00:00:00');
        }

        if (! empty($data['date_to'])) {
            $query->where('entry_at', '<=', $data['date_to'].' 23:59:59');
        }

        return $query;
    }
}
