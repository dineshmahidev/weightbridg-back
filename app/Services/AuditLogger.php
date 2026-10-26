<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?int $companyId = null,
        ?int $userId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $userId ?? auth('api')->id(),
            'company_id' => $companyId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => Request::ip(),
            'user_agent' => (string) Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
