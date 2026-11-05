<?php

use App\Http\Controllers\Admin\PropertyAccessController;
use Illuminate\Support\Facades\Route;

// NOTE: يجب تسجيلها قبل properties.php، وإلا يبتلعها المسار /{id}.
Route::prefix('properties')->group(function () {
    Route::get('/my-properties', [PropertyAccessController::class, 'myProperties']);
    Route::get('/assignable', [PropertyAccessController::class, 'assignable']);
});
