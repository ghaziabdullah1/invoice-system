<?php

use Illuminate\Support\Facades\Route;
use App\Constants\Constants;
use App\Http\Controllers\Admin\InvoiceController;

Route::group(['prefix' => 'invoices'], function () {
    Route::get('/', [InvoiceController::class, 'index'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::post('/', [InvoiceController::class, 'store'])
        ->middleware('permission:' . Constants::CREATE_INVOICE);
    Route::get('/dashboard/stats', [InvoiceController::class, 'dashboardStats'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::get('/notification-counts', [InvoiceController::class, 'notificationCounts'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::get('/overdue/all', [InvoiceController::class, 'overdueInvoices'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::get('/recent/all', [InvoiceController::class, 'recentInvoices'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::get('/{id}', [InvoiceController::class, 'show'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::put('/{id}', [InvoiceController::class, 'update'])
        ->middleware('permission:' . Constants::EDIT_INVOICE);
    Route::delete('/{id}', [InvoiceController::class, 'destroy'])
        ->middleware('permission:' . Constants::DELETE_INVOICE);
    Route::get('/{id}/download-pdf', [InvoiceController::class, 'downloadPDF'])
        ->middleware('permission:' . Constants::DOWNLOAD_INVOICE);
    Route::post('/{id}/duplicate', [InvoiceController::class, 'duplicate'])
        ->middleware('permission:' . Constants::CREATE_INVOICE);
    Route::get('/{id}/generate-pdf', [InvoiceController::class, 'generatePDF'])
        ->middleware('permission:' . Constants::DOWNLOAD_INVOICE);
    Route::put('/{id}/mark-paid', [InvoiceController::class, 'markAsPaid'])
        ->middleware('permission:' . Constants::EDIT_INVOICE);
    Route::post('/{id}/send', [InvoiceController::class, 'send'])
        ->middleware('permission:' . Constants::SEND_INVOICE);
    Route::get('/{id}/installment-plan', [InvoiceController::class, 'getInstallmentPlan'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
});
