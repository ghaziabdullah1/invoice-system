<?php

namespace App\Events;

use App\Models\SupportTicket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SupportTicketCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public SupportTicket $ticket) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('support-staff')];

        if ($this->ticket->user_id) {
            $channels[] = new PrivateChannel('support.user.' . $this->ticket->user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'support.ticket.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id'            => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'subject'       => $this->ticket->subject,
            'name'          => $this->ticket->name,
            'status'        => $this->ticket->status,
            'priority'      => $this->ticket->priority,
            'user_id'       => $this->ticket->user_id,
            'assigned_to'   => $this->ticket->assigned_to,
        ];
    }
}
