<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialOperation extends Model
{
    protected $fillable = [
        'vehicle_movement_id',
        'type',
        'location',
        'material_details',
        'quantity',
        'quantity_unit',
        'assigned_staff',
        'started_at',
        'completed_at',
        'status',
        'remarks',
        'started_by',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(VehicleMovement::class, 'vehicle_movement_id');
    }

    public function startedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
