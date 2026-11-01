<?php

namespace App\Providers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Observers\SupportTicketObserver;
use App\Observers\SupportTicketReplyObserver;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // المسار يصبح api/broadcasting/auth ومحميا بـ Sanctum
        Broadcast::routes(['prefix' => 'api', 'middleware' => ['auth:sanctum']]);

        require base_path('routes/channels.php');

        SupportTicket::observe(SupportTicketObserver::class);
        SupportTicketReply::observe(SupportTicketReplyObserver::class);

        // تذاكر المستخدم العادي (بدون صلاحيات الأدمن، مقيّدة بصاحب التذكرة)
        Route::middleware(['api', 'check.idle', 'auth:sanctum', 'check.sanctum', 'throttle:api'])
            ->prefix('api/support')
            ->group(base_path('routes/support-user.php'));
    }
}
