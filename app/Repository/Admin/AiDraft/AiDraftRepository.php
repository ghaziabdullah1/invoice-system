<?php

namespace App\Repository\Admin\AiDraft;

use App\Constants\Constants;
use App\Models\ActivityLog;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\WhatsAppSender;
use Illuminate\Support\Facades\Log;

class AiDraftRepository implements AiDraftInterface
{
    public function __construct(protected WhatsAppSender $sender)
    {
    }

    public function index($request)
    {
        try {
            $perPage = min(max((int) $request->input('per_page', 20), 5), 100);
            $status  = $request->input('status', Constants::AI_MESSAGE_DRAFT);

            $page = AiMessage::with(['conversation:id,contact,status'])
                ->where('direction', Constants::AI_DIRECTION_OUT)
                ->where('status', $status)
                ->orderByDesc('id')
                ->paginate($perPage);

            return [
                'status' => true,
                'data'   => [
                    'items'      => $page->items(),
                    'pagination' => [
                        'total'        => $page->total(),
                        'per_page'     => $page->perPage(),
                        'current_page' => $page->currentPage(),
                        'last_page'    => $page->lastPage(),
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('AiDraft index: ' . $e->getMessage());
            return ['status' => false, 'message' => __('messages.error'), 'data' => null];
        }
    }

    public function show($id)
    {
        $draft = AiMessage::with('conversation')
            ->where('direction', Constants::AI_DIRECTION_OUT)
            ->find($id);

        if (!$draft) {
            return ['status' => false, 'message' => __('messages.not_found'), 'data' => null, 'code' => Constants::RESPONSE_NOT_FOUND];
        }

        return ['status' => true, 'data' => $draft];
    }

    /** يرسل المسودة (بنصها الأصلي أو المعدّل) ثم يسجّل العملية */
    public function approve($draft, $body = null)
    {
        if ($draft->status !== Constants::AI_MESSAGE_DRAFT) {
            return ['status' => false, 'message' => 'Draft already processed', 'data' => null, 'code' => Constants::RESPONSE_BAD_REQUEST];
        }

        try {
            $text = trim((string) ($body ?? $draft->body));
            $to   = $draft->conversation->contact;

            $externalId = $this->sender->sendText($to, $text);

            $old = $draft->toArray();
            $draft->update([
                'body'        => $text,
                'status'      => Constants::AI_MESSAGE_SENT,
                'external_id' => $externalId,
            ]);

            ActivityLog::log('UPDATE', 'AI draft approved: #' . $draft->id, $draft, $old, $draft->fresh()->toArray());

            return ['status' => true, 'data' => $draft->fresh()];
        } catch (\Throwable $e) {
            Log::error('AiDraft approve: ' . $e->getMessage());
            $draft->update(['status' => Constants::AI_MESSAGE_FAILED]);
            return ['status' => false, 'message' => __('messages.error'), 'data' => null];
        }
    }

    public function reject($draft)
    {
        if ($draft->status !== Constants::AI_MESSAGE_DRAFT) {
            return ['status' => false, 'message' => 'Draft already processed', 'data' => null, 'code' => Constants::RESPONSE_BAD_REQUEST];
        }

        $draft->update(['status' => Constants::AI_MESSAGE_REJECTED]);
        ActivityLog::log('UPDATE', 'AI draft rejected: #' . $draft->id, $draft);

        return ['status' => true, 'data' => $draft->fresh()];
    }

    /** يعيد المحادثة من human إلى bot */
    public function resumeBot($conversationId)
    {
        $conversation = AiConversation::find($conversationId);

        if (!$conversation) {
            return ['status' => false, 'message' => __('messages.not_found'), 'data' => null, 'code' => Constants::RESPONSE_NOT_FOUND];
        }

        $conversation->update(['status' => Constants::AI_CONVERSATION_BOT]);

        return ['status' => true, 'data' => $conversation->fresh()];
    }
}
