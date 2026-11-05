<?php

use App\Http\Controllers\Admin\MyApprovalsController;
use Illuminate\Support\Facades\Route;

// خارج BranchMiddleware: الموافق يعمل على طلبه بغض النظر عن فرعه، والتحقق داخل الـ Controller
Route::prefix('my-approvals')->group(function () {
    Route::get('/', [MyApprovalsController::class, 'index']);
    Route::get('/count', [MyApprovalsController::class, 'count']);
    Route::get('/{id}', [MyApprovalsController::class, 'show'])->whereNumber('id');
    Route::post('/{id}/approve', [MyApprovalsController::class, 'approve'])->whereNumber('id');
    Route::post('/{id}/reject', [MyApprovalsController::class, 'reject'])->whereNumber('id');
});
