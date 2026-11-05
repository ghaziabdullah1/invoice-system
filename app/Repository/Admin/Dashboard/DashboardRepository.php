<?php

namespace App\Repository\Admin\Dashboard;

use App\Constants\Constants;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardRepository implements DashboardInterface
{
    private const RECENT_LIMIT = 5;
    private const RECENT_ACTIVITY_LIMIT = 10;

    private const MONTH_NAMES = [
        '01' => 'يناير',
        '02' => 'فبراير',
        '03' => 'مارس',
        '04' => 'أبريل',
        '05' => 'مايو',
        '06' => 'يونيو',
        '07' => 'يوليو',
        '08' => 'أغسطس',
        '09' => 'سبتمبر',
        '10' => 'أكتوبر',
        '11' => 'نوفمبر',
        '12' => 'ديسمبر',
    ];

    public function getDashboardData(User $user, ?int $branchId = null): array
    {
        try {
            $today = Carbon::today();
            $currentMonth = Carbon::now()->startOfMonth();
            $lastMonth = Carbon::now()->subMonth()->startOfMonth();
            $twelveMonthsAgo = Carbon::now()->subMonths(11)->startOfMonth();

            // إحصائيات كاملة (استعلامان فقط: فواتير + عملاء)
            $stats = $this->getCompleteStats($today, $currentMonth, $lastMonth, $branchId);

            // نمو العملاء يُحسب مرة واحدة ويُستخدم في آخر العملاء وأفضل العملاء
            $clientGrowth = $this->getClientGrowthRates($branchId);

            // نجلب آخر العملاء وآخر الفواتير مرة واحدة ونعيد استخدامها في أكثر من قسم
            $recentClientModels = Client::when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->latest()
                ->take(self::RECENT_LIMIT)
                ->get();

            $latestInvoices = Invoice::with('client')
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->latest()
                ->take(self::RECENT_ACTIVITY_LIMIT)
                ->get();

            // البيانات الحديثة
            $recentClients = $this->getRecentClients($recentClientModels, $clientGrowth, $branchId);
            $recentInvoices = $this->getRecentInvoices($latestInvoices->take(self::RECENT_LIMIT));
            $monthlyRevenue = $this->getMonthlyRevenue($twelveMonthsAgo, $branchId);
            $overdueInvoices = $this->getOverdueInvoices($branchId);
            $recentActivity = $this->getRecentActivity($latestInvoices, $recentClientModels->take(2), $branchId);
            $topClients = $this->getTopClients(self::RECENT_LIMIT, $clientGrowth, $branchId);

            // بيانات الأداء الشهري للرسم البياني
            $performanceData = $this->getPerformanceData($twelveMonthsAgo, $branchId);

            return [
                'stats' => $stats,
                'recentClients' => $recentClients,
                'recentInvoices' => $recentInvoices,
                'monthlyRevenue' => $monthlyRevenue,
                'overdueInvoices' => $overdueInvoices,
                'recentActivity' => $recentActivity,
                'topClients' => $topClients,
                'invoiceStatuses' => $this->buildInvoiceStatuses($stats),
                'performanceData' => $performanceData,
                'summary' => $this->buildSummary($this->calculateTodayPerformance($stats)),
            ];
        } catch (\Exception $e) {
            Log::error('Dashboard data aggregation failed: ' . $e->getMessage());

            return $this->getFallbackData();
        }
    }

    /**
     * كل إحصائيات الفواتير في استعلام تجميعي واحد (بدل ~24 استعلاماً)،
     * وإحصائيات العملاء في استعلام واحد.
     */
    private function getCompleteStats(Carbon $today, Carbon $currentMonth, Carbon $lastMonth, ?int $branchId = null): array
    {
        $now = Carbon::now();
        $tomorrow = $today->copy()->addDay();
        $avgWindowStart = Carbon::now()->subMonths(11)->startOfMonth();

        $paid = Constants::INVOICE_STATUS_PAID;
        $sent = Constants::INVOICE_STATUS_SENT;
        $overdue = Constants::INVOICE_STATUS_OVERDUE;
        $draft = Constants::INVOICE_STATUS_DRAFT;

        $nowStr = $now->toDateTimeString();
        $currentMonthStr = $currentMonth->toDateTimeString();
        $lastMonthStr = $lastMonth->toDateTimeString();
        $todayStr = $today->toDateTimeString();
        $tomorrowStr = $tomorrow->toDateTimeString();
        $avgStartStr = $avgWindowStart->toDateTimeString();

        // كل تعبير مع الـ bindings الخاصة به، لتفادي أخطاء ترتيب المعاملات
        $columns = [
            'COUNT(*) AS total_invoices' => [],
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS paid_count' => [$paid],
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS pending_count' => [$sent],
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS overdue_count' => [$overdue],
            'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS draft_count' => [$draft],
            'SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS paid_amount' => [$paid],
            'SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS pending_amount' => [$sent],
            'SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS overdue_amount' => [$overdue],
            'SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS draft_amount' => [$draft],
            'SUM(CASE WHEN status = ? AND paid_at BETWEEN ? AND ? THEN total ELSE 0 END) AS current_month_revenue'
            => [$paid, $currentMonthStr, $nowStr],
            'SUM(CASE WHEN status = ? AND paid_at BETWEEN ? AND ? THEN total ELSE 0 END) AS last_month_revenue'
            => [$paid, $lastMonthStr, $currentMonthStr],
            'SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS current_month_invoices'
            => [$currentMonthStr, $nowStr],
            'SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS last_month_invoices'
            => [$lastMonthStr, $currentMonthStr],
            'SUM(CASE WHEN status = ? AND paid_at >= ? AND paid_at < ? THEN 1 ELSE 0 END) AS today_paid_invoices'
            => [$paid, $todayStr, $tomorrowStr],
            'SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS today_total_invoices'
            => [$todayStr, $tomorrowStr],
            'SUM(CASE WHEN status = ? AND paid_at >= ? THEN total ELSE 0 END) AS avg_window_revenue'
            => [$paid, $avgStartStr],
            'MIN(CASE WHEN status = ? AND paid_at >= ? THEN paid_at END) AS avg_window_first_paid'
            => [$paid, $avgStartStr],
        ];

        $invoiceQuery = Invoice::query()->when($branchId, fn($q) => $q->where('branch_id', $branchId));
        foreach ($columns as $sql => $bindings) {
            $invoiceQuery->selectRaw($sql, $bindings);
        }
        $row = $invoiceQuery->first();

        $clientRow = Client::query()
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw('COUNT(*) AS total_clients')
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS current_month_clients', [$currentMonthStr, $nowStr])
            ->selectRaw('SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS last_month_clients', [$lastMonthStr, $currentMonthStr])
            ->first();

        $totalInvoices = (int) $row->total_invoices;
        $paidInvoices = (int) $row->paid_count;
        $paidAmount = (float) $row->paid_amount;
        $currentMonthRevenue = (float) $row->current_month_revenue;
        $lastMonthRevenue = (float) $row->last_month_revenue;
        $currentMonthInvoices = (int) $row->current_month_invoices;
        $lastMonthInvoices = (int) $row->last_month_invoices;
        $currentMonthClients = (int) $clientRow->current_month_clients;
        $lastMonthClients = (int) $clientRow->last_month_clients;

        // متوسط الإيراد الشهري خلال آخر 12 شهراً (أو منذ أول دفعة إن كانت أحدث)
        $avgMonths = $row->avg_window_first_paid
            ? max(1, min(12, (int) round(Carbon::parse($row->avg_window_first_paid)->startOfMonth()->diffInMonths(Carbon::now()->startOfMonth())) + 1))
            : 1;
        $avgMonthlyRevenue = (float) $row->avg_window_revenue / $avgMonths;

        return [
            'totalRevenue' => $paidAmount,
            'totalInvoices' => $totalInvoices,
            'totalClients' => (int) $clientRow->total_clients,
            'paidInvoices' => $paidInvoices,
            'pendingInvoices' => (int) $row->pending_count,
            'overdueInvoices' => (int) $row->overdue_count,
            'draftInvoices' => (int) $row->draft_count,
            'revenueGrowth' => round($this->calculateGrowth($currentMonthRevenue, $lastMonthRevenue), 2),
            'invoiceGrowth' => round($this->calculateGrowth($currentMonthInvoices, $lastMonthInvoices), 2),
            'clientsGrowth' => round($this->calculateGrowth($currentMonthClients, $lastMonthClients), 2),
            'paymentRate' => round($totalInvoices > 0 ? ($paidInvoices / $totalInvoices) * 100 : 0, 2),
            'avgMonthlyRevenue' => round($avgMonthlyRevenue, 2),
            'paidAmount' => $paidAmount,
            'pendingAmount' => (float) $row->pending_amount,
            'overdueAmount' => (float) $row->overdue_amount,
            'draftAmount' => (float) $row->draft_amount,
            'todayPaidInvoices' => (int) $row->today_paid_invoices,
            'todayTotalInvoices' => (int) $row->today_total_invoices,
            'currentMonthRevenue' => $currentMonthRevenue,
            'currentMonthInvoices' => $currentMonthInvoices,
            'currentMonthClients' => $currentMonthClients,
        ];
    }

    /**
     * آخر العملاء: استعلام واحد لمجموع مدفوعات وعدد فواتير كل العملاء الخمسة
     * (بدل استعلامين لكل عميل).
     */
    private function getRecentClients(Collection $clients, array $clientGrowth, ?int $branchId = null): array
    {
        if ($clients->isEmpty()) {
            return [];
        }

        $totals = Invoice::query()
            ->whereIn('client_id', $clients->pluck('id'))
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw('client_id, COUNT(*) AS invoices_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS spent', [Constants::INVOICE_STATUS_PAID])
            ->groupBy('client_id')
            ->get()
            ->keyBy('client_id');

        return $clients->map(function ($client) use ($clientGrowth, $totals) {
            $row = $totals->get($client->id);

            return [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'phone' => $client->phone,
                'company_name' => $client->company_name ?: 'غير محدد',
                'status' => $client->status ?: 'active',
                'total_spent' => (float) ($row->spent ?? 0),
                'total_invoices' => (int) ($row->invoices_count ?? 0),
                'growth' => $clientGrowth[$client->id] ?? 0.0,
                'created_at' => $client->created_at->format('Y-m-d H:i:s'),
            ];
        })->toArray();
    }

    /**
     * نمو حقيقي لكل عميل: مقارنة إجمالي مدفوعاته هذا الشهر بالشهر الماضي.
     * استعلام تجميعي واحد، ويُحسب مرة واحدة لكل طلب dashboard.
     *
     * @return array<int, float> [client_id => نسبة النمو]
     */
    private function getClientGrowthRates(?int $branchId = null): array
    {
        $currentMonthStart = Carbon::now()->startOfMonth();
        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $currentMonthStartStr = $currentMonthStart->toDateTimeString();

        return Invoice::where('status', Constants::INVOICE_STATUS_PAID)
            ->where('paid_at', '>=', $lastMonthStart)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw('client_id')
            ->selectRaw('SUM(CASE WHEN paid_at >= ? THEN total ELSE 0 END) AS current_spent', [$currentMonthStartStr])
            ->selectRaw('SUM(CASE WHEN paid_at <= ? THEN total ELSE 0 END) AS last_spent', [$currentMonthStartStr])
            ->groupBy('client_id')
            ->get()
            ->mapWithKeys(fn($row) => [
                $row->client_id => round($this->calculateGrowth((float) $row->current_spent, (float) $row->last_spent), 2),
            ])
            ->all();
    }

    private function getRecentInvoices($invoices): array
    {
        return $invoices
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client_name' => $invoice->client ? $invoice->client->name : 'غير محدد',
                    'client_company' => $invoice->client ? $invoice->client->company_name : 'غير محدد',
                    'total' => (float) $invoice->total,
                    'status' => $invoice->status,
                    'issue_date' => $invoice->issue_date,
                    'due_date' => $invoice->due_date,
                    'paid_at' => $invoice->paid_at,
                    'created_at' => $invoice->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->values()
            ->toArray();
    }

    private function getMonthlyRevenue($startDate, ?int $branchId = null): array
    {
        $revenues = Invoice::where('status', Constants::INVOICE_STATUS_PAID)
            ->where('paid_at', '>=', $startDate)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->select(
                DB::raw('DATE_FORMAT(paid_at, "%Y-%m") as month'),
                DB::raw('SUM(total) as revenue'),
                DB::raw('COUNT(*) as invoice_count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $revenues->map(function ($item) {
            [$year, $month] = explode('-', $item->month);
            return [
                'month' => (self::MONTH_NAMES[$month] ?? $month) . ' ' . $year,
                'revenue' => (float) $item->revenue,
                'invoice_count' => (int) $item->invoice_count,
                'year' => $year,
                'month_number' => $month,
            ];
        })->toArray();
    }

    private function getOverdueInvoices(?int $branchId = null): array
    {
        return Invoice::where('status', Constants::INVOICE_STATUS_OVERDUE)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->with('client')
            ->latest()
            ->get()
            ->map(function ($invoice) {
                $daysOverdue = $invoice->due_date ?
                    Carbon::parse($invoice->due_date)->diffInDays(Carbon::now()) : 0;

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client_name' => $invoice->client ? $invoice->client->name : 'غير محدد',
                    'total' => (float) $invoice->total,
                    'due_date' => $invoice->due_date,
                    'days_overdue' => $daysOverdue,
                    'created_at' => $invoice->created_at->format('Y-m-d H:i:s'),
                ];
            })->toArray();
    }

    /**
     * النشاط الحديث: يعيد استخدام الفواتير والعملاء المجلوبين مسبقاً،
     * ويحتاج فقط استعلام آخر 3 فواتير مدفوعة.
     */
    private function getRecentActivity(Collection $latestInvoices, $recentClients, ?int $branchId = null): array
    {
        $activities = [];
        $counter = 1;

        foreach ($latestInvoices as $invoice) {
            $activities[] = [
                'id' => $counter++,
                'type' => 'invoice_created',
                'title' => 'فاتورة جديدة',
                'description' => 'فاتورة #' . $invoice->invoice_number . ' تم إنشاؤها للعميل ' . ($invoice->client->name ?? 'غير محدد'),
                'amount' => (float) $invoice->total,
                'user_name' => 'النظام',
                'invoiceId' => $invoice->invoice_number,
                'invoice_id' => $invoice->id,
                'timestamp' => $invoice->created_at->timestamp,
                'created_at' => $invoice->created_at->format('Y-m-d H:i:s'),
            ];
        }

        // إضافة نشاطات للفواتير المدفوعة
        $paidInvoices = Invoice::where('status', Constants::INVOICE_STATUS_PAID)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->latest()
            ->take(3)
            ->get();

        foreach ($paidInvoices as $invoice) {
            $activities[] = [
                'id' => $counter++,
                'type' => 'invoice_paid',
                'title' => 'فاتورة مدفوعة',
                'description' => 'فاتورة #' . $invoice->invoice_number . ' تم دفعها',
                'amount' => (float) $invoice->total,
                'user_name' => 'النظام',
                'invoiceId' => $invoice->invoice_number,
                'invoice_id' => $invoice->id,
                'timestamp' => $invoice->paid_at ? Carbon::parse($invoice->paid_at)->timestamp : Carbon::now()->timestamp,
                'created_at' => $invoice->paid_at ?: $invoice->updated_at->format('Y-m-d H:i:s'),
            ];
        }

        // إضافة نشاطات للعملاء الجدد
        foreach ($recentClients as $client) {
            $activities[] = [
                'id' => $counter++,
                'type' => 'client_added',
                'title' => 'عميل جديد',
                'description' => 'تم إضافة العميل ' . $client->name,
                'user_name' => 'النظام',
                'clientId' => $client->id,
                'timestamp' => $client->created_at->timestamp,
                'created_at' => $client->created_at->format('Y-m-d H:i:s'),
            ];
        }

        // ترتيب حسب التاريخ الأحدث
        usort($activities, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($activities, 0, self::RECENT_ACTIVITY_LIMIT);
    }

    /**
     * أفضل العملاء: الترتيب والحد يتمّان في قاعدة البيانات
     * بدل جلب كل العملاء وترتيبهم في PHP.
     */
    private function getTopClients(int $limit, array $clientGrowth, ?int $branchId = null): array
    {
        $totalSpentByClient = Invoice::where('status', Constants::INVOICE_STATUS_PAID)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->select('client_id', DB::raw('SUM(total) as spent'))
            ->groupBy('client_id')
            ->orderByDesc('spent')
            ->limit($limit)
            ->pluck('spent', 'client_id');

        if ($totalSpentByClient->isEmpty()) {
            return [];
        }

        $clients = Client::whereIn('id', $totalSpentByClient->keys())->get()->keyBy('id');

        return $totalSpentByClient
            ->map(function ($spent, $clientId) use ($clients, $clientGrowth) {
                $client = $clients->get($clientId);
                if (!$client) {
                    return null;
                }

                return [
                    'id' => $client->id,
                    'name' => $client->name,
                    'company_name' => $client->company_name ?: 'غير محدد',
                    'total_spent' => (float) $spent,
                    'growth' => $clientGrowth[$client->id] ?? 0.0,
                    'created_at' => $client->created_at->format('Y-m-d H:i:s'),
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    private function getPerformanceData($startDate, ?int $branchId = null): array
    {
        $data = Invoice::where('paid_at', '>=', $startDate)
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->select(
                DB::raw('DATE_FORMAT(paid_at, "%Y-%m") as month'),
                DB::raw('COUNT(*) as invoice_count'),
                DB::raw('SUM(total) as revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $result = [
            'months' => [],
            'invoices' => [],
            'revenues' => [],
        ];

        foreach ($data as $item) {
            [$year, $month] = explode('-', $item->month);
            $result['months'][] = (self::MONTH_NAMES[$month] ?? $month) . ' ' . $year;
            $result['invoices'][] = (int) $item->invoice_count;
            $result['revenues'][] = (float) $item->revenue;
        }

        // إذا لم يكن هناك بيانات فعلية، نعرض آخر 6 أشهر بقيمة صفر حقيقية
        return empty($result['months']) ? $this->emptyPerformanceData() : $result;
    }

    private function emptyPerformanceData(): array
    {
        $result = [
            'months' => [],
            'invoices' => [],
            'revenues' => [],
        ];

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $result['months'][] = (self::MONTH_NAMES[$date->format('m')] ?? $date->format('m')) . ' ' . $date->format('Y');
            $result['invoices'][] = 0;
            $result['revenues'][] = 0;
        }

        return $result;
    }

    /**
     * توزيع حالة الفواتير (يُستخدم في البيانات الفعلية وفي بيانات الطوارئ).
     */
    private function buildInvoiceStatuses(array $stats): array
    {
        return [
            [
                'status' => Constants::INVOICE_STATUS_PAID,
                'label' => 'مدفوعة',
                'value' => $stats['paidInvoices'],
                'color' => '#10b981',
                'icon' => 'fas fa-check-circle',
                'amount' => $stats['paidAmount'],
                'percentage' => $this->calculatePercentage($stats['paidInvoices'], $stats['totalInvoices']),
            ],
            [
                'status' => Constants::INVOICE_STATUS_SENT,
                'label' => 'مرسلة',
                'value' => $stats['pendingInvoices'],
                'color' => '#f59e0b',
                'icon' => 'fas fa-clock',
                'amount' => $stats['pendingAmount'],
                'percentage' => $this->calculatePercentage($stats['pendingInvoices'], $stats['totalInvoices']),
            ],
            [
                'status' => Constants::INVOICE_STATUS_OVERDUE,
                'label' => 'متأخرة',
                'value' => $stats['overdueInvoices'],
                'color' => '#ef4444',
                'icon' => 'fas fa-exclamation-triangle',
                'amount' => $stats['overdueAmount'],
                'percentage' => $this->calculatePercentage($stats['overdueInvoices'], $stats['totalInvoices']),
            ],
            [
                'status' => Constants::INVOICE_STATUS_DRAFT,
                'label' => 'مسودة',
                'value' => $stats['draftInvoices'],
                'color' => '#6b7280',
                'icon' => 'fas fa-file-alt',
                'amount' => $stats['draftAmount'],
                'percentage' => $this->calculatePercentage($stats['draftInvoices'], $stats['totalInvoices']),
            ],
        ];
    }

    private function buildSummary($performanceToday): array
    {
        return [
            'performance_today' => $performanceToday,
            'chart_periods' => [
                ['label' => '1M', 'value' => '1m'],
                ['label' => '3M', 'value' => '3m'],
                ['label' => '6M', 'value' => '6m'],
                ['label' => '1Y', 'value' => '1y'],
            ],
        ];
    }

    private function calculateGrowth($current, $previous)
    {
        return $previous > 0
            ? (($current - $previous) / $previous) * 100
            : ($current > 0 ? 100 : 0);
    }

    private function calculatePercentage($part, $total)
    {
        return $total > 0 ? round(($part / $total) * 100, 2) : 0;
    }

    private function calculateTodayPerformance($stats)
    {
        $todayPaid = $stats['todayPaidInvoices'] ?? 0;
        $todayTotal = $stats['todayTotalInvoices'] ?? 0;
        return $todayTotal > 0 ? round(($todayPaid / $todayTotal) * 100, 2) : 0;
    }

    private function getFallbackData(): array
    {
        $stats = [
            'totalRevenue' => 0,
            'totalInvoices' => 0,
            'totalClients' => 0,
            'paidInvoices' => 0,
            'pendingInvoices' => 0,
            'overdueInvoices' => 0,
            'draftInvoices' => 0,
            'revenueGrowth' => 0,
            'invoiceGrowth' => 0,
            'clientsGrowth' => 0,
            'paymentRate' => 0,
            'avgMonthlyRevenue' => 0,
            'paidAmount' => 0,
            'pendingAmount' => 0,
            'overdueAmount' => 0,
            'draftAmount' => 0,
            'todayPaidInvoices' => 0,
            'todayTotalInvoices' => 0,
            'currentMonthRevenue' => 0,
            'currentMonthInvoices' => 0,
            'currentMonthClients' => 0,
        ];

        return [
            'stats' => $stats,
            'recentClients' => [],
            'recentInvoices' => [],
            'monthlyRevenue' => [],
            'overdueInvoices' => [],
            'recentActivity' => [],
            'topClients' => [],
            'invoiceStatuses' => $this->buildInvoiceStatuses($stats),
            'performanceData' => $this->emptyPerformanceData(),
            'summary' => $this->buildSummary(0),
            'is_fallback' => true,
        ];
    }
}
