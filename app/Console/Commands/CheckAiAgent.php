<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class CheckAiAgent extends Command
{
    protected $signature = 'ai:check {--url= : الرابط العام للموقع (النفق) لفحص الـ Webhook من الخارج} {--live-claude : استدعاء حقيقي صغير لـ Claude (يستهلك رصيداً)}';

    protected $description = 'يفحص جاهزية الموظف الذكي: الإعدادات، واتساب، الـ Webhook العام، الطابور، والاستهلاك';

    private int $failures = 0;

    public function handle(): int
    {
        $this->line('');
        $this->line('═══ فحص جاهزية الموظف الذكي ═══');

        $this->checkConfig();
        $this->checkKnowledge();
        $this->checkWhatsApp();
        $this->checkWebhook(rtrim((string) $this->option('url'), '/'));
        $this->checkQueue();
        $this->checkActivity();

        if ($this->option('live-claude')) {
            $this->checkClaude();
        }

        $this->line('');

        if ($this->failures > 0) {
            $this->error("❌ {$this->failures} مشكلة تحتاج إصلاحاً");
            return self::FAILURE;
        }

        $this->info('✅ لا مشاكل حرجة');
        return self::SUCCESS;
    }

    private function pass(string $m): void
    {
        $this->line("  ✅ {$m}");
    }

    private function bad(string $m): void
    {
        $this->failures++;
        $this->line("  ❌ {$m}");
    }

    private function note(string $m): void
    {
        $this->line("  ⚠️  {$m}");
    }

    private function section(string $title): void
    {
        $this->line('');
        $this->line("── {$title} ──");
    }

    private function checkConfig(): void
    {
        $this->section('الإعدادات (لا تُطبع القيم)');

        $wa = (array) config('services.whatsapp');

        $required = [
            'WHATSAPP_TOKEN'           => $wa['token'] ?? null,
            'WHATSAPP_PHONE_NUMBER_ID' => $wa['phone_number_id'] ?? null,
            'WHATSAPP_APP_SECRET'      => $wa['app_secret'] ?? null,
            'WHATSAPP_VERIFY_TOKEN'    => $wa['verify_token'] ?? null,
        ];

        foreach ($required as $name => $value) {
            empty($value) ? $this->bad("{$name} فارغ") : $this->pass("{$name} مضبوط");
        }

        if (in_array($wa['app_secret'] ?? '', ['test123', 'local-test-secret'], true)) {
            $this->bad('WHATSAPP_APP_SECRET قيمة اختبار، ضع السر الحقيقي من إعدادات التطبيق في Meta');
        }

        if (empty(config('services.anthropic.key'))) {
            $this->note('ANTHROPIC_API_KEY فارغ: القوالب الثابتة تعمل، وأي سؤال آخر يُحوَّل لموظف');
        } else {
            $this->pass('ANTHROPIC_API_KEY مضبوط');
        }

        $yn = fn ($v) => $v ? 'مفعّل' : 'مغلق';
        $this->line('  النموذج: ' . config('services.anthropic.model') . ' | Graph: ' . ($wa['graph_version'] ?? '?'));
        $this->line('  إرسال القوالب تلقائياً: ' . $yn(config('services.ai_agent.auto_reply_templates')) . ' | إرسال ردود النموذج تلقائياً: ' . $yn(config('services.ai_agent.auto_reply')));
    }

    private function checkKnowledge(): void
    {
        $this->section('ملف معلومات الشركة');

        $path = storage_path('app/ai/knowledge.md');

        if (!is_file($path)) {
            $this->bad('storage/app/ai/knowledge.md غير موجود');
            return;
        }

        $left = substr_count((string) file_get_contents($path), '...');

        $left > 0
            ? $this->note("فيه {$left} موضع لم يُعبَّأ بعد (...)، وسيحوّل النموذج الأسئلة المتعلقة بها لموظف")
            : $this->pass('الملف مُعبّأ');
    }

    private function checkWhatsApp(): void
    {
        $this->section('واتساب (Graph API)');

        $cfg = (array) config('services.whatsapp');

        if (empty($cfg['token']) || empty($cfg['phone_number_id'])) {
            $this->note('تخطيت: التوكن أو معرّف الرقم غير مضبوط');
            return;
        }

        $base = rtrim((string) ($cfg['base_url'] ?? 'https://graph.facebook.com'), '/');

        try {
            $r = Http::withToken($cfg['token'])->timeout(15)->get(
                "{$base}/{$cfg['graph_version']}/{$cfg['phone_number_id']}",
                ['fields' => 'display_phone_number,verified_name']
            );
        } catch (\Throwable $e) {
            $this->bad('تعذر الاتصال بـ Graph API: ' . substr($e->getMessage(), 0, 100));
            return;
        }

        if ($r->successful()) {
            $this->pass('التوكن ومعرّف الرقم صحيحان: ' . $r->json('display_phone_number') . ' (' . $r->json('verified_name') . ')');
            return;
        }

        $this->bad("رُفض الطلب {$r->status()}: " . $r->json('error.message', 'خطأ غير معروف'));

        if ((int) $r->json('error.code') === 190) {
            $this->line('     ← التوكن منتهٍ. توكن الاختبار المؤقت يدوم 24 ساعة، ولّد غيره من API Setup ثم شغّل set-ai-env.sh');
        }
    }

    private function checkWebhook(string $url): void
    {
        $this->section('الـ Webhook من الخارج (كما تفعل Meta)');

        if ($url === '') {
            $this->note('لم يُمرَّر --url، تخطيت فحص الوصول من الخارج');
            return;
        }

        $token = (string) config('services.whatsapp.verify_token');
        if ($token === '') {
            $this->bad('WHATSAPP_VERIFY_TOKEN فارغ');
            return;
        }

        $endpoint = $url . '/api/ai/whatsapp/webhook';

        try {
            $ok  = Http::timeout(20)->get($endpoint, ['hub.mode' => 'subscribe', 'hub.verify_token' => $token, 'hub.challenge' => 'ai-check-123']);
            $bad = Http::timeout(20)->get($endpoint, ['hub.mode' => 'subscribe', 'hub.verify_token' => 'wrong-token', 'hub.challenge' => 'x']);
        } catch (\Throwable $e) {
            $this->bad('تعذر الوصول للرابط: ' . substr($e->getMessage(), 0, 100));
            return;
        }

        trim($ok->body()) === 'ai-check-123' && $ok->status() === 200
            ? $this->pass('التحقق بالرمز الصحيح يعيد الـ challenge')
            : $this->bad("التحقق بالرمز الصحيح لم ينجح (الرمز {$ok->status()})");

        $bad->status() === 403
            ? $this->pass('الرمز الخاطئ يُرفض بـ 403')
            : $this->bad("الرمز الخاطئ أعاد {$bad->status()} وكان المتوقع 403");

        $this->line("  رابط Callback الذي تضعه في Meta:  {$endpoint}");
    }

    private function checkQueue(): void
    {
        $this->section('الطابور');

        try {
            $this->line('  Jobs بانتظار المعالجة: ' . DB::table('jobs')->count());

            if (Schema::hasTable('failed_jobs')) {
                $failed = DB::table('failed_jobs')->count();
                $failed > 0 ? $this->note("Jobs فاشلة: {$failed} (php artisan queue:failed)") : $this->pass('لا Jobs فاشلة');
            }
        } catch (\Throwable $e) {
            $this->bad('تعذر قراءة جداول الطابور');
        }

        $workers = (int) trim((string) @shell_exec('pgrep -f "queue:work" 2>/dev/null | wc -l'));

        $workers > 0
            ? $this->pass("عامل الطابور شغّال ({$workers})")
            : $this->note('لا يوجد queue:work شغّال: الرسائل الواردة لن تُعالَج حتى تشغّله');
    }

    private function checkActivity(): void
    {
        $this->section('النشاط والاستهلاك');

        $cap  = (int) config('services.ai_agent.daily_token_cap');
        $used = AiUsage::todayTokens();
        $this->line("  استهلاك اليوم: {$used} / {$cap} tokens");

        $drafts = AiMessage::where('direction', Constants::AI_DIRECTION_OUT)->where('status', Constants::AI_MESSAGE_DRAFT)->count();
        $human  = AiConversation::where('status', Constants::AI_CONVERSATION_HUMAN)->count();
        $this->line("  مسودات بانتظار المراجعة: {$drafts} | محادثات محوّلة لموظف: {$human}");

        $last = AiMessage::where('direction', Constants::AI_DIRECTION_IN)->latest('id')->first();
        $this->line('  آخر رسالة واردة: ' . ($last ? $last->created_at->diffForHumans() : 'لا يوجد'));
    }

    private function checkClaude(): void
    {
        $this->section('Claude (استدعاء حقيقي، يستهلك رصيداً صغيراً جداً)');

        $key = config('services.anthropic.key');
        if (empty($key)) {
            $this->bad('ANTHROPIC_API_KEY فارغ');
            return;
        }

        $base = rtrim((string) (config('services.anthropic.base_url') ?: 'https://api.anthropic.com'), '/');

        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(30)->post("{$base}/v1/messages", [
                'model'      => config('services.anthropic.model'),
                'max_tokens' => 16,
                'messages'   => [['role' => 'user', 'content' => 'Reply with one word: OK']],
            ]);
        } catch (\Throwable $e) {
            $this->bad('تعذر الاتصال: ' . substr($e->getMessage(), 0, 100));
            return;
        }

        $r->successful()
            ? $this->pass('رد النموذج: ' . trim((string) $r->json('content.0.text')) . ' (tokens ' . $r->json('usage.input_tokens') . '/' . $r->json('usage.output_tokens') . ')')
            : $this->bad("{$r->status()}: " . $r->json('error.message', 'خطأ غير معروف'));
    }
}
