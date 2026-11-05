<?php

namespace App\Repository\Admin\SupportTicket;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\ActivityLog;
use App\Mail\NewSupportTicketMail;
use App\Mail\SupportTicketAssignedMail;
use App\Mail\SupportTicketReplyMail;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportTicketRepository implements SupportTicketInterface
{
    public function index($request)
    {
        try {
            $query = SupportTicket::with(['user:id,name', 'assignedTo:id,name'])
                ->withCount('replies')
                ->orderBy('created_at', 'desc');

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }
            if ($request->filled('priority')) {
                $query->where('priority', $request->priority);
            }
            if ($request->filled('assigned_to')) {
                // ✅ يدعم "me" للفلترة على تذاكري أنا كموظف مسجّل دخول
                $assignedTo = $request->assigned_to === 'me' ? auth('sanctum')->id() : $request->assigned_to;
                $query->where('assigned_to', $assignedTo);
            }
            if ($request->filled('unassigned') && $request->boolean('unassigned')) {
                $query->whereNull('assigned_to');
            }
            if ($request->filled('search')) {
                $search = substr(trim($request->search), 0, 100);
                $query->where(function ($q) use ($search) {
                    $q->where('ticket_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            }

            $perPage = (int) ($request->per_page ?? Constants::DEFAULT_PER_PAGE);
            $perPage = min(max($perPage, Constants::MIN_PER_PAGE), Constants::MAX_PER_PAGE);

            $tickets = $query->paginate($perPage);

            return [
                'status'  => true,
                'message' => __('messages.support_tickets_fetched'),
                'data'    => [
                    'items'      => $tickets->items(),
                    'pagination' => [
                        'current_page' => $tickets->currentPage(),
                        'last_page'    => $tickets->lastPage(),
                        'per_page'     => $tickets->perPage(),
                        'total'        => $tickets->total(),
                    ],
                ],
            ];
        } catch (\Exception $e) {
            Log::error('SupportTicketRepository index error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function show($id)
    {
        try {
            if (!is_numeric($id) || (int) $id <= 0) {
                return ['status' => false, 'message' => __('messages.support_ticket_not_found'), 'data' => null];
            }

            $ticket = SupportTicket::with(['replies.user:id,name', 'user:id,name', 'assignedTo:id,name'])
                ->find((int) $id);

            if (!$ticket) {
                return ['status' => false, 'message' => __('messages.support_ticket_not_found'), 'data' => null];
            }

            // ✅ الأدمن يشوف كل شيء بما فيه الملاحظات الداخلية + الـ timeline الحقيقي
            $ticket->setAttribute('timeline', $this->buildTimeline($ticket, includeInternal: true));

            return ['status' => true, 'message' => __('messages.support_ticket_fetched'), 'data' => $ticket];
        } catch (\Exception $e) {
            Log::error('SupportTicketRepository show error', ['id' => $id, 'error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    // ✅ endpoint عام — يعتمد ticket_number + email بدل id، ويستثني الملاحظات الداخلية
    public function track($request)
    {
        try {
            $ticket = SupportTicket::with(['replies' => function ($q) {
                $q->where('is_internal', false)->with('user:id,name');
            }])
                ->where('ticket_number', $request->ticket_number)
                ->where('email', strtolower(trim($request->email)))
                ->first();

            if (!$ticket) {
                return ['status' => false, 'message' => __('messages.support_ticket_not_found'), 'data' => null];
            }

            $ticket->setAttribute('timeline', $this->buildTimeline($ticket, includeInternal: false));

            return ['status' => true, 'message' => __('messages.support_ticket_fetched'), 'data' => $ticket];
        } catch (\Exception $e) {
            Log::error('SupportTicketRepository track error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    // public function store($request)
    // {
    //     DB::beginTransaction();
    //     try {
    //         $ticket = SupportTicket::create([
    //             'ticket_number' => $this->generateTicketNumber(),
    //             'user_id'       => auth('sanctum')->id(),
    //             'name'          => $request->name,
    //             'email'         => strtolower(trim($request->email)),
    //             'subject'       => $request->subject,
    //             'message'       => $request->message,
    //             'priority'      => $request->priority ?? 'medium',
    //             'status'        => 'open',
    //         ]);

    //         ActivityLog::log('CREATE', __('messages.support_ticket_created') . ': ' . $ticket->ticket_number, $ticket);
    //         DB::commit();

    //         // ✅ إشعار الفريق بتذكرة جديدة (خارج الـ transaction عشان فشل الإيميل ما يرجع التذكرة)
    //         $this->notifyNewTicket($ticket);

    //         return ['status' => true, 'message' => __('messages.support_ticket_created'), 'data' => $ticket];
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         Log::error('SupportTicketRepository store error', ['error' => $e->getMessage()]);
    //         return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
    //     }
    // }

    public function store($request)
    {
        DB::beginTransaction();
        try {
            $ticket = SupportTicket::create([
                'ticket_number' => $this->generateTicketNumber(),
                'user_id'       => auth('sanctum')->id(),
                'name'          => $request->name,
                'email'         => strtolower(trim($request->email)),
                'subject'       => $request->subject,
                'message'       => $request->message,
                'priority'      => $request->priority ?? 'medium',
                'status'        => 'open',
            ]);

            ActivityLog::log('CREATE', __('messages.support_ticket_created') . ': ' . $ticket->ticket_number, $ticket);
            DB::commit();

            // ✅ إشعار الفريق (الأدمن)
            $this->notifyNewTicket($ticket);

            // ✅ إشعار العميل بأن التذكرة تم استلامها
            $this->notifyCustomerTicketCreated($ticket);

            return ['status' => true, 'message' => __('messages.support_ticket_created'), 'data' => $ticket];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SupportTicketRepository store error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    private function notifyCustomerTicketCreated(SupportTicket $ticket): void
    {
        try {
            Mail::to($ticket->email)->queue(new \App\Mail\SupportTicketCreatedMail($ticket));
            Log::info('Customer confirmation email queued', ['email' => $ticket->email, 'ticket' => $ticket->ticket_number]);
        } catch (\Exception $e) {
            Log::error('Customer confirmation email failed', ['error' => $e->getMessage(), 'ticket' => $ticket->ticket_number]);
        }
    }

    public function reply($request, $ticket)
    {
        DB::beginTransaction();
        try {
            // موظف الدعم = السوبر أدمن أو مجموعة الأدمن (المحاسب والمبيعات يبقون في خانة المستخدمين)
            $actor = auth('sanctum')->user();
            $isAdminReply = (bool) ($actor && ($actor->isSuperAdmin() || $actor->isAdmin()));
            // ✅ is_internal يُفرض false من السيرفر لو الراد مش أدمن — لا نثق بمدخل الفرونت هنا
            $isInternal = $isAdminReply ? (bool) $request->boolean('is_internal') : false;

            $reply = SupportTicketReply::create([
                'ticket_id'      => $ticket->id,
                'user_id'        => auth('sanctum')->id(),
                'message'        => $request->message,
                'is_admin_reply' => $isAdminReply,
                'is_internal'    => $isInternal,
            ]);

            if ($isAdminReply) {
                $ticket->last_admin_reply_at = now();
                if ($ticket->status === 'closed') $ticket->status = 'in_progress';
            } else {
                $ticket->last_customer_reply_at = now();
            }
            $ticket->save();

            ActivityLog::log('CREATE', __('messages.support_ticket_reply_added') . ': ' . $ticket->ticket_number, $ticket);
            DB::commit();

            // ✅ ملاحظة داخلية لا تُرسل إيميل للعميل إطلاقًا
            if (!$isInternal) {
                $this->notifyReply($ticket, $request->message, $isAdminReply);
            }

            return [
                'status'  => true,
                'message' => __('messages.support_ticket_reply_added'),
                'data'    => [
                    'reply'   => $reply,
                    'replies' => $ticket->replies()->with('user:id,name')->get(),
                    'status'  => $ticket->status,
                ],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SupportTicketRepository reply error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function updateStatus($request, $ticket)
    {
        DB::beginTransaction();
        try {
            $oldStatus = $ticket->status;
            $newStatus = $request->status;

            if ($oldStatus === $newStatus) {
                DB::commit();
                return ['status' => true, 'message' => __('messages.support_ticket_status_updated'), 'data' => $ticket->load(['replies.user:id,name'])];
            }

            $ticket->status = $newStatus;
            $ticket->save();

            if ($newStatus === 'closed') {
                SupportTicketReply::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => auth('sanctum')->id(),
                    'message' => __('messages.support_ticket_closed_note'),
                    'is_admin_reply' => true,
                ]);
            } elseif ($oldStatus === 'closed') {
                SupportTicketReply::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => auth('sanctum')->id(),
                    'message' => __('messages.support_ticket_reopened_note'),
                    'is_admin_reply' => true,
                ]);
            }

            ActivityLog::log(
                'UPDATE',
                __('messages.support_ticket_status_updated') . ': ' . $ticket->ticket_number,
                $ticket,
                ['status' => $oldStatus],
                ['status' => $newStatus]
            );

            DB::commit();

            return ['status' => true, 'message' => __('messages.support_ticket_status_updated'), 'data' => $ticket->load(['replies.user:id,name'])];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SupportTicketRepository updateStatus error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    // ✅ جديد — تعيين/إلغاء تعيين التذكرة
    public function assign($request, $ticket)
    {
        DB::beginTransaction();
        try {
            $oldAssignee = $ticket->assigned_to;
            $ticket->assigned_to = $request->assigned_to;
            $ticket->save();

            ActivityLog::log(
                'UPDATE',
                __('messages.support_ticket_assigned') . ': ' . $ticket->ticket_number,
                $ticket,
                ['assigned_to' => $oldAssignee],
                ['assigned_to' => $ticket->assigned_to]
            );

            DB::commit();

            if ($ticket->assigned_to) {
                $this->notifyAssignment($ticket->fresh());
            }

            return ['status' => true, 'message' => __('messages.support_ticket_assigned'), 'data' => $ticket->load('assignedTo:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SupportTicketRepository assign error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function destroy($ticket)
    {
        DB::beginTransaction();
        try {
            $ticketNumber = $ticket->ticket_number;
            $ticketId = $ticket->id;
            ActivityLog::log('DELETE', __('messages.support_ticket_deleted') . ': ' . $ticketNumber, $ticket);
            $ticket->replies()->delete();
            $ticket->delete();
            DB::commit();
            return ['status' => true, 'message' => __('messages.support_ticket_deleted'), 'data' => ['id' => $ticketId]];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SupportTicketRepository destroy error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    private function generateTicketNumber(): string
    {
        do {
            $number = 'TKT-' . strtoupper(bin2hex(random_bytes(4)));
        } while (SupportTicket::where('ticket_number', $number)->exists());
        return $number;
    }

    // ⚠️ يعتمد أن ActivityLog له أعمدة subject_type/subject_id (Polymorphic) —
    //    تأكد من هذا بمراجعة migration/model الفعلي وعدّل أسماء الأعمدة لو مختلفة
    private function buildTimeline(SupportTicket $ticket, bool $includeInternal): array
    {
        $activities = ActivityLog::where('model_type', SupportTicket::class)
            ->where('model_id', $ticket->id)
            ->get()
            ->map(fn($log) => [
                'type'        => 'activity',
                'action'      => $log->action,
                'description' => $log->description,
                'created_at'  => $log->created_at,
            ]);

        $replies = $ticket->replies()
            ->when(!$includeInternal, fn($q) => $q->where('is_internal', false))
            ->with('user:id,name')
            ->get()
            ->map(fn($r) => [
                'type'       => 'reply',
                'reply'      => $r,
                'created_at' => $r->created_at,
            ]);

        return $activities->concat($replies)->sortBy('created_at')->values()->all();
    }

    private function notifyNewTicket(SupportTicket $ticket): void
    {
        $notifyEmail = config('mail.support_notify_address');
        if ($notifyEmail) {
            Mail::to($notifyEmail)->queue(new NewSupportTicketMail($ticket));
        }
    }

    private function notifyReply(SupportTicket $ticket, string $message, bool $fromAdmin): void
    {
        if ($fromAdmin) {
            // رد الأدمن → إشعار العميل
            Mail::to($ticket->email)->queue(new SupportTicketReplyMail($ticket, $message, true));
        } else {
            // رد العميل → إشعار الموظف المعيّن أو عنوان الدعم العام
            $to = $ticket->assignedTo?->email ?? config('mail.support_notify_address');
            if ($to) {
                Mail::to($to)->queue(new SupportTicketReplyMail($ticket, $message, false));
            }
        }
    }

    private function notifyAssignment(SupportTicket $ticket): void
    {
        if ($ticket->assignedTo?->email) {
            Mail::to($ticket->assignedTo->email)->queue(new SupportTicketAssignedMail($ticket));
        }
    }
}
