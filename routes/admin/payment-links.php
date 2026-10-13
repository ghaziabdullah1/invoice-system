<?php

use App\Constants\Constants;
use App\Http\Controllers\Admin\PaymentLinkController;
use Illuminate\Support\Facades\Route;

// داخل /api/admin/ — auth:sanctum مطبّق من api.php، والصلاحيات هنا لكل مسار

Route::get('/invoices/{invoiceId}/payment-links', [PaymentLinkController::class, 'index'])
    ->middleware('permission:' . Constants::VIEW_INVOICES);
Route::post('/invoices/{invoiceId}/payment-links', [PaymentLinkController::class, 'createForInvoice'])
    ->middleware('permission:' . Constants::CREATE_PAYMENT);
Route::post('/invoices/{invoiceId}/installments/{installmentNumber}/payment-links', [PaymentLinkController::class, 'createForInstallment'])
    ->middleware('permission:' . Constants::CREATE_PAYMENT);
Route::post('/payment-links/{linkId}/send', [PaymentLinkController::class, 'sendLink'])
    ->middleware('permission:' . Constants::SEND_INVOICE);
