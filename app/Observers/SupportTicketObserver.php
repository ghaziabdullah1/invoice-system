<?php

namespace App\Observers;

use App\Events\SupportTicketCreated;
use App\Events\SupportTicketUpdated;
use App\Models\SupportTicket;

class SupportTicketObserver
{
    public bool $afterCommit = true;

    public function created(SupportTicket $ticket): void
    {
        try {
            event(new SupportTicketCreated($ticket));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function updated(SupportTicket $ticket): void
    {
        if (!$ticket->wasChanged(['status', 'priority', 'assigned_to', 'last_customer_reply_at', 'last_admin_reply_at'])) {
            return;
        }

        try {
            event(new SupportTicketUpdated($ticket));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
