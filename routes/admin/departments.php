<?php

use App\Http\Controllers\Admin\DepartmentController;
use Illuminate\Support\Facades\Route;

Route::prefix('departments')->group(function () {
    Route::get('/', [DepartmentController::class, 'index']);
    Route::post('/', [DepartmentController::class, 'store']);
    Route::get('/{id}', [DepartmentController::class, 'show'])->whereNumber('id');
    Route::put('/{id}', [DepartmentController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [DepartmentController::class, 'destroy'])->whereNumber('id');
});
