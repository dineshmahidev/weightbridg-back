<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class QualityParameter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'material_id',
        'name',
        'data_type',
        'unit',
        'is_required',
        'min_value',
        'max_value',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'min_value' => 'decimal:3',
            'max_value' => 'decimal:3',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
