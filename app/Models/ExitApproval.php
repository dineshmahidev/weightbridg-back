<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExitApproval extends Model
{
    protected $fillable = [
        'vehicle_movement_id',
        'decision',
        'requested_by',
        'requested_at',
        'approver_id',
        'decided_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(VehicleMovement::class, 'vehicle_movement_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
