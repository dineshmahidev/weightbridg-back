<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovementHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'vehicle_movement_id',
        'stage',
        'from_status',
        'to_status',
        'user_id',
        'action',
        'remarks',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(VehicleMovement::class, 'vehicle_movement_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
