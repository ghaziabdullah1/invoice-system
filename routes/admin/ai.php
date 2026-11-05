<?php

use App\Http\Controllers\Admin\AiDraftController;
use Illuminate\Support\Facades\Route;

// مسودات الموظف الذكي: عامة للشركة، والصلاحية تُفحص داخل الـ Controller
Route::prefix('ai-drafts')->group(function () {
    Route::get('/', [AiDraftController::class, 'index']);
    Route::get('/{id}', [AiDraftController::class, 'show'])->whereNumber('id');
    Route::post('/{id}/approve', [AiDraftController::class, 'approve'])->whereNumber('id');
    Route::post('/{id}/reject', [AiDraftController::class, 'reject'])->whereNumber('id');
});

Route::post('ai-conversations/{id}/resume-bot', [AiDraftController::class, 'resumeBot'])->whereNumber('id');
