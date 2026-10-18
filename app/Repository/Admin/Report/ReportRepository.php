<?php

namespace App\Repository\Admin\Report;

use App\Models\Invoice;
use App\Models\Client;
use App\Models\Payment;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceDueSoonMail;
use App\Mail\InvoiceOverdueMail;

class ReportRepository implements ReportInterface
{
    public function getInvoiceReport(array $filters = []): array
    {
        try {
            $query = Invoice::with([
                // select فقط الحقول المطلوبة من العلاقات
                'client:id,name,email,company_name',
                'items:id,invoice_id,description,quantity,unit_price,total',
            ])
                ->when(!empty($filters['start_date']), fn($q) => $q->whereDate('invoice_date', '>=', $filters['start_date']))
                ->when(!empty($filters['end_date']),   fn($q) => $q->whereDate('invoice_date', '<=', $filters['end_date']))
                ->when(!empty($filters['status']),     fn($q) => $q->where('status', $filters['status']))
                ->when(!empty($filters['client_id']),  fn($q) => $q->where('client_id', (int) $filters['client_id']))
                ->when(!empty($filters['user_id']),    fn($q) => $q->where('user_id', (int) $filters['user_id']))
                ->when(!empty($filters['branch_id']),  fn($q) => $q->where('branch_id', (int) $filters['branch_id']))
                ->orderBy('invoice_date', 'desc');

            $perPage  = min((int) ($filters['per_page'] ?? 20), 100);
            $invoices = $query->paginate($perPage);

            $statsQuery = Invoice::when(!empty($filters['start_date']), fn($q) => $q->whereDate('invoice_date', '>=', $filters['start_date']))
                ->when(!empty($filters['end_date']),   fn($q) => $q->whereDate('invoice_date', '<=', $filters['end_date']))
                ->when(!empty($filters['status']),     fn($q) => $q->where('status', $filters['status']))
                ->when(!empty($filters['client_id']),  fn($q) => $q->where('client_id', (int) $filters['client_id']))
                ->when(!empty($filters['branch_id']),  fn($q) => $q->where('branch_id', (int) $filters['branch_id']))
                ->selectRaw('
                    COUNT(*) as total_invoices,
                    SUM(total) as total_amount,
                    SUM(CASE WHEN status = ? THEN total ELSE 0 END) as total_paid,
                    SUM(CASE WHEN status IN (?,?) THEN total ELSE 0 END) as total_due
                ', [
                    Constants::INVOICE_STATUS_PAID,
                    Constants::INVOICE_STATUS_SENT,
                    Constants::INVOICE_STATUS_OVERDUE,
                ])
                ->first();

            return [
                'items'      => $invoices->items(),
                'stats'      => [
                    'total_invoices' => (int)   ($statsQuery->total_invoices ?? 0),
                    'total_amount'   => (float)  ($statsQuery->total_amount   ?? 0),
                    'total_paid'     => (float)  ($statsQuery->total_paid     ?? 0),
                    'total_due'      => (float)  ($statsQuery->total_due      ?? 0),
                ],
                'pagination' => [
                    'current_page' => $invoices->currentPage(),
                    'last_page'    => $invoices->lastPage(),
                    'per_page'     => $invoices->perPage(),
                    'total'        => $invoices->total(),
                    'from'         => $invoices->firstItem(),
                    'to'           => $invoices->lastItem(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ReportRepository getInvoiceReport error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getClientReport(array $filters = []): array
    {
        try {
            $query = Client::withCount(['invoices' => function ($q) use ($filters) {
                $this->applyDateFilters($q, $filters);
                $this->applyBranchFilter($q, $filters);
            }])
                ->withSum(['invoices as invoices_sum_total' => function ($q) use ($filters) {
                    $this->applyDateFilters($q, $filters);
                    $this->applyBranchFilter($q, $filters);
                }], 'total')
                ->withSum(['invoices as paid_sum' => function ($q) use ($filters) {
                    $this->applyDateFilters($q, $filters);
                    $this->applyBranchFilter($q, $filters);
                    $q->where('status', Constants::INVOICE_STATUS_PAID);
                }], 'total')
                ->when(!empty($filters['branch_id']), fn($q) => $q->where('branch_id', (int) $filters['branch_id']))
                ->orderBy('invoices_count', 'desc');

            if (!empty($filters['client_id'])) {
                $query->where('id', (int) $filters['client_id']);
            }

            $perPage = min((int) ($filters['per_page'] ?? 20), 100);
            $clients = $query->paginate($perPage);

            $collection    = $clients->getCollection();
            $totalRevenue  = $collection->sum('invoices_sum_total');
            $activeClients = $collection->where('invoices_count', '>', 0)->count();

            return [
                'items' => $collection->map(function ($client) {
                    $total   = (float) ($client->invoices_sum_total ?? 0);
                    $paid    = (float) ($client->paid_sum           ?? 0);
                    $count   = (int)   ($client->invoices_count     ?? 0);

                    return [
                        'id'              => $client->id,
                        'name'            => $client->name,
                        'email'           => $client->email,
                        'phone'           => $client->phone,
                        'company_name'    => $client->company_name,
                        'invoices_count'  => $count,
                        'total_spent'     => $total,
                        'total_paid'      => $paid,
                        'total_due'       => $total - $paid,
                        'average_invoice' => $count > 0 ? round($total / $count, 2) : 0,
                        'created_at'      => $client->created_at?->format('Y-m-d'),
                    ];
                })->toArray(),
                'stats' => [
                    'total_clients'  => $clients->total(),
                    'active_clients' => $activeClients,
                    'total_invoices' => $collection->sum('invoices_count'),
                    'total_revenue'  => $totalRevenue,
                ],
                'pagination' => [
                    'current_page' => $clients->currentPage(),
                    'last_page'    => $clients->lastPage(),
                    'per_page'     => $clients->perPage(),
                    'total'        => $clients->total(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ReportRepository getClientReport error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getRevenueReport(array $filters = []): array
    {
        try {
            $revenueData = Invoice::select(
                DB::raw('DATE_FORMAT(invoice_date, "%Y-%m") as month'),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('SUM(total) as total_amount'),
                DB::raw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) as paid_amount')
            )
                ->addBinding(Constants::INVOICE_STATUS_PAID, 'select')
                ->when(!empty($filters['start_date']), fn($q) => $q->whereDate('invoice_date', '>=', $filters['start_date']))
                ->when(!empty($filters['end_date']),   fn($q) => $q->whereDate('invoice_date', '<=', $filters['end_date']))
                ->when(!empty($filters['status']),     fn($q) => $q->where('status', $filters['status']))
                ->when(!empty($filters['branch_id']),  fn($q) => $q->where('branch_id', (int) $filters['branch_id']))
                ->groupBy('month')
                ->orderBy('month', 'desc')
                ->get();

            $totalAmount     = $revenueData->sum('total_amount');
            $collectedAmount = $revenueData->sum('paid_amount');

            return [
                'items' => $revenueData->map(fn($item) => [
                    'month'         => $item->month,
                    'invoice_count' => (int)   $item->invoice_count,
                    'total_amount'  => (float)  $item->total_amount,
                    'paid_amount'   => (float)  $item->paid_amount,
                    'due_amount'    => (float) ($item->total_amount - $item->paid_amount),
                ]),
                'stats' => [
                    'total_revenue'       => (float) $totalAmount,
                    'collected_revenue'   => (float) $collectedAmount,
                    'outstanding_revenue' => (float) ($totalAmount - $collectedAmount),
                    'collection_rate'     => $totalAmount > 0
                        ? round(($collectedAmount / $totalAmount) * 100, 2)
                        : 0,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ReportRepository getRevenueReport error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getOverdueReport(array $filters = []): array
    {
        try {
            $overdueInvoices = Invoice::select([
                'id',
                'invoice_number',
                'client_id',
                'due_date',
                'total',
                'status',
                'currency',
            ])
                ->with('client:id,name,email')
                ->where(function ($q) {
                    $q->where('status', Constants::INVOICE_STATUS_OVERDUE)
                        ->orWhere(function ($sub) {
                            $sub->where('status', Constants::INVOICE_STATUS_SENT)
                                ->whereDate('due_date', '<', now());
                        });
                })
                ->when(!empty($filters['start_date']), fn($q) => $q->whereDate('invoice_date', '>=', $filters['start_date']))
                ->when(!empty($filters['end_date']),   fn($q) => $q->whereDate('invoice_date', '<=', $filters['end_date']))
                ->when(!empty($filters['branch_id']),  fn($q) => $q->where('branch_id', (int) $filters['branch_id']))
                ->orderBy('due_date', 'asc')
                ->get();

            $now   = now();
            $items = $overdueInvoices->map(function ($invoice) use ($now) {
                $daysOverdue = max(0, $now->diffInDays($invoice->due_date));

                return [
                    'id'             => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client'         => [
                        'id'    => $invoice->client_id,
                        'name'  => $invoice->client->name  ?? 'غير محدد',
                        'email' => $invoice->client->email ?? null,
                    ],
                    'due_date'       => $invoice->due_date,
                    'total_amount'   => (float) $invoice->total,
                    'status'         => $invoice->status,
                    'days_overdue'   => $daysOverdue,
                    'currency'       => $invoice->currency,
                ];
            });

            $totalAmount    = $overdueInvoices->sum('total');
            $avgDaysOverdue = $items->avg('days_overdue');

            return [
                'items' => $items,
                'stats' => [
                    'total_overdue'      => $overdueInvoices->count(),
                    'total_amount'       => (float) $totalAmount,
                    'average_days_overdue' => (int) round($avgDaysOverdue ?? 0),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ReportRepository getOverdueReport error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getAgingReport(array $filters = []): array
    {
        try {
            // نفس معيار "المتأخر" المستخدم بالضبط في getOverdueReport()
            $overdueInvoices = Invoice::select([
                'id',
                'invoice_number',
                'client_id',
                'due_date',
                'total',
                'status',
                'currency',
            ])
                ->with('client:id,name,email')
                ->where(function ($q) {
                    $q->where('status', Constants::INVOICE_STATUS_OVERDUE)
                        ->orWhere(function ($sub) {
                            $sub->where('status', Constants::INVOICE_STATUS_SENT)
                                ->whereDate('due_date', '<', now());
                        });
                })
                ->when(!empty($filters['client_id']), fn($q) => $q->where('client_id', (int) $filters['client_id']))
                ->when(!empty($filters['branch_id']), fn($q) => $q->where('branch_id', (int) $filters['branch_id']))
                ->orderBy('due_date', 'asc')
                ->get();

            $now        = now();
            $buckets    = ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0];
            $clientsMap = [];

            foreach ($overdueInvoices as $invoice) {
                $daysOverdue = max(0, $now->diffInDays($invoice->due_date));
                $bucketKey   = $this->getAgingBucketKey($daysOverdue);
                $amount      = (float) $invoice->total;
                $clientId    = $invoice->client_id;

                if (!isset($clientsMap[$clientId])) {
                    $clientsMap[$clientId] = [
                        'client_id'      => $clientId,
                        'client_name'    => $invoice->client->name  ?? 'غير محدد',
                        'client_email'   => $invoice->client->email ?? null,
                        'bucket_0_30'    => 0,
                        'bucket_31_60'   => 0,
                        'bucket_61_90'   => 0,
                        'bucket_90_plus' => 0,
                        'total_due'      => 0,
                        'invoices_count' => 0,
                    ];
                }

                $clientsMap[$clientId]['bucket_' . $bucketKey] += $amount;
                $clientsMap[$clientId]['total_due']            += $amount;
                $clientsMap[$clientId]['invoices_count']       += 1;

                $buckets[$bucketKey] += $amount;
            }

            // ترتيب حسب أعلى مديونية أولاً
            $items = collect(array_values($clientsMap))
                ->sortByDesc('total_due')
                ->values();

            return [
                'items' => $items,
                'stats' => [
                    'total_clients'        => $items->count(),
                    'total_due'            => (float) array_sum($buckets),
                    'bucket_0_30_total'    => (float) $buckets['0_30'],
                    'bucket_31_60_total'   => (float) $buckets['31_60'],
                    'bucket_61_90_total'   => (float) $buckets['61_90'],
                    'bucket_90_plus_total' => (float) $buckets['90_plus'],
                ],
            ];
        } catch (\Exception $e) {
            Log::error('ReportRepository getAgingReport error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getDashboardStats(?int $branchId = null): array
    {
        try {
            $invoiceStats = Invoice::when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->selectRaw('
                COUNT(*) as total_invoices,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid_invoices,
                SUM(CASE WHEN status = ? THEN total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as overdue_invoices,
                SUM(CASE WHEN status = ? THEN total ELSE 0 END) as overdue_amount
            ', [
                    Constants::INVOICE_STATUS_PAID,
                    Constants::INVOICE_STATUS_PAID,
                    Constants::INVOICE_STATUS_OVERDUE,
                    Constants::INVOICE_STATUS_OVERDUE,
                ])->first();

            return [
                'total_invoices'   => (int)   ($invoiceStats->total_invoices   ?? 0),
                'total_amount'     => (float)  ($invoiceStats->total_amount     ?? 0),
                'paid_invoices'    => (int)   ($invoiceStats->paid_invoices    ?? 0),
                'paid_amount'      => (float)  ($invoiceStats->paid_amount      ?? 0),
                'overdue_invoices' => (int)   ($invoiceStats->overdue_invoices ?? 0),
                'overdue_amount'   => (float)  ($invoiceStats->overdue_amount   ?? 0),
                'total_clients'    => Client::when($branchId, fn($q) => $q->where('branch_id', $branchId))->count(),
            ];
        } catch (\Exception $e) {
            Log::error('ReportRepository getDashboardStats error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function exportReport(string $type, array $filters = []): array
    {
        return match ($type) {
            'invoices' => $this->getInvoiceReport($filters),
            'clients'  => $this->getClientReport($filters),
            'revenue'  => $this->getRevenueReport($filters),
            'overdue'  => $this->getOverdueReport($filters),
            default    => throw new \InvalidArgumentException("نوع التقرير غير صالح: {$type}"),
        };
    }

    public function sendInvoiceReminder(int $invoiceId): array
    {
        $invoice = Invoice::with('client:id,name,email')
            ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
            ->findOrFail($invoiceId);

        if (!in_array($invoice->status, [Constants::INVOICE_STATUS_SENT, Constants::INVOICE_STATUS_OVERDUE], true)) {
            throw new \Exception('لا يمكن إرسال تذكير لفاتورة في حالتها الحالية');
        }

        if (empty($invoice->client?->email)) {
            throw new \Exception('لا يوجد بريد إلكتروني للعميل');
        }

        $mailClass = $invoice->status === Constants::INVOICE_STATUS_OVERDUE
            ? InvoiceOverdueMail::class
            : InvoiceDueSoonMail::class;

        Mail::to($invoice->client->email)->queue(new $mailClass($invoice));

        return [
            'message' => 'تم إرسال التذكير بنجاح',
            'invoice' => $invoice,
        ];
    }

    public function markInvoiceAsPaid(int $invoiceId): array
    {
        DB::beginTransaction();

        try {
            $invoice = Invoice::with('client:id,name,email,currency')
                ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->findOrFail($invoiceId);

            if ($invoice->status === Constants::INVOICE_STATUS_PAID) {
                throw new \Exception('الفاتورة مدفوعة بالفعل');
            }

            $invoice->update([
                'status'  => Constants::INVOICE_STATUS_PAID,
                'paid_at' => now(),
            ]);

            Payment::create([
                'invoice_id'      => $invoiceId,
                'client_id'       => $invoice->client_id,
                'user_id'         => auth()->id(),
                'amount'          => $invoice->total,
                'currency'        => $invoice->currency ?? 'SAR',
                'status'          => Constants::PAYMENT_STATUS_COMPLETED,
                'payment_method'  => 'manual',
                'payment_gateway' => 'manual',
                'paid_at'         => now(),
                'metadata'        => [
                    'source' => 'report_system',
                    'notes'  => 'تم التسديد من خلال التقارير',
                ],
            ]);

            DB::commit();

            return [
                'message' => 'تم تسديد الفاتورة بنجاح',
                'invoice' => $invoice->fresh(['client:id,name,email']),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ReportRepository markInvoiceAsPaid error', [
                'invoice_id' => $invoiceId,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ================================================================
    // Private Helpers
    // ================================================================

    private function applyDateFilters($query, array $filters): void
    {
        if (!empty($filters['start_date'])) {
            $query->whereDate('invoice_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('invoice_date', '<=', $filters['end_date']);
        }
    }

    /**
     * ✅ إضافة: فلترة حسب الفرع - تستخدم داخل الـ closures المتداخلة
     * (withCount/withSum) بنفس طريقة applyDateFilters()
     */
    private function applyBranchFilter($query, array $filters): void
    {
        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }
    }

    /**
     * تصنيف عدد أيام التأخير إلى فئة عمرية
     */
    private function getAgingBucketKey(int $daysOverdue): string
    {
        if ($daysOverdue <= 30) return '0_30';
        if ($daysOverdue <= 60) return '31_60';
        if ($daysOverdue <= 90) return '61_90';
        return '90_plus';
    }
}
