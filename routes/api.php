<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PaymentLinkController;
use App\Http\Controllers\Admin\SupportTicketController;
use App\Http\Controllers\Admin\OtpLogController;
use App\Http\Middleware\BranchMiddleware;

/*
|--------------------------------------------------------------------------
| API Routes - Invoice System
|--------------------------------------------------------------------------
*/

// ═══════════════════════════════════════════════════════════════
// ║  مسارات Webhook (Public)                                   ║
// ═══════════════════════════════════════════════════════════════

Route::post('/stripe/webhook', [PaymentController::class, 'handleWebhook'])
    ->name('stripe.webhook');

Route::post(
    'admin/payments/webhook',
    [PaymentController::class, 'handleWebhook']
)->withoutMiddleware([
    \App\Http\Middleware\SanitizeInputMiddleware::class,
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
]);

// ═══════════════════════════════════════════════════════════════
// ║  مسارات عامة (Public) — لا تتطلب تسجيل دخول              ║
// ═══════════════════════════════════════════════════════════════

// إنشاء تذكرة دعم من صفحة "اتصل بنا" (Public)
// Route::post('/support/tickets', [SupportTicketController::class, 'store']);

Route::prefix('support')->group(function () {
    Route::post('/tickets', [SupportTicketController::class, 'store'])
        ->middleware('throttle:support-ticket-create');

    Route::get('/tickets/track', [SupportTicketController::class, 'track'])
        ->middleware('throttle:support-ticket-track');
});

// التحقق من روابط الدفع
Route::get(
    '/payment-links/{token}/validate',
    [PaymentLinkController::class, 'validateLink']
);

// ═══════════════════════════════════════════════════════════════
// ║  Content Pages (Public)                                   ║
// ═══════════════════════════════════════════════════════════════

require __DIR__ . '/admin/content.php';

// ═══════════════════════════════════════════════════════════════
// ║  Auth (Public) — بدون sanctum                              ║
// ═══════════════════════════════════════════════════════════════

Route::prefix('admin')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:password-reset');

    Route::post('reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset');

    Route::post('send-otp', [AuthController::class, 'sendOtp'])
        ->middleware('throttle:otp');

    Route::post('verify-otp', [AuthController::class, 'verifyOtp'])
        ->middleware('throttle:login');
});

// ═══════════════════════════════════════════════════════════════
// ║  Admin Routes                                              ║
// ═══════════════════════════════════════════════════════════════

Route::prefix('admin')->middleware([
    'check.idle',
    //'idle.timeout',
    'auth:sanctum',
    'check.sanctum',
    'throttle:api',
    'admin',
])->group(function () {

    // ── Auth ────────────────────────────────────────────────
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::post('refresh', [AuthController::class, 'refresh']);

    // ── Profile ─────────────────────────────────────────────
    Route::get('/me', [UserController::class, 'profile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
    Route::post('/change-password', [UserController::class, 'changePassword']);

    // ── Logs ────────────────────────────────────────────────
    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::get('/otp-logs', [OtpLogController::class, 'index']);

    // ── Includes ────────────────────────────────────────────

    require __DIR__ . '/admin/branches.php';

    Route::middleware([BranchMiddleware::class])->group(function () {
        require __DIR__ . '/admin/clients.php';
        require __DIR__ . '/admin/invoices.php';
        require __DIR__ . '/admin/reports.php';
        require __DIR__ . '/admin/installments.php';
        require __DIR__ . '/admin/users.php';
        require __DIR__ . '/admin/dashboard.php';
        require __DIR__ . '/admin/permissions.php';
        require __DIR__ . '/admin/admin-groups.php';
        require __DIR__ . '/admin/payments.php';
        require __DIR__ . '/admin/recurring-invoices.php';
        require __DIR__ . '/admin/payment-links.php';
        require __DIR__ . '/admin/support.php';
        require __DIR__ . '/admin/properties.php';
        require __DIR__ . '/admin/floors.php';
        require __DIR__ . '/admin/units.php';
        require __DIR__ . '/admin/tenants.php';
    });
});
