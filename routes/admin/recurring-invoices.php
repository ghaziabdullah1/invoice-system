<?php

use App\Constants\Constants;
use App\Http\Controllers\Admin\RecurringInvoiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('recurring-invoices')->group(function () {
    Route::get('/', [RecurringInvoiceController::class, 'index'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::get('{id}', [RecurringInvoiceController::class, 'show'])
        ->middleware('permission:' . Constants::VIEW_INVOICES);
    Route::post('/', [RecurringInvoiceController::class, 'store'])
        ->middleware('permission:' . Constants::CREATE_INVOICE);
    Route::put('{id}', [RecurringInvoiceController::class, 'update'])
        ->middleware('permission:' . Constants::EDIT_INVOICE);
    Route::delete('{id}', [RecurringInvoiceController::class, 'destroy'])
        ->middleware('permission:' . Constants::DELETE_INVOICE);
    Route::post('{id}/generate-now', [RecurringInvoiceController::class, 'generateNow'])
        ->middleware('permission:' . Constants::CREATE_INVOICE);
    Route::post('{id}/cancel', [RecurringInvoiceController::class, 'cancel'])
        ->middleware('permission:' . Constants::EDIT_INVOICE);
});
