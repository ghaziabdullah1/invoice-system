<?php

use App\Constants\Constants;
use App\Http\Controllers\Admin\ReportController;
use Illuminate\Support\Facades\Route;

Route::prefix('reports')->group(function () {

    Route::middleware('permission:' . Constants::VIEW_REPORTS)->group(function () {
        Route::get('invoices', [ReportController::class, 'invoices']);
        Route::get('clients',  [ReportController::class, 'clients']);
        Route::get('revenue',  [ReportController::class, 'revenue']);
        Route::get('overdue',  [ReportController::class, 'overdue']);
        Route::get('aging',    [ReportController::class, 'aging']);
    });

    // Rate limit مخصص للتصدير (10 ملفات/5 دقائق) لأن توليد Excel يضغط على CPU والذاكرة
    Route::middleware('permission:' . Constants::EXPORT_REPORTS)->group(function () {
        Route::get('export/{type}', [ReportController::class, 'export'])
            ->middleware('throttle:exports')
            ->where('type', 'invoices|clients|revenue|overdue');
        Route::get('exported-files', [ReportController::class, 'exportedFiles']);
        Route::delete('exported-files/delete', [ReportController::class, 'deleteExportedFile']);
    });

    Route::post('send-reminder/{invoice}', [ReportController::class, 'sendReminder'])
        ->middleware('permission:' . Constants::SEND_INVOICE);
    Route::post('mark-paid/{invoice}', [ReportController::class, 'markAsPaid'])
        ->middleware('permission:' . Constants::EDIT_INVOICE);
});
