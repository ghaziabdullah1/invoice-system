<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\PropertySummaryController;

Route::group(['prefix' => 'properties'], function () {
    Route::get('/', [PropertyController::class, 'index']);
    Route::get('/summary', [PropertySummaryController::class, 'index']);
    Route::post('/', [PropertyController::class, 'store']);
    Route::get('/{id}', [PropertyController::class, 'show']);
    Route::put('/{id}', [PropertyController::class, 'update']);
    Route::delete('/{id}', [PropertyController::class, 'destroy']);
});
