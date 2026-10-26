<?php

namespace App\Support;

class MovementStatus
{
    public const ENTERED = 'entered';
    public const QUALITY_PENDING = 'quality_pending';
    public const QUALITY_PASSED = 'quality_passed';
    public const QUALITY_FAILED = 'quality_failed';
    public const FIRST_WEIGHMENT_PENDING = 'first_weighment_pending';
    public const FIRST_WEIGHMENT_COMPLETED = 'first_weighment_completed';
    public const LOADING_IN_PROGRESS = 'loading_in_progress';
    public const UNLOADING_IN_PROGRESS = 'unloading_in_progress';
    public const LOADING_COMPLETED = 'loading_completed';
    public const UNLOADING_COMPLETED = 'unloading_completed';
    public const MATERIAL_OPERATION_PENDING = 'material_operation_pending';
    public const SECOND_WEIGHMENT_PENDING = 'second_weighment_pending';
    public const SECOND_WEIGHMENT_COMPLETED = 'second_weighment_completed';
    public const WEIGHT_VERIFICATION_PENDING = 'weight_verification_pending';
    public const WEIGHT_VERIFIED = 'weight_verified';
    public const EXIT_APPROVAL_PENDING = 'exit_approval_pending';
    public const EXIT_APPROVED = 'exit_approved';
    public const EXIT_REJECTED = 'exit_rejected';
    public const READY_FOR_EXIT = 'ready_for_exit';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::ENTERED,
        self::QUALITY_PENDING,
        self::QUALITY_PASSED,
        self::QUALITY_FAILED,
        self::FIRST_WEIGHMENT_PENDING,
        self::FIRST_WEIGHMENT_COMPLETED,
        self::LOADING_IN_PROGRESS,
        self::UNLOADING_IN_PROGRESS,
        self::LOADING_COMPLETED,
        self::UNLOADING_COMPLETED,
        self::MATERIAL_OPERATION_PENDING,
        self::SECOND_WEIGHMENT_PENDING,
        self::SECOND_WEIGHMENT_COMPLETED,
        self::WEIGHT_VERIFICATION_PENDING,
        self::WEIGHT_VERIFIED,
        self::EXIT_APPROVAL_PENDING,
        self::EXIT_APPROVED,
        self::EXIT_REJECTED,
        self::READY_FOR_EXIT,
        self::COMPLETED,
        self::CANCELLED,
    ];

    /** Statuses that mean the vehicle visit is still open (inside the premises). */
    public const OPEN = [
        self::ENTERED,
        self::QUALITY_PENDING,
        self::QUALITY_PASSED,
        self::QUALITY_FAILED,
        self::FIRST_WEIGHMENT_PENDING,
        self::FIRST_WEIGHMENT_COMPLETED,
        self::LOADING_IN_PROGRESS,
        self::UNLOADING_IN_PROGRESS,
        self::LOADING_COMPLETED,
        self::UNLOADING_COMPLETED,
        self::MATERIAL_OPERATION_PENDING,
        self::SECOND_WEIGHMENT_PENDING,
        self::SECOND_WEIGHMENT_COMPLETED,
        self::WEIGHT_VERIFICATION_PENDING,
        self::WEIGHT_VERIFIED,
        self::EXIT_APPROVAL_PENDING,
        self::EXIT_APPROVED,
        self::EXIT_REJECTED,
        self::READY_FOR_EXIT,
    ];

    public const TERMINAL = [self::COMPLETED, self::CANCELLED];

    public const PENDING_QUALITY = [self::QUALITY_PENDING];
    public const PENDING_FIRST_WEIGHMENT = [self::FIRST_WEIGHMENT_PENDING];
    public const PENDING_SECOND_WEIGHMENT = [self::SECOND_WEIGHMENT_PENDING];
    public const PENDING_VERIFICATION = [self::WEIGHT_VERIFICATION_PENDING];
    public const PENDING_EXIT = [
        self::EXIT_APPROVAL_PENDING,
        self::EXIT_REJECTED,
        self::READY_FOR_EXIT,
        self::EXIT_APPROVED,
    ];

    public static function label(string $status): string
    {
        $labels = [
            self::ENTERED => 'Entered',
            self::QUALITY_PENDING => 'Quality Pending',
            self::QUALITY_PASSED => 'Quality Passed',
            self::QUALITY_FAILED => 'Quality Failed',
            self::FIRST_WEIGHMENT_PENDING => 'First Weighment Pending',
            self::FIRST_WEIGHMENT_COMPLETED => 'First Weighment Completed',
            self::LOADING_IN_PROGRESS => 'Loading In Progress',
            self::UNLOADING_IN_PROGRESS => 'Unloading In Progress',
            self::LOADING_COMPLETED => 'Loading Completed',
            self::UNLOADING_COMPLETED => 'Unloading Completed',
            self::MATERIAL_OPERATION_PENDING => 'Material Operation Pending',
            self::SECOND_WEIGHMENT_PENDING => 'Second Weighment Pending',
            self::SECOND_WEIGHMENT_COMPLETED => 'Second Weighment Completed',
            self::WEIGHT_VERIFICATION_PENDING => 'Weight Verification Pending',
            self::WEIGHT_VERIFIED => 'Weight Verified',
            self::EXIT_APPROVAL_PENDING => 'Exit Approval Pending',
            self::EXIT_APPROVED => 'Exit Approved',
            self::EXIT_REJECTED => 'Exit Rejected',
            self::READY_FOR_EXIT => 'Ready For Exit',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        ];

        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}
