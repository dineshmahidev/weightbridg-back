<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user();

        if (! $user->can('audit.view')) {
            throw \App\Exceptions\ApiException::forbidden('You are not allowed to view audit logs');
        }

        $query = AuditLog::query()->with(['user:id,name,email', 'company:id,name'])->latest('created_at');

        if (! $user->isSuperAdmin()) {
            $query->whereIn('company_id', $user->accessibleCompanyIds());
        } elseif ($request->filled('company_id')) {
            $query->where('company_id', $request->query('company_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->query('action').'%');
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->query('date_to').' 23:59:59');
        }

        return $this->paginated($query->paginate(min((int) $request->query('per_page', 20), 100)));
    }
}
