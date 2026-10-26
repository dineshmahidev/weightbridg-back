<?php

namespace App\Support;

class WorkflowStage
{
    public const MAIN_GATE_ENTRY = 'main_gate_entry';
    public const QUALITY_CHECK = 'quality_check';
    public const FIRST_WEIGHMENT = 'first_weighment';
    public const MATERIAL_OPERATION = 'material_operation';
    public const SECOND_WEIGHMENT = 'second_weighment';
    public const NET_WEIGHT_CALCULATION = 'net_weight_calculation';
    public const FINAL_VERIFICATION = 'final_verification';
    public const EXIT_APPROVAL = 'exit_approval';
    public const SECURITY_GATE_EXIT = 'security_gate_exit';
    public const COMPLETED = 'completed';

    public const ORDER = [
        self::MAIN_GATE_ENTRY,
        self::QUALITY_CHECK,
        self::FIRST_WEIGHMENT,
        self::MATERIAL_OPERATION,
        self::SECOND_WEIGHMENT,
        self::NET_WEIGHT_CALCULATION,
        self::FINAL_VERIFICATION,
        self::EXIT_APPROVAL,
        self::SECURITY_GATE_EXIT,
        self::COMPLETED,
    ];

    public const DEFAULT_CONFIG = [
        self::QUALITY_CHECK => ['enabled' => true, 'required' => true],
        self::FIRST_WEIGHMENT => ['enabled' => true, 'required' => true],
        self::MATERIAL_OPERATION => ['enabled' => true, 'required' => false],
        self::SECOND_WEIGHMENT => ['enabled' => true, 'required' => true],
        self::FINAL_VERIFICATION => ['enabled' => true, 'required' => true],
        self::EXIT_APPROVAL => ['enabled' => true, 'required' => true],
        self::SECURITY_GATE_EXIT => ['enabled' => true, 'required' => true],
    ];

    public static function label(string $stage): string
    {
        $labels = [
            self::MAIN_GATE_ENTRY => 'Main Gate Entry',
            self::QUALITY_CHECK => 'Quality Check',
            self::FIRST_WEIGHMENT => 'First Weighment',
            self::MATERIAL_OPERATION => 'Loading / Unloading',
            self::SECOND_WEIGHMENT => 'Second Weighment',
            self::NET_WEIGHT_CALCULATION => 'Net Weight Calculation',
            self::FINAL_VERIFICATION => 'Final Verification',
            self::EXIT_APPROVAL => 'Exit Approval',
            self::SECURITY_GATE_EXIT => 'Security Gate Exit',
            self::COMPLETED => 'Completed',
        ];

        return $labels[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
    }

    /**
     * Merge stored company workflow config with defaults.
     *
     * @return array<string, array{enabled: bool, required: bool}>
     */
    public static function config(?array $stored): array
    {
        $config = self::DEFAULT_CONFIG;

        foreach ((array) $stored as $stage => $value) {
            if (! array_key_exists($stage, $config) || ! is_array($value)) {
                continue;
            }

            $config[$stage] = [
                'enabled' => (bool) ($value['enabled'] ?? true),
                'required' => (bool) ($value['required'] ?? false),
            ];
        }

        return $config;
    }
}
