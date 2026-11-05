<?php

use App\Models\SupportTicket;
use Illuminate\Support\Facades\Broadcast;

// قناة عامة قديمة (الحدث يستخدم Channel عادية، فهذا التعريف احتياطي فقط)
Broadcast::channel('invoice-admin-channel', function ($user) {
    return $user !== null;
});

// صندوق وارد فريق الدعم: super admin يمر تلقائيا داخل hasPermission
Broadcast::channel('support-staff', function ($user) {
    return $user->hasPermission('support_agent');
}, ['guards' => ['sanctum']]);

// محادثة تذكرة واحدة: صاحبها او احد افراد الدعم
Broadcast::channel('support.ticket.{ticketId}', function ($user, $ticketId) {
    $ticket = SupportTicket::find($ticketId);
    if (!$ticket) {
        return false;
    }

    return (int) $ticket->user_id === (int) $user->id
        || $user->hasPermission('support_agent');
}, ['guards' => ['sanctum']]);

// قناة المستخدم الشخصية (لتحديث صفحة "تذاكري")
Broadcast::channel('support.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
}, ['guards' => ['sanctum']]);
