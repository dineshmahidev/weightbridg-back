<?php

namespace App\Support;

class Purposes
{
    public const RAW_MATERIAL_PURCHASE = 'raw_material_purchase';
    public const MATERIAL_SALES = 'material_sales';
    public const LOADING = 'loading';
    public const UNLOADING = 'unloading';
    public const INTERNAL_TRANSFER = 'internal_transfer';
    public const OTHER = 'other';

    public static function all(): array
    {
        return [
            self::RAW_MATERIAL_PURCHASE => 'Raw Material Purchase',
            self::MATERIAL_SALES => 'Material Sales',
            self::LOADING => 'Loading',
            self::UNLOADING => 'Unloading',
            self::INTERNAL_TRANSFER => 'Internal Transfer',
            self::OTHER => 'Other',
        ];
    }

    public static function label(string $purpose): string
    {
        return self::all()[$purpose] ?? ucfirst(str_replace('_', ' ', $purpose));
    }
}
