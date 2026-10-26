<?php

namespace App\Models;

use App\Support\WorkflowStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'email',
        'gstin',
        'is_active',
        'workflow_config',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'workflow_config' => 'array',
            'settings' => 'array',
        ];
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(VehicleMovement::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_company')->withPivot('is_primary')->withTimestamps();
    }

    public function qualityParameters(): HasMany
    {
        return $this->hasMany(QualityParameter::class);
    }

    /**
     * @return array<string, array{enabled: bool, required: bool}>
     */
    public function workflow(): array
    {
        return WorkflowStage::config($this->workflow_config);
    }

    public function isStageEnabled(string $stage): bool
    {
        return (bool) ($this->workflow()[$stage]['enabled'] ?? false);
    }

    public function isStageRequired(string $stage): bool
    {
        return (bool) ($this->workflow()[$stage]['required'] ?? false);
    }
}
