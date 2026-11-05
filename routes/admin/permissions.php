<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\PermissionController;

Route::prefix('permissions')->group(function () {
    Route::get('/', [PermissionController::class, 'index']);
    Route::post('/', [PermissionController::class, 'store']);
    Route::get('/all', [PermissionController::class, 'getAllPermissions']);
    Route::get('/menus', [PermissionController::class, 'menus']);
    Route::get('/with-menus', [PermissionController::class, 'permissionsWithMenus']);
    Route::get('/parent-permissions', [PermissionController::class, 'parentPermissions']);

    Route::prefix('{id}')->group(function () {
        Route::get('/', [PermissionController::class, 'show']);
        Route::put('/', [PermissionController::class, 'update']);
        Route::delete('/', [PermissionController::class, 'destroy']);
    });
});
