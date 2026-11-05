<?php

use App\Http\Controllers\Admin\ApprovalWorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('approval-workflows')->group(function () {
    Route::get('/', [ApprovalWorkflowController::class, 'index']);
    Route::get('/{key}', [ApprovalWorkflowController::class, 'show'])->where('key', '.*');
    Route::post('/save', [ApprovalWorkflowController::class, 'save']);
    Route::delete('/{key}', [ApprovalWorkflowController::class, 'destroy'])->where('key', '.*');
});
