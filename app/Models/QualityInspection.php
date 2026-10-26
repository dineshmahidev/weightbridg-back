<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityInspection extends Model
{
    protected $fillable = [
        'vehicle_movement_id',
        'result',
        'remarks',
        'rejection_reason',
        'parameter_values',
        'inspector_id',
        'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'parameter_values' => 'array',
            'inspected_at' => 'datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(VehicleMovement::class, 'vehicle_movement_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }
}
