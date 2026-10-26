<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Weighment extends Model
{
    protected $fillable = [
        'vehicle_movement_id',
        'sequence',
        'weight_type',
        'weight',
        'unit',
        'weight_in_kg',
        'weighbridge_ref',
        'operator_id',
        'weighed_at',
        'remarks',
        'is_correction',
        'corrected_by',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:3',
            'weight_in_kg' => 'decimal:3',
            'weighed_at' => 'datetime',
            'is_correction' => 'boolean',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(VehicleMovement::class, 'vehicle_movement_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
