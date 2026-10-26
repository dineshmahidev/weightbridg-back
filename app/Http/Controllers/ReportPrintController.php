<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\VehicleMovement;
use App\Support\MovementStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportPrintController extends Controller
{
    /**
     * Display an individual vehicle weighbridge movement slip formatted for A4 print.
     */
    public function movementSlip(string|int $id, Request $request): View
    {
        $movement = VehicleMovement::query()
            ->with([
                'company',
                'department',
                'material',
                'enteredBy:id,name',
                'exitedBy:id,name',
                'verifiedBy:id,name',
                'weighments.operator:id,name',
                'qualityInspection.inspector:id,name',
                'exitApproval.approver:id,name',
            ])
            ->where(function ($query) use ($id) {
                if (is_numeric($id)) {
                    $query->where('id', (int) $id)->orWhere('movement_ref', (string) $id);
                } else {
                    $query->where('movement_ref', (string) $id);
                }
            })
            ->firstOrFail();

        $autoPrint = $request->boolean('print', false);

        return view('reports.movement-slip', [
            'movement' => $movement,
            'autoPrint' => $autoPrint,
        ]);
    }

    /**
     * Display a bulk movement register report formatted for A4 print based on date range and filters.
     */
    public function bulkRegister(Request $request): View
    {
        $dateFrom = $request->query('date_from', now()->subDays(7)->toDateString());
        $dateTo = $request->query('date_to', now()->toDateString());
        $companyId = $request->query('company_id');
        $status = $request->query('status');

        $query = VehicleMovement::query()
            ->with([
                'company:id,name,code',
                'department:id,name',
                'material:id,name',
                'enteredBy:id,name',
                'exitedBy:id,name',
                'weighments',
            ])
            ->whereDate('entry_at', '>=', $dateFrom)
            ->whereDate('entry_at', '<=', $dateTo);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        if ($status === 'open') {
            $query->whereIn('status', MovementStatus::OPEN);
        } elseif ($status) {
            $query->where('status', $status);
        }

        $movements = $query->orderBy('entry_at', 'asc')->get();

        $company = $companyId ? Company::find($companyId) : null;
        $totalNetWeight = $movements->where('status', MovementStatus::COMPLETED)->sum('net_weight');

        $autoPrint = $request->boolean('print', false);

        return view('reports.bulk-register', [
            'movements' => $movements,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'company' => $company,
            'status' => $status,
            'totalNetWeight' => round($totalNetWeight, 3),
            'autoPrint' => $autoPrint,
        ]);
    }
}
