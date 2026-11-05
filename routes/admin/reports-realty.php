<?php

use App\Constants\Constants;
use App\Http\Controllers\Admin\RealtyReportController;
use Illuminate\Support\Facades\Route;

// تقارير العقارات: داخل PropertyMiddleware (لا تمر عبر الفروع)، وصلاحية كل تقرير تُفحص داخل الـ Controller
Route::prefix('reports/realty')->middleware('permission:' . Constants::VIEW_REPORTS)->group(function () {
    $keys = 'occupancy|rent_roll|expiring_contracts|tenant_arrears|collection|vacant_units|property_revenue';

    Route::get('/', [RealtyReportController::class, 'meta']);
    Route::get('{key}', [RealtyReportController::class, 'show'])->where('key', $keys);
    Route::get('{key}/export', [RealtyReportController::class, 'export'])
        ->middleware(['permission:' . Constants::EXPORT_REPORTS, 'throttle:exports'])
        ->where('key', $keys);
});
