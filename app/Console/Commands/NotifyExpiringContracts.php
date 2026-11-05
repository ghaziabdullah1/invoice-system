<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Mail\ContractExpiringMail;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotifyExpiringContracts extends Command
{
    // من الأصغر للأكبر: يُرسل تنبيه واحد لكل عتبة، ويُلحَق ما فات إن تأخر التشغيل
    private const THRESHOLDS = [30, 60, 90];

    protected $signature = 'contracts:notify-expiring {--dry-run : عرض ما سيُرسل دون إرسال أو كتابة}';

    protected $description = 'يرسل تنبيه انتهاء العقود السارية قبل 90 و60 و30 يوماً إلى من يملك صلاحية التجديد ومُسنَد له العقار';

    public function handle(): int
    {
        $dry     = (bool) $this->option('dry-run');
        $today   = today();
        $horizon = $today->copy()->addDays(max(self::THRESHOLDS));

        $alerted = 0;
        $mails   = 0;
        $failed  = 0;

        Contract::active()
            ->whereDate('end_date', '>=', $today->format(Constants::DATE_FORMAT))
            ->whereDate('end_date', '<=', $horizon->format(Constants::DATE_FORMAT))
            ->whereDoesntHave('renewals')
            ->with(['tenant:id,name', 'unit.floor:id,name', 'property:id,name,name_en'])
            ->chunkById(100, function ($contracts) use ($dry, $today, &$alerted, &$mails, &$failed) {
                foreach ($contracts as $contract) {
                    $endDate   = $contract->end_date->format(Constants::DATE_FORMAT);
                    $days      = (int) round($today->diffInDays($contract->end_date->copy()->startOfDay(), false));
                    $threshold = $this->thresholdFor($days);
                    $last      = $contract->expiry_alert_days;

                    if ($threshold === null || ($last !== null && $threshold >= (int) $last)) {
                        continue;
                    }

                    $label      = "العقد {$contract->contract_number} — ينتهي {$endDate} (بعد {$days} يوم، عتبة {$threshold})";
                    $recipients = $this->recipients((int) $contract->property_id);

                    if ($recipients->isEmpty()) {
                        Log::warning("لا يوجد مستلمون لتنبيه انتهاء العقد: {$label}");
                        $this->error("❌ لا يوجد مستلمون: {$label}");
                        $failed++;
                        continue;
                    }

                    if ($dry) {
                        $this->line("• سيُرسل: {$label} ← " . $recipients->pluck('email')->implode(', '));
                        $alerted++;
                        continue;
                    }

                    $sent = 0;

                    foreach ($recipients as $recipient) {
                        try {
                            Mail::to($recipient->email)->send(new ContractExpiringMail($contract, $days));
                            $sent++;
                        } catch (\Throwable $e) {
                            Log::error("فشل إرسال تنبيه العقد #{$contract->id} إلى {$recipient->email}: {$e->getMessage()}");
                        }
                    }

                    // لا نسجّل العتبة إلا إذا وصلت رسالة واحدة على الأقل، فيُعاد المحاولة في اليوم التالي عند الفشل
                    if ($sent > 0) {
                        Contract::whereKey($contract->id)->update(['expiry_alert_days' => $threshold]);
                        $alerted++;
                        $mails += $sent;
                        $this->info("✅ {$label} ← {$sent} مستلم");
                    } else {
                        $failed++;
                        $this->error("❌ فشل الإرسال: {$label}");
                    }
                }
            });

        $this->info(($dry ? '(تجربة) ' : '') . "الملخص: {$alerted} عقد " . ($dry ? 'سيُنبَّه' : 'نُبِّه') . "، {$mails} رسالة، {$failed} فشل أو بلا مستلمين.");

        return self::SUCCESS;
    }

    private function thresholdFor(int $days): ?int
    {
        foreach (self::THRESHOLDS as $threshold) {
            if ($days <= $threshold) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * من يملك صلاحية التجديد ومُسنَد له العقار. وإن لم يوجد أحد فالسوبر أدمن (حتى لا يضيع التنبيه).
     */
    private function recipients(int $propertyId): Collection
    {
        $withPermission = User::where('is_active', true)
            ->whereNotNull('email')
            ->whereHas('adminGroup.permissions', fn ($q) => $q
                ->where('title', Constants::RENEW_CONTRACT)
                ->where('is_active', Constants::ACTIVE))
            ->whereHas('properties', fn ($q) => $q->where('properties.id', $propertyId))
            ->get(['id', 'name', 'email']);

        if ($withPermission->isNotEmpty()) {
            return $withPermission;
        }

        return User::where('is_active', true)
            ->whereNotNull('email')
            ->where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)
            ->get(['id', 'name', 'email']);
    }
}
