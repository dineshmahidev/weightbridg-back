<?php

use App\Http\Controllers\ReportPrintController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// A4 Print & PDF Preview Screens
Route::get('reports/movement/{id}/slip', [ReportPrintController::class, 'movementSlip'])->name('reports.movement-slip');
Route::get('reports/bulk-register', [ReportPrintController::class, 'bulkRegister'])->name('reports.bulk-register');
