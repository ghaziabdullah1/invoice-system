<?php

namespace App\Observers;

use App\Events\SupportMessageSent;
use App\Models\SupportTicketReply;

class SupportTicketReplyObserver
{
    // لا نبث الا بعد نجاح حفظ المعاملة في القاعدة
    public bool $afterCommit = true;

    public function created(SupportTicketReply $reply): void
    {
        try {
            event(new SupportMessageSent($reply));
        } catch (\Throwable $e) {
            // فشل Pusher يجب الا يمنع حفظ الرد
            report($e);
        }
    }
}
