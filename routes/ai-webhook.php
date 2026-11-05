<?php

use App\Http\Controllers\Admin\AiWhatsAppWebhookController;
use App\Http\Middleware\SanitizeInputMiddleware;
use Illuminate\Support\Facades\Route;

// Webhook واتساب: عام، ومحمي بالتحقق من توقيع Meta داخل الـ Repository.
// نستثني SanitizeInput لأنه قد يغيّر جسم الطلب فيفسد التحقق من التوقيع (كما في Stripe).
Route::prefix('ai/whatsapp')
    ->withoutMiddleware([
        SanitizeInputMiddleware::class,
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    ])
    ->group(function () {
        Route::get('webhook', [AiWhatsAppWebhookController::class, 'verify']);
        Route::post('webhook', [AiWhatsAppWebhookController::class, 'receive'])
            ->middleware('throttle:120,1');
    });
