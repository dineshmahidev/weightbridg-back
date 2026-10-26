<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\Company;
use App\Models\VehicleMovement;
use App\Support\MovementStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('dashboards.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view dashboards');
        }

        $companyIds = $user->accessibleCompanyIds();

        if ($request->filled('company_id')) {
            $companyId = (int) $request->query('company_id');
            $this->authorizeCompany($companyId);
            $companyIds = [$companyId];
        }

        $today = now()->startOfDay();

        $base = VehicleMovement::query()->whereIn('company_id', $companyIds);

        $stats = [
            'vehicles_today' => (clone $base)->where('entry_at', '>=', $today)->count(),
            'vehicles_inside' => (clone $base)->whereIn('status', MovementStatus::OPEN)->count(),
            'pending_quality' => (clone $base)->where('status', MovementStatus::QUALITY_PENDING)->count(),
            'quality_failed' => (clone $base)->where('status', MovementStatus::QUALITY_FAILED)->count(),
            'pending_first_weighment' => (clone $base)->whereIn('status', MovementStatus::PENDING_FIRST_WEIGHMENT)->count(),
            'pending_second_weighment' => (clone $base)->whereIn('status', MovementStatus::PENDING_SECOND_WEIGHMENT)->count(),
            'pending_weighments' => (clone $base)->whereIn('status', array_merge(
                MovementStatus::PENDING_FIRST_WEIGHMENT,
                MovementStatus::PENDING_SECOND_WEIGHMENT,
            ))->count(),
            'loading_unloading' => (clone $base)->whereIn('status', [
                MovementStatus::LOADING_IN_PROGRESS,
                MovementStatus::UNLOADING_IN_PROGRESS,
                MovementStatus::MATERIAL_OPERATION_PENDING,
            ])->count(),
            'pending_verification' => (clone $base)->whereIn('status', MovementStatus::PENDING_VERIFICATION)->count(),
            'pending_exit' => (clone $base)->whereIn('status', MovementStatus::PENDING_EXIT)->count(),
            'completed_today' => (clone $base)->where('status', MovementStatus::COMPLETED)->where('exit_at', '>=', $today)->count(),
            'completed_total' => (clone $base)->where('status', MovementStatus::COMPLETED)->count(),
            'net_weight_today' => round((float) (clone $base)->where('status', MovementStatus::COMPLETED)->where('exit_at', '>=', $today)->sum('net_weight'), 3),
            'net_weight_total' => round((float) (clone $base)->where('status', MovementStatus::COMPLETED)->sum('net_weight'), 3),
        ];

        if ($user->isSuperAdmin()) {
            $stats['total_companies'] = Company::query()->count();
            $stats['active_companies'] = Company::query()->where('is_active', true)->count();
        }

        $recentMovements = (clone $base)
            ->with(['company:id,name', 'material:id,name'])
            ->withCount(['weighments', 'operations'])
            ->latest('entry_at')
            ->limit(10)
            ->get()
            ->map(fn (VehicleMovement $m) => [
                'id' => $m->id,
                'movement_ref' => $m->movement_ref,
                'vehicle_number' => $m->vehicle_number,
                'driver_name' => $m->driver_name,
                'company' => $m->company?->only(['id', 'name']),
                'material' => $m->material?->only(['id', 'name']),
                'status' => $m->status,
                'status_label' => $m->statusLabel(),
                'current_stage' => $m->current_stage,
                'current_stage_label' => $m->stageLabel(),
                'net_weight' => $m->net_weight !== null ? (float) $m->net_weight : null,
                'weight_unit' => $m->weight_unit,
                'entry_at' => $m->entry_at?->toIso8601String(),
            ]);

        $recentWeighments = DB::table('weighments')
            ->join('vehicle_movements', 'vehicle_movements.id', '=', 'weighments.vehicle_movement_id')
            ->whereIn('vehicle_movements.company_id', $companyIds)
            ->orderByDesc('weighments.weighed_at')
            ->limit(8)
            ->get([
                'weighments.id',
                'weighments.vehicle_movement_id',
                'weighments.sequence',
                'weighments.weight_type',
                'weighments.weight',
                'weighments.unit',
                'weighments.weighed_at',
                'vehicle_movements.vehicle_number',
                'vehicle_movements.movement_ref',
            ]);

        $statusBreakdown = (clone $base)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderByDesc('total')
            ->pluck('total', 'status');

        $formatMini = fn (?VehicleMovement $m) => $m ? [
            'id' => $m->id,
            'movement_ref' => $m->movement_ref,
            'vehicle_number' => $m->vehicle_number,
            'driver_name' => $m->driver_name,
            'material' => $m->material?->name ?? 'Freight',
            'status' => $m->status,
            'status_label' => $m->statusLabel(),
            'current_stage' => $m->current_stage,
            'current_stage_label' => $m->stageLabel(),
            'weighing_mode' => $m->weighing_mode,
            'net_weight' => $m->net_weight !== null ? (float) $m->net_weight : null,
            'weight_unit' => $m->weight_unit,
            'waiting_since' => $m->stage_started_at?->diffForHumans() ?? 'Just now',
        ] : null;

        $roleQueues = [
            'weighbridge' => $formatMini(
                (clone $base)->with('material')
                    ->whereIn('status', array_merge(MovementStatus::PENDING_FIRST_WEIGHMENT, MovementStatus::PENDING_SECOND_WEIGHMENT))
                    ->oldest('stage_started_at')
                    ->first()
            ),
            'quality' => $formatMini(
                (clone $base)->with('material')
                    ->where('status', MovementStatus::QUALITY_PENDING)
                    ->oldest('stage_started_at')
                    ->first()
            ),
            'operations' => $formatMini(
                (clone $base)->with('material')
                    ->whereIn('status', [
                        MovementStatus::MATERIAL_OPERATION_PENDING,
                        MovementStatus::LOADING_IN_PROGRESS,
                        MovementStatus::UNLOADING_IN_PROGRESS,
                    ])
                    ->oldest('stage_started_at')
                    ->first()
            ),
            'exit_approval' => $formatMini(
                (clone $base)->with('material')
                    ->where('status', MovementStatus::EXIT_APPROVAL_PENDING)
                    ->oldest('stage_started_at')
                    ->first()
            ),
            'gate_exit' => $formatMini(
                (clone $base)->with('material')
                    ->whereIn('status', [MovementStatus::READY_FOR_EXIT, MovementStatus::EXIT_APPROVED])
                    ->oldest('stage_started_at')
                    ->first()
            ),
        ];

        $insideVehicles = (clone $base)
            ->with(['company:id,name', 'material:id,name'])
            ->whereIn('status', MovementStatus::OPEN)
            ->oldest('entry_at')
            ->get()
            ->map(fn (VehicleMovement $m) => [
                'id' => $m->id,
                'movement_ref' => $m->movement_ref,
                'vehicle_number' => $m->vehicle_number,
                'driver_name' => $m->driver_name,
                'company' => $m->company?->only(['id', 'name']),
                'material' => $m->material?->only(['id', 'name']),
                'status' => $m->status,
                'status_label' => $m->statusLabel(),
                'current_stage' => $m->current_stage,
                'current_stage_label' => $m->stageLabel(),
                'weighing_mode' => $m->weighing_mode,
                'net_weight' => $m->net_weight !== null ? (float) $m->net_weight : null,
                'weight_unit' => $m->weight_unit,
                'waiting_since' => $m->stage_started_at?->diffForHumans() ?? 'Just now',
                'entry_at' => $m->entry_at?->toIso8601String(),
                'is_open' => true,
            ]);

        return $this->ok([
            'role' => $user->role?->slug,
            'permissions' => $user->role?->permissions?->pluck('slug') ?? [],
            'company_ids' => $companyIds,
            'stats' => $stats,
            'inside_vehicles' => $insideVehicles,
            'role_queues' => $roleQueues,
            'recent_movements' => $recentMovements,
            'recent_weighments' => $recentWeighments,
            'status_breakdown' => $statusBreakdown,
        ]);
    }
}
