<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ContractController;

Route::group(['prefix' => 'contracts'], function () {
    Route::get('/', [ContractController::class, 'index']);
    Route::post('/', [ContractController::class, 'store']);
    Route::get('/{id}', [ContractController::class, 'show']);
    Route::put('/{id}', [ContractController::class, 'update']);
    Route::delete('/{id}', [ContractController::class, 'destroy']);
    Route::post('/{id}/renew', [ContractController::class, 'renew']);
    Route::post('/{id}/terminate', [ContractController::class, 'terminate']);
    Route::post('/{id}/document', [ContractController::class, 'uploadDocument']);
    Route::get('/{id}/document', [ContractController::class, 'downloadDocument']);
    Route::get('/{id}/schedule', [ContractController::class, 'schedule']);
    Route::post('/{id}/invoices/{number}', [ContractController::class, 'generateInvoice']);
});
