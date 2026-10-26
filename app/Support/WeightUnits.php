<?php

namespace App\Support;

use InvalidArgumentException;

class WeightUnits
{
    public const KG = 'kg';
    public const TONNE = 'tonne';
    public const QUINTAL = 'quintal';
    public const GRAM = 'gram';
    public const POUND = 'lb';

    /** Multipliers to kilograms. */
    public const TO_KG = [
        self::KG => 1,
        self::GRAM => 0.001,
        self::QUINTAL => 100,
        self::TONNE => 1000,
        'mt' => 1000,
        self::POUND => 0.45359237,
    ];

    public static function all(): array
    {
        return array_keys(self::TO_KG);
    }

    public static function isSupported(?string $unit): bool
    {
        if (! is_string($unit)) {
            return false;
        }

        $normalized = self::normalize($unit);

        return isset(self::TO_KG[$normalized]);
    }

    public static function normalize(string $unit): string
    {
        $unit = strtolower(trim($unit));

        return match ($unit) {
            'kgs', 'kilogram', 'kilograms' => self::KG,
            'mt', 'metric ton', 'metric_ton', 'metric tonne', 'metric tonnes', 't', 'tons', 'tonnes' => self::TONNE,
            'q', 'quintal', 'quintals' => self::QUINTAL,
            'g', 'gram', 'grams' => self::GRAM,
            'lbs', 'pound', 'pounds' => self::POUND,
            default => $unit,
        };
    }

    public static function toKg(float $value, string $unit): float
    {
        $unit = self::normalize($unit);

        if (! isset(self::TO_KG[$unit])) {
            throw new InvalidArgumentException("Unsupported weight unit: {$unit}");
        }

        return round($value * self::TO_KG[$unit], 3);
    }

    public static function fromKg(float $valueInKg, string $unit): float
    {
        $unit = self::normalize($unit);

        if (! isset(self::TO_KG[$unit]) || self::TO_KG[$unit] == 0) {
            throw new InvalidArgumentException("Unsupported weight unit: {$unit}");
        }

        return round($valueInKg / self::TO_KG[$unit], 3);
    }
}
