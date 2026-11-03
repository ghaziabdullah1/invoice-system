<?php

namespace App\Jobs;

use App\Constants\Constants;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AiAgentService;
use App\Services\WhatsAppSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $messageId)
    {
    }

    public function handle(AiAgentService $ai, WhatsAppSender $sender): void
    {
        $incoming = AiMessage::with('conversation')->find($this->messageId);
        if (!$incoming || !$incoming->conversation) {
            return;
        }

        $conversation = $incoming->conversation;
        $cfg  = config('services.ai_agent');
        $lang = $this->languageOf($incoming->body);

        // 1) محادثة محوّلة لإنسان: لا رد آلي
        if ($conversation->status === Constants::AI_CONVERSATION_HUMAN) {
            return;
        }

        // 2) حد الرسائل لكل رقم في الساعة (يمنع السبام واستهلاك الرصيد)
        $lastHour = $conversation->messages()
            ->where('direction', Constants::AI_DIRECTION_IN)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($lastHour > $cfg['hourly_limit_per_chat']) {
            return;
        }

        // 3) رسائل لا تحتاج ذكاء اصطناعي: قوالب ثابتة بلا تكلفة وبلغة العميل
        $key = $this->templateFor($incoming);
        if ($key) {
            // الوسائط غير النصية لا تحمل لغة: نستخدم لغة آخر رسالة نصية من العميل
            $lang = $lang ?? $this->conversationLanguage($conversation);
            $this->deliver($conversation, $this->template($key, $lang), 0, 0, $sender);
            return;
        }

        // 4) السقف اليومي: نحوّل لإنسان بدل استهلاك الرصيد
        if ($ai->dailyCapReached()) {
            Log::warning('AI daily token cap reached');
            $conversation->update(['status' => Constants::AI_CONVERSATION_HUMAN]);
            $this->deliver($conversation, $this->template('busy', $lang), 0, 0, $sender);
            return;
        }

        // 5) استدعاء النموذج (يرد بلغة العميل حسب تعليمات الـ System Prompt)
        try {
            $result = $ai->reply($conversation);
        } catch (Throwable $e) {
            Log::error('AI reply failed: ' . $e->getMessage());
            return;
        }

        if ($result['text'] === '') {
            return;
        }

        if ($result['handoff']) {
            $conversation->update(['status' => Constants::AI_CONVERSATION_HUMAN]);
        }

        $this->deliver($conversation, $result['text'], $result['input'], $result['output'], $sender);
    }

    /** حروف عربية ← ar، نص آخر ← en، لا نص ← null */
    protected function languageOf(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return preg_match('/\p{Arabic}/u', $text) ? Constants::AI_LANG_AR : Constants::AI_LANG_EN;
    }

    /** لغة آخر رسالة نصية أرسلها العميل في هذه المحادثة */
    protected function conversationLanguage(AiConversation $conversation): ?string
    {
        $body = $conversation->messages()
            ->where('direction', Constants::AI_DIRECTION_IN)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('id')
            ->value('body');

        return $this->languageOf($body);
    }

    /** نص القالب بلغة العميل، وإن لم تُعرف اللغة نرسل اللغتين معاً */
    protected function template(string $key, ?string $lang): string
    {
        $t = config("services.ai_agent.templates.{$key}", []);

        if ($lang && isset($t[$lang])) {
            return $t[$lang];
        }

        return trim(($t[Constants::AI_LANG_AR] ?? '') . "\n\n" . ($t[Constants::AI_LANG_EN] ?? ''));
    }

    protected function templateFor(AiMessage $m): ?string
    {
        if ($m->type !== 'text' || $m->body === null || trim($m->body) === '') {
            return 'unsupported';
        }

        $t = trim(mb_strtolower($m->body));

        if (preg_match('/^(شكرا|شكراً|مشكور|تسلم|يعطيك العافية|thanks|thank you|thx|ok|okay|تمام|اوكي|👍)[\s!.،]*$/u', $t)) {
            return 'thanks';
        }
        if (preg_match('/^(مرحبا|مرحباً|السلام عليكم|هلا|اهلا|أهلا|صباح الخير|مساء الخير|hi|hello|hey|good morning|good evening)[\s!.،]*$/u', $t)) {
            return 'greeting';
        }

        return null;
    }

    /** يحفظ الرد كمسودة، أو يرسله مباشرة إذا كان AI_AUTO_REPLY=true */
    protected function deliver(AiConversation $conversation, string $body, int $in, int $out, WhatsAppSender $sender): void
    {
        $message = AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'direction'          => Constants::AI_DIRECTION_OUT,
            'type'               => 'text',
            'body'               => $body,
            'status'             => Constants::AI_MESSAGE_DRAFT,
            'input_tokens'       => $in,
            'output_tokens'      => $out,
        ]);

        if (!config('services.ai_agent.auto_reply')) {
            return;
        }

        try {
            $externalId = $sender->sendText($conversation->contact, $body);
            $message->update(['status' => Constants::AI_MESSAGE_SENT, 'external_id' => $externalId]);
        } catch (Throwable $e) {
            Log::error('WhatsApp send failed: ' . $e->getMessage());
            $message->update(['status' => Constants::AI_MESSAGE_FAILED]);
        }
    }
}
