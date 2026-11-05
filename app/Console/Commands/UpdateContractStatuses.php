<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\Contract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateContractStatuses extends Command
{
    protected $signature = 'contracts:update-statuses';

    protected $description = 'يحوّل العقود السارية التي انتهت مدتها إلى «منتهٍ»، أو «مجدَّد» إن كان لها عقد تجديد';

    public function handle(): int
    {
        $expired = 0;
        $renewed = 0;

        Contract::active()
            ->whereDate('end_date', '<', today()->format(Constants::DATE_FORMAT))
            ->withCount('renewals')
            ->chunkById(100, function ($contracts) use (&$expired, &$renewed) {
                foreach ($contracts as $contract) {
                    try {
                        if ($contract->renewals_count > 0) {
                            $contract->update(['status' => Constants::CONTRACT_STATUS_RENEWED]);
                            $renewed++;
                        } else {
                            $contract->update(['status' => Constants::CONTRACT_STATUS_EXPIRED]);
                            $expired++;
                        }
                    } catch (\Throwable $e) {
                        Log::error("فشل تحديث حالة العقد #{$contract->id}: {$e->getMessage()}");
                        $this->error("❌ العقد #{$contract->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->info("✅ الملخص: {$expired} عقد أصبح منتهياً، {$renewed} عقد أصبح مجدَّداً.");

        return self::SUCCESS;
    }
}
