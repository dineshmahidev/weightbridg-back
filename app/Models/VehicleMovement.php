<?php

namespace App\Models;

use App\Support\MovementStatus;
use App\Support\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleMovement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'movement_ref',
        'company_id',
        'department_id',
        'material_id',
        'vehicle_id',
        'vehicle_number',
        'driver_name',
        'driver_mobile',
        'driver_license_no',
        'supplier_or_customer',
        'purpose',
        'weighing_mode',
        'status',
        'current_stage',
        'security_remarks',
        'entry_at',
        'stage_started_at',
        'stage_completed_at',
        'exit_at',
        'entered_by',
        'exit_by',
        'empty_weight',
        'loaded_weight',
        'net_weight',
        'weight_unit',
        'weight_verified_by',
        'weight_verified_at',
        'quality_result',
    ];

    protected function casts(): array
    {
        return [
            'entry_at' => 'datetime',
            'stage_started_at' => 'datetime',
            'stage_completed_at' => 'datetime',
            'exit_at' => 'datetime',
            'weight_verified_at' => 'datetime',
            'empty_weight' => 'decimal:3',
            'loaded_weight' => 'decimal:3',
            'net_weight' => 'decimal:3',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function exitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exit_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'weight_verified_by');
    }

    public function qualityInspection(): HasOne
    {
        return $this->hasOne(QualityInspection::class);
    }

    public function weighments(): HasMany
    {
        return $this->hasMany(Weighment::class)->orderBy('sequence');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(MaterialOperation::class);
    }

    public function exitApproval(): HasOne
    {
        return $this->hasOne(ExitApproval::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(MovementHistory::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, MovementStatus::OPEN, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === MovementStatus::COMPLETED;
    }

    public function statusLabel(): string
    {
        return MovementStatus::label($this->status);
    }

    public function stageLabel(): string
    {
        return WorkflowStage::label($this->current_stage);
    }
}
