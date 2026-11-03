<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ClientController;

Route::group(['prefix' => 'clients'], function () {
    Route::get('/', [ClientController::class, 'index']);
    Route::post('/', [ClientController::class, 'store']);
    Route::get('/search/all', [ClientController::class, 'search']);
    Route::get('/{id}', [ClientController::class, 'show']);
    Route::put('/{id}', [ClientController::class, 'update']);
    Route::delete('/{id}', [ClientController::class, 'destroy']);
    Route::get('/{id}/invoices', [ClientController::class, 'invoices']);
    Route::get('/{id}/stats', [ClientController::class, 'stats']);
    Route::get('/{id}/statement', [ClientController::class, 'statement']);
});
