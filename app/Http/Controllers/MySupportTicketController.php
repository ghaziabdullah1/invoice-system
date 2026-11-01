<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Repository\Admin\SupportTicket\SupportTicketInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

// تذاكر المستخدم العادي: كل استعلام مقيّد بـ user_id، ولا شيء من الملاحظات الداخلية يخرج
class MySupportTicketController extends Controller
{
    use ResponseTrait;

    public function __construct(protected SupportTicketInterface $repo) {}

    private function owned(int $id): ?SupportTicket
    {
        return SupportTicket::where('user_id', auth('sanctum')->id())->find($id);
    }

    private function publicReplies(SupportTicket $ticket)
    {
        return $ticket->replies()
            ->where('is_internal', false)
            ->with('user:id,name')
            ->orderBy('created_at')->orderBy('id')
            ->get();
    }

    public function index(Request $request)
    {
        $table   = (new SupportTicket)->getTable();
        $perPage = min(max((int) $request->input('per_page', 30), 1), 100);

        $tickets = SupportTicket::where('user_id', auth('sanctum')->id())
            ->withCount(['replies as replies_count' => fn ($q) => $q->where('is_internal', false)])
            ->addSelect(['last_message' => SupportTicketReply::select('message')
                ->whereColumn('ticket_id', "$table.id")
                ->where('is_internal', false)
                ->orderByDesc('id')
                ->limit(1)])
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        return $this->successResponse(__('messages.support_tickets_fetched'), [
            'items'      => $tickets->items(),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page'    => $tickets->lastPage(),
                'per_page'     => $tickets->perPage(),
                'total'        => $tickets->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $ticket = $this->owned($id);
        if (!$ticket) {
            return $this->failureResponse(__('messages.support_ticket_not_found'), null, 404);
        }

        $ticket->load('assignedTo:id,name');
        $ticket->setRelation('replies', $this->publicReplies($ticket));

        return $this->successResponse(__('messages.support_ticket_fetched'), $ticket);
    }

    public function reply(Request $request, int $id)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $ticket = $this->owned($id);
        if (!$ticket) {
            return $this->failureResponse(__('messages.support_ticket_not_found'), null, 404);
        }
        if ($ticket->status === 'closed') {
            return $this->failureResponse('التذكرة مغلقة، افتح تذكرة جديدة', null, 422);
        }

        // المستخدم لا يملك حق إنشاء ملاحظة داخلية مهما أرسل
        $request->merge(['is_internal' => false]);

        $result = $this->repo->reply($request, $ticket);
        if (!$result['status']) {
            return $this->failureResponse($result['message']);
        }

        return $this->successResponse($result['message'], [
            'reply'   => $result['data']['reply'],
            'replies' => $this->publicReplies($ticket->fresh()),
            'status'  => $result['data']['status'],
        ]);
    }
}
