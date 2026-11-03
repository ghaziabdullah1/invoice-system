<?php

namespace App\Repository\Admin\AiWebhook;

use App\Constants\Constants;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class AiWebhookRepository implements AiWebhookInterface
{
    /** تحقق Meta من ملكية الـ Webhook (طلب GET) */
    public function verify(?string $token): array
    {
        $expected = config('services.whatsapp.verify_token');

        if (empty($expected) || !hash_equals($expected, (string) $token)) {
            return ['status' => false, 'message' => 'Invalid verify token'];
        }

        return ['status' => true, 'message' => 'Verified'];
    }

    /** استقبال الرسائل (طلب POST): نتحقق من التوقيع أولاً ثم نحفظ ونطلق الـ Job */
    public function handle(string $payload, ?string $sigHeader): array
    {
        try {
            $secret = config('services.whatsapp.app_secret');

            if (empty($secret)) {
                Log::critical('WhatsApp app secret is not configured');
                return ['status' => false, 'message' => 'Webhook is not configured'];
            }

            $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

            if (!hash_equals($expected, (string) $sigHeader)) {
                Log::warning('WhatsApp webhook signature mismatch');
                return ['status' => false, 'message' => 'Invalid signature'];
            }

            $data = json_decode($payload, true) ?: [];

            foreach ((array) ($data['entry'] ?? []) as $entry) {
                foreach ((array) ($entry['changes'] ?? []) as $change) {
                    foreach ((array) ($change['value']['messages'] ?? []) as $message) {
                        $this->storeMessage($message);
                    }
                }
            }

            return ['status' => true, 'message' => 'Webhook handled'];
        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook error: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Webhook processing failed'];
        }
    }

    private function storeMessage(array $msg): void
    {
        $from = $msg['from'] ?? null;
        $id   = $msg['id'] ?? null;

        if (!$from || !$id) {
            return;
        }

        $type = $msg['type'] ?? 'text';
        $body = $type === 'text' ? mb_substr((string) ($msg['text']['body'] ?? ''), 0, 1000) : null;

        $conversation = AiConversation::firstOrCreate(
            ['channel' => Constants::AI_CHANNEL_WHATSAPP, 'contact' => $from],
            ['status' => Constants::AI_CONVERSATION_BOT]
        );

        try {
            $message = AiMessage::create([
                'ai_conversation_id' => $conversation->id,
                'direction'          => Constants::AI_DIRECTION_IN,
                'type'               => $type,
                'body'               => $body,
                'status'             => Constants::AI_MESSAGE_RECEIVED,
                'external_id'        => $id,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return; // رسالة مكررة أعادت Meta إرسالها
        }

        $conversation->update(['last_message_at' => now()]);

        ProcessIncomingMessageJob::dispatch($message->id);
    }
}
