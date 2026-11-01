<?php

namespace App\Events;

use App\Models\SupportTicketReply;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class SupportMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public SupportTicketReply $reply) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('support-staff')];

        // الملاحظة الداخلية لا تصل للعميل ابدا
        if (!$this->reply->is_internal) {
            $channels[] = new PrivateChannel('support.ticket.' . $this->reply->ticket_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'support.message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'id'             => $this->reply->id,
            'ticket_id'      => $this->reply->ticket_id,
            'user_id'        => $this->reply->user_id,
            'sender_name'    => User::find($this->reply->user_id)?->name,
            'message'        => $this->reply->message,
            'is_admin_reply' => (bool) $this->reply->is_admin_reply,
            'is_internal'    => (bool) $this->reply->is_internal,
            'created_at'     => optional($this->reply->created_at)->toIso8601String(),
        ];
    }
}
