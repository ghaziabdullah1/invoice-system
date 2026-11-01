<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SupportTicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public SupportTicket $ticket) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('support-staff'),
            new PrivateChannel('support.ticket.' . $this->ticket->id),
        ];

        if ($this->ticket->user_id) {
            $channels[] = new PrivateChannel('support.user.' . $this->ticket->user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id'                     => $this->ticket->id,
            'ticket_number'          => $this->ticket->ticket_number,
            'status'                 => $this->ticket->status,
            'priority'               => $this->ticket->priority,
            'assigned_to'            => $this->ticket->assigned_to,
            'last_customer_reply_at' => optional($this->ticket->last_customer_reply_at)->toIso8601String(),
            'last_admin_reply_at'    => optional($this->ticket->last_admin_reply_at)->toIso8601String(),
        ];
    }
}
