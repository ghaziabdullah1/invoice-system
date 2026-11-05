<?php

namespace App\Console\Commands;

use App\Constants\Constants;
use App\Models\ActivityLog;
use App\Models\PurchaseOrderApproval;
use App\Models\User;
use Illuminate\Console\Command;

class EscalatePendingApprovals extends Command
{
    protected $signature = 'procurement:escalate';

    protected $description = 'تصعيد الموافقات المتأخرة إلى المدير العام بعد انتهاء المهلة';

    public function handle(): int
    {
        $overdue = PurchaseOrderApproval::where('status', PurchaseOrderApproval::STATUS_PENDING)
            ->where('deadline_at', '<', now())
            ->whereNull('escalated_at')
            ->with('purchaseOrder:id,po_number')
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('لا توجد موافقات متأخرة.');
            return self::SUCCESS;
        }

        $gm = User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)
            ->where('is_active', true)
            ->first();

        if (!$gm) {
            $this->error('لا يوجد مدير عام نشط للتصعيد إليه.');
            return self::FAILURE;
        }

        foreach ($overdue as $approval) {
            $approval->update([
                'status'          => PurchaseOrderApproval::STATUS_ESCALATED,
                'escalated_at'    => now(),
                'escalated_to_id' => $gm->id,
                // المسؤولية تبقى على الموافق الأصلي، والمُصعَّد إليه في escalated_to_id
            ]);

            ActivityLog::log(
                'ESCALATE',
                'تصعيد موافقة متأخرة: ' . $approval->purchaseOrder?->po_number . ' (الخطوة ' . $approval->step . ')',
                $approval->purchaseOrder
            );

            $this->line("✓ تم تصعيد {$approval->purchaseOrder?->po_number} — خطوة {$approval->step}");
        }

        $this->info("تم تصعيد {$overdue->count()} موافقة.");

        return self::SUCCESS;
    }
}
