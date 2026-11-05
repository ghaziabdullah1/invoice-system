<?php

use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\SupplierController;
use Illuminate\Support\Facades\Route;

// الموردون: عامّون للشركة، والصلاحية تُفحص داخل الـ Controller
Route::prefix('suppliers')->group(function () {
    Route::get('/', [SupplierController::class, 'index']);
    Route::post('/', [SupplierController::class, 'store']);
    Route::get('/{id}', [SupplierController::class, 'show'])->whereNumber('id');
    Route::put('/{id}', [SupplierController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [SupplierController::class, 'destroy'])->whereNumber('id');
});

// أوامر الشراء: مقيّدة بالفرع (داخل BranchMiddleware)
Route::prefix('purchase-orders')->group(function () {
    Route::get('/', [PurchaseOrderController::class, 'index']);
    Route::post('/', [PurchaseOrderController::class, 'store']);
    Route::get('/{id}', [PurchaseOrderController::class, 'show'])->whereNumber('id');
    Route::put('/{id}', [PurchaseOrderController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [PurchaseOrderController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/submit', [PurchaseOrderController::class, 'submit'])->whereNumber('id');
    Route::post('/{id}/approve', [PurchaseOrderController::class, 'approve'])->whereNumber('id');
    Route::post('/{id}/reject', [PurchaseOrderController::class, 'reject'])->whereNumber('id');
    Route::post('/{id}/send', [PurchaseOrderController::class, 'send'])->whereNumber('id');
    Route::post('/{id}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('id');
    Route::post('/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('id');

    // المرفقات
    Route::get('/{id}/attachments', [PurchaseOrderController::class, 'attachments'])->whereNumber('id');
    Route::post('/{id}/attachments', [PurchaseOrderController::class, 'uploadAttachment'])->whereNumber('id');
    Route::delete('/{id}/attachments/{attachmentId}', [PurchaseOrderController::class, 'deleteAttachment'])->whereNumber('id')->whereNumber('attachmentId');
});
