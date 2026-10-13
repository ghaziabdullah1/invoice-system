<?php

use App\Constants\Constants;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Middleware\BranchMiddleware;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', BranchMiddleware::class, 'permission:' . Constants::VIEW_DASHBOARD])->group(function () {

    Route::get('/dashboard', [DashboardController::class, 'dashboard']);
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/monthly-revenue', [DashboardController::class, 'monthlyRevenue']);
    Route::get('/dashboard/overdue-invoices', [DashboardController::class, 'overdueInvoices']);
    Route::get('/dashboard/recent-activity', [DashboardController::class, 'recentActivity']);
    Route::get('/dashboard/recent-invoices', [DashboardController::class, 'recentInvoices']);
    Route::get('/dashboard/recent-clients', [DashboardController::class, 'recentClients']);
    Route::post('/dashboard/report', [DashboardController::class, 'dashboardReport']);
});
