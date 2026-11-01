<?php

namespace App\Helpers;

use App\Events\InvoiceNotificationUpdated;
use App\Events\InvoiceDueDateAlert;
use App\Models\Invoice;
use App\Models\SupportTicket;
use App\Constants\Constants;
use Illuminate\Support\Facades\Log;

class InvoiceNotificationHelper
{
    /**
     * ✅ العدادات: فواتير + تذاكر مفتوحة
     *
     * عدادات الفواتير الثلاثة في استعلام واحد (بدل ثلاثة)، والنتيجة نفسها:
     * overdue و due_soon كلاهما ضمن unpaid (status != paid) و is_active،
     * لذلك نطبّق الشرطين مرة واحدة ثم نفرّع بـ CASE.
     */
    public static function getCounts(): array
    {
        $today = now()->toDateString();
        $nextWeek = now()->addDays(7)->toDateString();

        $invoiceCounts = Invoice::query()
            ->where('is_active', true)
            ->where('status', '!=', Constants::INVOICE_STATUS_PAID)
            ->selectRaw('COUNT(*) AS unpaid')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS overdue', [Constants::INVOICE_STATUS_OVERDUE])
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND due_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS due_soon',
                [Constants::INVOICE_STATUS_SENT, $today, $nextWeek]
            )
            ->first();

        $unpaidCount = (int) $invoiceCounts->unpaid;
        $overdueCount = (int) $invoiceCounts->overdue;
        $dueSoonCount = (int) $invoiceCounts->due_soon;

        $openTicketsCount = SupportTicket::whereIn('status', ['open', 'in_progress'])
            ->count();

        return [
            'unpaid'       => $unpaidCount,
            'overdue'      => $overdueCount,
            'due_soon'     => $dueSoonCount,
            'open_tickets' => $openTicketsCount,
            'total'        => $unpaidCount + $overdueCount + $dueSoonCount + $openTicketsCount,
        ];
    }

    public static function broadcastCounts(?string $trigger = null): void
    {
        $counts = self::getCounts();

        Log::info('📢 Broadcasting notification counts', [
            'trigger'      => $trigger,
            'unpaid'       => $counts['unpaid'],
            'overdue'      => $counts['overdue'],
            'due_soon'     => $counts['due_soon'],
            'open_tickets' => $counts['open_tickets'],
        ]);

        event(new InvoiceNotificationUpdated(
            $counts['unpaid'],
            $counts['overdue'],
            $counts['due_soon'],
            $counts['open_tickets'],
            $trigger
        ));
    }

    public static function broadcastDueDateAlerts($dueSoon, $newlyOverdue): void
    {
        if ($dueSoon->isNotEmpty()) {
            event(new InvoiceDueDateAlert('due_soon', self::formatInvoicesForAlert($dueSoon)));
        }
        if ($newlyOverdue->isNotEmpty()) {
            event(new InvoiceDueDateAlert('overdue', self::formatInvoicesForAlert($newlyOverdue)));
        }
        self::broadcastCounts('due_date_check');
    }

    private static function formatInvoicesForAlert($invoices): array
    {
        return $invoices->map(function (Invoice $invoice) {
            return [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'client_name'    => $invoice->client->name ?? null,
                'client_phone'   => $invoice->client->phone ?? null,
                'due_date'       => $invoice->due_date?->format('Y-m-d'),
            ];
        })->values()->all();
    }
}
