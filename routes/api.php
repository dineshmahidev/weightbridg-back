<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\ExitController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\QualityController;
use App\Http\Controllers\Api\QualityParameterController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WeighbridgeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // -----------------------------------------------------------------
    // Public authentication endpoints
    // -----------------------------------------------------------------
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:6,1');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:6,1');
    });

    // -----------------------------------------------------------------
    // Authenticated endpoints
    // -----------------------------------------------------------------
    Route::middleware('auth.api')->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::patch('profile', [AuthController::class, 'updateProfile']);
            Route::post('change-password', [AuthController::class, 'changePassword']);
        });

        Route::get('dashboard', [DashboardController::class, 'index']);

        // Device Push Tokens
        Route::post('devices/register', [DeviceTokenController::class, 'register']);
        Route::post('devices/unregister', [DeviceTokenController::class, 'unregister']);
        Route::post('devices/test-trigger', [DeviceTokenController::class, 'testTrigger']);

        // Reference data
        Route::get('reference/movements', [MovementController::class, 'reference']);

        // Companies
        Route::get('companies', [CompanyController::class, 'index'])->middleware('permission:companies.view');
        Route::post('companies', [CompanyController::class, 'store'])->middleware('permission:companies.create');
        Route::get('companies/{company}', [CompanyController::class, 'show']);
        Route::put('companies/{company}', [CompanyController::class, 'update'])->middleware('permission:companies.update');
        Route::patch('companies/{company}/status', [CompanyController::class, 'toggleStatus'])->middleware('permission:companies.manage');
        Route::get('companies/{company}/stats', [CompanyController::class, 'stats']);
        Route::post('companies/{company}/assign-admin', [CompanyController::class, 'assignAdmin'])->middleware('permission:users.manage');

        // Departments
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::post('departments', [DepartmentController::class, 'store'])->middleware('permission:departments.create');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->middleware('permission:departments.update');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])->middleware('permission:departments.update');

        // Materials
        Route::get('materials', [MaterialController::class, 'index']);
        Route::post('materials', [MaterialController::class, 'store'])->middleware('permission:materials.create');
        Route::put('materials/{material}', [MaterialController::class, 'update'])->middleware('permission:materials.update');
        Route::delete('materials/{material}', [MaterialController::class, 'destroy'])->middleware('permission:materials.update');

        // Quality parameters
        Route::get('quality-parameters', [QualityParameterController::class, 'index']);
        Route::post('quality-parameters', [QualityParameterController::class, 'store'])->middleware('permission:quality_params.create');
        Route::put('quality-parameters/{quality_parameter}', [QualityParameterController::class, 'update'])->middleware('permission:quality_params.update');
        Route::delete('quality-parameters/{quality_parameter}', [QualityParameterController::class, 'destroy'])->middleware('permission:quality_params.update');

        // Roles & permissions
        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.view');
        Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions'])->middleware('permission:roles.update');

        // Users
        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
        Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:users.manage');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.manage');

        // Vehicle movements
        Route::get('movements', [MovementController::class, 'index']);
        Route::post('movements', [MovementController::class, 'store'])->middleware('permission:entries.create');
        Route::get('movements/duplicate-check', [MovementController::class, 'duplicateWarning']);
        Route::get('movements/vehicle-history/{vehicle_number}', [MovementController::class, 'vehicleHistory']);
        Route::get('movements/{movement}', [MovementController::class, 'show']);
        Route::get('movements/{movement}/history', [MovementController::class, 'history']);
        Route::post('movements/{movement}/cancel', [MovementController::class, 'cancel'])->middleware('permission:movements.cancel');

        // Quality inspections
        Route::get('quality/pending', [QualityController::class, 'pending'])->middleware('permission:quality.view');
        Route::get('quality/parameters', [QualityController::class, 'parameters']);
        Route::post('quality/{movement}/inspect', [QualityController::class, 'store'])->middleware('permission:quality.create');
        Route::get('quality/{movement}', [QualityController::class, 'show'])->middleware('permission:quality.view');

        // Weighbridge
        Route::get('weighbridge/pending', [WeighbridgeController::class, 'pending']);
        Route::post('weighbridge/{movement}/weights/{sequence}', [WeighbridgeController::class, 'record'])->middleware('permission:weighments.create');
        Route::get('weighbridge/{movement}/weights', [WeighbridgeController::class, 'showWeights'])->middleware('permission:weighments.view');
        Route::put('weighments/{weighment}/correct', [WeighbridgeController::class, 'correct'])->middleware('permission:weighments.correct');
        Route::post('weighbridge/{movement}/verify', [WeighbridgeController::class, 'verify'])->middleware('permission:weighments.verify');

        // Loading / unloading operations
        Route::get('operations/pending', [OperationController::class, 'pending'])->middleware('permission:operations.view');
        Route::post('operations/{movement}/start', [OperationController::class, 'start'])->middleware('permission:operations.create');
        Route::put('operations/{movement}', [OperationController::class, 'update'])->middleware('permission:operations.update|operations.create');
        Route::post('operations/{movement}/complete', [OperationController::class, 'complete'])->middleware('permission:operations.update');
        Route::post('operations/{movement}/skip', [OperationController::class, 'skip'])->middleware('permission:operations.update|weighments.create');
        Route::get('operations/{movement}', [OperationController::class, 'show'])->middleware('permission:operations.view');

        // Exit approval & security gate exit
        Route::get('exits/pending', [ExitController::class, 'pending']);
        Route::post('exits/{movement}/request', [ExitController::class, 'requestApproval']);
        Route::post('exits/{movement}/decide', [ExitController::class, 'decide'])->middleware('permission:exits.approve');
        Route::post('exits/{movement}/complete', [ExitController::class, 'complete'])->middleware('permission:exits.complete');
        Route::get('exits/{movement}/blockers', [ExitController::class, 'blockers']);

        // Reports
        Route::get('reports/summary', [ReportController::class, 'summary'])->middleware('permission:reports.view');
        Route::get('reports/company-wise', [ReportController::class, 'companyWise'])->middleware('permission:reports.view');
        Route::get('reports/material-wise', [ReportController::class, 'materialWise'])->middleware('permission:reports.view');
        Route::get('reports/date-wise', [ReportController::class, 'dateWise'])->middleware('permission:reports.view');
        Route::get('reports/register', [ReportController::class, 'register'])->middleware('permission:reports.view');
        Route::get('reports/export', [ReportController::class, 'export'])->middleware('permission:reports.export|reports.view');

        // Audit
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit.view');
    });
});
