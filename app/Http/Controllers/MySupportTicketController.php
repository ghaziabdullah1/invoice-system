<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Repository\Admin\SupportTicket\SupportTicketInterface;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    // ردود الدعم العلنية التي وصلت بعد آخر قراءة (أو كلها إن لم تُقرأ التذكرة بعد)
    private function unreadScope($query, string $ticketTable, string $replyTable)
    {
        return $query->where("$replyTable.is_internal", false)
            ->where("$replyTable.is_admin_reply", true)
            ->where(function ($w) use ($ticketTable, $replyTable) {
                $w->whereNull("$ticketTable.customer_last_read_at")
                    ->orWhereColumn("$replyTable.created_at", '>', "$ticketTable.customer_last_read_at");
            });
    }

    private function markRead(int $ticketId): void
    {
        // DB مباشر: لا نغيّر updated_at ولا نطلق أحداث الموديل
        DB::table((new SupportTicket)->getTable())->where('id', $ticketId)->update(['customer_last_read_at' => now()]);
    }

    public function index(Request $request)
    {
        $table      = (new SupportTicket)->getTable();
        $replyTable = (new SupportTicketReply)->getTable();
        $perPage    = min(max((int) $request->input('per_page', 30), 1), 100);

        $tickets = SupportTicket::where('user_id', auth('sanctum')->id())
            ->withCount(['replies as replies_count' => fn ($q) => $q->where('is_internal', false)])
            ->withCount(['replies as unread_count' => fn ($q) => $this->unreadScope($q, $table, $replyTable)])
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

    public function unreadCount()
    {
        $table      = (new SupportTicket)->getTable();
        $replyTable = (new SupportTicketReply)->getTable();

        $count = SupportTicketReply::whereHas('ticket', function ($q) use ($table, $replyTable) {
            $q->where("$table.user_id", auth('sanctum')->id());
            $this->unreadScope($q->getQuery(), $table, $replyTable);
        })->count();

        return $this->successResponse('ok', ['count' => $count]);
    }

    public function read(int $id)
    {
        $ticket = $this->owned($id);
        if (!$ticket) {
            return $this->failureResponse(__('messages.support_ticket_not_found'), null, 404);
        }

        $this->markRead($ticket->id);

        return $this->successResponse('ok', ['id' => $ticket->id]);
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

        // من يرسل رداً فقد قرأ المحادثة
        $this->markRead($ticket->id);

        return $this->successResponse($result['message'], [
            'reply'   => $result['data']['reply'],
            'replies' => $this->publicReplies($ticket->fresh()),
            'status'  => $result['data']['status'],
        ]);
    }
}
