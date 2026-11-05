<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Contract;
use App\Models\User;
use App\Services\ContractInvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateContractInvoices extends Command
{
    protected $signature = 'contracts:generate-invoices {--days=14 : عدد الأيام قبل الاستحقاق} {--dry-run : عرض ما سيُنشأ دون كتابة}';

    protected $description = 'ينشئ مسودات فواتير الإيجار للفترات التي يحل استحقاقها خلال الأيام القادمة (لا ينشئ فترات سابقة)';

    public function handle(ContractInvoiceService $service): int
    {
        $days  = max(0, min((int) $this->option('days'), 60));
        $dry   = (bool) $this->option('dry-run');
        $today = today();
        $limit = today()->addDays($days);

        $fallbackUser = User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)->value('id');

        $created = 0;
        $skipped = 0;
        $failed  = 0;

        Contract::active()
            ->whereDate('end_date', '>=', $today->format(Constants::DATE_FORMAT))
            ->with(['tenant', 'unit.floor', 'property'])
            ->chunkById(50, function ($contracts) use ($service, $dry, $today, $limit, $fallbackUser, &$created, &$skipped, &$failed) {
                foreach ($contracts as $contract) {
                    foreach ($service->periods($contract) as $period) {
                        $due = Carbon::parse($period['due_date']);

                        if ($due->lt($today) || $due->gt($limit)) {
                            continue;
                        }

                        if ($service->existingInvoice((int) $contract->id, $period['start'])) {
                            $skipped++;
                            continue;
                        }

                        $label = "العقد {$contract->contract_number} — الفترة {$period['number']} ({$period['start']}) استحقاق {$period['due_date']}";

                        if ($dry) {
                            $this->line("• سيُنشأ: {$label}");
                            $created++;
                            continue;
                        }

                        $result = $service->generate($contract, $period['number'], $contract->created_by ?: $fallbackUser);

                        if ($result['status']) {
                            $created++;
                            $this->info("✅ {$label} → {$result['data']->invoice_number}");
                        } else {
                            $failed++;
                            Log::warning("فشل توليد فاتورة إيجار: {$label}: {$result['message']}");
                            $this->error("❌ {$label}: {$result['message']}");
                        }
                    }
                }
            });

        $this->info(($dry ? '(تجربة) ' : '') . "الملخص: {$created} " . ($dry ? 'ستُنشأ' : 'أُنشئت') . "، {$skipped} موجودة مسبقاً، {$failed} فشلت.");

        return self::SUCCESS;
    }
}
