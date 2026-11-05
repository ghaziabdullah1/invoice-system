<?php

namespace App\Repository\Admin\Report;

use App\Constants\Constants;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RealtyReportRepository
{
    // مفتاح التقرير => الصلاحية المطلوبة لعرضه
    public const PERMISSIONS = [
        'occupancy'          => 'view_report_occupancy',
        'rent_roll'          => 'view_report_rent_roll',
        'expiring_contracts' => 'view_report_expiring_contracts',
        'tenant_arrears'     => 'view_report_tenant_arrears',
        'collection'         => 'view_report_collection',
        'vacant_units'       => 'view_report_vacant_units',
        'property_revenue'   => 'view_report_property_revenue',
    ];

    public function build(string $key, array $filters = []): array
    {
        return match ($key) {
            'occupancy'          => $this->occupancy($filters),
            'rent_roll'          => $this->rentRoll($filters),
            'expiring_contracts' => $this->expiringContracts($filters),
            'tenant_arrears'     => $this->tenantArrears($filters),
            'collection'         => $this->collection($filters),
            'vacant_units'       => $this->vacantUnits($filters),
            'property_revenue'   => $this->propertyRevenue($filters),
            default              => throw new \InvalidArgumentException("report type not found: {$key}"),
        };
    }

    public function visibleProperties(): Collection
    {
        $allowed = request()->attributes->get('allowed_property_ids');

        return Property::active()
            ->when(is_array($allowed), fn($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->get(['id', 'name', 'name_en']);
    }

    // ================================================================
    // 1) الإشغال
    // ================================================================
    private function occupancy(array $f): array
    {
        $ids      = $this->propertyIds($f);
        $occupied = $this->occupiedUnitIds();
        $units    = $this->unitsQuery($ids)->get($this->unitCols());

        $items = $units->groupBy('floor_id')
            ->map(function ($g) use ($occupied) {
                $first    = $g->first();
                $total    = $g->count();
                $inactive = $g->filter(fn($u) => !$u->is_active)->count();
                $occ      = $g->filter(fn($u) => $u->is_active && $occupied->has($u->id))->count();
                $active   = $total - $inactive;

                return [
                    'property'       => $this->pname($first->property_name, $first->property_name_en),
                    'floor'          => $first->floor_name,
                    'floor_number'   => (int) $first->floor_number,
                    'total_units'    => $total,
                    'occupied'       => $occ,
                    'vacant'         => $active - $occ,
                    'inactive'       => $inactive,
                    'occupancy_rate' => $active > 0 ? round($occ / $active * 100, 1) : 0,
                ];
            })->sortBy([['property', 'asc'], ['floor_number', 'asc']])->values();

        $total    = $items->sum('total_units');
        $occ      = $items->sum('occupied');
        $inactive = $items->sum('inactive');
        $active   = $total - $inactive;

        return [
            'items' => $items,
            'stats' => [
                'properties_count' => $units->pluck('property_id')->unique()->count(),
                'total_units'      => $total,
                'occupied'         => $occ,
                'vacant'           => $active - $occ,
                'inactive'         => $inactive,
                'occupancy_rate'   => $active > 0 ? round($occ / $active * 100, 1) : 0,
            ],
        ];
    }

    // ================================================================
    // 2) كشف الإيجارات (العقود السارية فعلياً اليوم)
    // ================================================================
    private function rentRoll(array $f): array
    {
        $ids = $this->propertyIds($f);

        $contracts = Contract::inEffect()
            ->with($this->contractRelations())
            ->when(is_array($ids), fn($q) => $q->whereIn('contracts.property_id', $ids))
            ->orderBy('property_id')->orderBy('unit_id')
            ->get();

        $monthly = $annual = $deposits = [];

        $items = $contracts->map(function (Contract $c) use (&$monthly, &$annual, &$deposits) {
            $cur = $this->cur($c->currency);
            $m   = $this->monthly($c);
            $this->add($monthly, $cur, $m);
            $this->add($annual, $cur, $m * 12);
            $this->add($deposits, $cur, (float) $c->security_deposit);

            return [
                'contract_number'         => $c->contract_number,
                'property'                => $this->pname($c->property?->name, $c->property?->name_en),
                'floor'                   => $c->unit?->floor?->name,
                'unit'                    => $c->unit?->name,
                'tenant'                  => $c->tenant?->name,
                'start_date'              => $c->start_date?->format(Constants::DATE_FORMAT),
                'end_date'                => $c->end_date?->format(Constants::DATE_FORMAT),
                'payment_frequency'       => $c->payment_frequency,
                'rent_amount'             => (float) $c->rent_amount,
                'monthly_equivalent'      => $m,
                'currency'                => $cur,
                'security_deposit'        => (float) $c->security_deposit,
                'annual_increase_percent' => $c->annual_increase_percent !== null ? (float) $c->annual_increase_percent : null,
            ];
        })->values();

        return [
            'items' => $items,
            'stats' => [
                'contracts_count'  => $items->count(),
                'monthly_expected' => $monthly,
                'annual_expected'  => $annual,
                'deposits'         => $deposits,
            ],
        ];
    }

    // ================================================================
    // 3) العقود المنتهية والقريبة من الانتهاء
    // ================================================================
    private function expiringContracts(array $f): array
    {
        $ids   = $this->propertyIds($f);
        $days  = in_array((int) ($f['days'] ?? 90), [30, 60, 90], true) ? (int) $f['days'] : 90;
        $today = Carbon::today();
        $limit = $today->copy()->addDays($days)->format(Constants::DATE_FORMAT);

        $contracts = Contract::active()
            ->whereDate('end_date', '<=', $limit)
            ->with($this->contractRelations())
            ->withCount('renewals')
            ->when(is_array($ids), fn($q) => $q->whereIn('contracts.property_id', $ids))
            ->orderBy('end_date')
            ->get();

        $stats = ['total' => 0, 'expired' => 0, 'within_30' => 0, 'within_60' => 0, 'within_90' => 0, 'monthly_at_risk' => []];

        $items = $contracts->map(function (Contract $c) use ($today, &$stats) {
            $remaining = (int) $today->diffInDays($c->end_date->copy()->startOfDay(), false);
            $cur       = $this->cur($c->currency);

            $stats['total']++;
            if ($remaining < 0) $stats['expired']++;
            elseif ($remaining <= 30) $stats['within_30']++;
            elseif ($remaining <= 60) $stats['within_60']++;
            else $stats['within_90']++;
            $this->add($stats['monthly_at_risk'], $cur, $this->monthly($c));

            return [
                'contract_number' => $c->contract_number,
                'property'        => $this->pname($c->property?->name, $c->property?->name_en),
                'unit'            => $c->unit?->name,
                'tenant'          => $c->tenant?->name,
                'phone'           => $c->tenant?->phone,
                'end_date'        => $c->end_date?->format(Constants::DATE_FORMAT),
                'days_remaining'  => $remaining,
                'rent_amount'     => (float) $c->rent_amount,
                'currency'        => $cur,
                'renewals_count'  => (int) $c->renewals_count,
            ];
        })->values();

        return ['items' => $items, 'stats' => $stats];
    }

    // ================================================================
    // 4) متأخرات المستأجرين مع أعمار الدين
    // ================================================================
    private function tenantArrears(array $f): array
    {
        $ids   = $this->propertyIds($f);
        $today = Carbon::today();

        $invoices = Invoice::select(['id', 'contract_id', 'due_date', 'total', 'status', 'currency'])
            ->whereNotNull('contract_id')
            ->where(function ($w) {
                $w->where('status', Constants::INVOICE_STATUS_OVERDUE)
                    ->orWhere(function ($s) {
                        $s->where('status', Constants::INVOICE_STATUS_SENT)->whereDate('due_date', '<', now());
                    });
            })
            ->whereHas('contract', fn($c) => $this->inProperties($c, $ids))
            ->with([
                'contract:id,contract_number,property_id,unit_id,tenant_id,currency',
                'contract.tenant:id,name,phone',
                'contract.unit:id,name',
                'contract.property:id,name,name_en',
            ])
            ->orderBy('due_date')
            ->get();

        $rows = [];
        $totalDue = $over90 = [];

        foreach ($invoices as $inv) {
            $c = $inv->contract;
            if (!$c) continue;

            $cur    = $this->cur($inv->currency ?: $c->currency);
            $amount = (float) $inv->total;
            $days   = max(0, (int) Carbon::parse($inv->due_date)->startOfDay()->diffInDays($today, false));
            $bucket = $days <= 30 ? 'b0_30' : ($days <= 60 ? 'b31_60' : ($days <= 90 ? 'b61_90' : 'b90_plus'));
            $k      = $c->id . '|' . $cur;

            $rows[$k] ??= [
                'contract_number' => $c->contract_number,
                'tenant'          => $c->tenant?->name,
                'phone'           => $c->tenant?->phone,
                'property'        => $this->pname($c->property?->name, $c->property?->name_en),
                'unit'            => $c->unit?->name,
                'currency'        => $cur,
                'b0_30' => 0.0,
                'b31_60' => 0.0,
                'b61_90' => 0.0,
                'b90_plus' => 0.0,
                'total_due'       => 0.0,
                'invoices_count'  => 0,
            ];

            $rows[$k][$bucket]    = round($rows[$k][$bucket] + $amount, 2);
            $rows[$k]['total_due'] = round($rows[$k]['total_due'] + $amount, 2);
            $rows[$k]['invoices_count']++;

            $this->add($totalDue, $cur, $amount);
            if ($bucket === 'b90_plus') $this->add($over90, $cur, $amount);
        }

        $items = collect($rows)->sortByDesc('total_due')->values();

        return [
            'items' => $items,
            'stats' => [
                'tenants_count'  => $items->count(),
                'invoices_count' => $invoices->count(),
                'total_due'      => $totalDue,
                'over_90'        => $over90,
            ],
        ];
    }

    // ================================================================
    // 5) التحصيل مقابل المستحق (شهرياً ولكل عقار)
    // ================================================================
    private function collection(array $f): array
    {
        $ids      = $this->propertyIds($f);
        $invoices = $this->issuedInvoices($f, $ids);

        $rows = [];
        foreach ($invoices as $inv) {
            $pid = $inv->contract?->property_id;
            if (!$pid) continue;

            $cur   = $this->cur($inv->currency ?: $inv->contract->currency);
            $month = Carbon::parse($inv->due_date)->format('Y-m');
            $k     = "$month|$pid|$cur";

            $rows[$k] ??= ['month' => $month, 'pid' => $pid, 'currency' => $cur, 'due' => 0.0, 'collected' => 0.0];
            $rows[$k]['due'] = round($rows[$k]['due'] + (float) $inv->total, 2);
            if ($inv->status === Constants::INVOICE_STATUS_PAID) {
                $rows[$k]['collected'] = round($rows[$k]['collected'] + (float) $inv->total, 2);
            }
        }

        $props = $this->propertyMap(collect($rows)->pluck('pid')->unique()->all());
        $due = $col = [];

        $items = collect($rows)->map(function ($r) use ($props, &$due, &$col) {
            $this->add($due, $r['currency'], $r['due']);
            $this->add($col, $r['currency'], $r['collected']);

            return [
                'month'       => $r['month'],
                'property'    => $this->pname($props[$r['pid']]->name ?? null, $props[$r['pid']]->name_en ?? null),
                'currency'    => $r['currency'],
                'due'         => $r['due'],
                'collected'   => $r['collected'],
                'outstanding' => round($r['due'] - $r['collected'], 2),
                'rate'        => $r['due'] > 0 ? round($r['collected'] / $r['due'] * 100, 1) : 0,
            ];
        })->sortBy([['month', 'desc'], ['property', 'asc']])->values();

        return [
            'items' => $items,
            'stats' => [
                'due'             => $due,
                'collected'       => $col,
                'outstanding'     => array_map(fn($cur) => round($due[$cur] - ($col[$cur] ?? 0), 2), array_combine(array_keys($due), array_keys($due))),
                'collection_rate' => array_map(fn($cur) => $due[$cur] > 0 ? round(($col[$cur] ?? 0) / $due[$cur] * 100, 1) : 0, array_combine(array_keys($due), array_keys($due))),
            ],
        ];
    }

    // ================================================================
    // 6) الوحدات الشاغرة وخسارة الإيراد
    // ================================================================
    private function vacantUnits(array $f): array
    {
        $ids      = $this->propertyIds($f);
        $today    = Carbon::today();
        $occupied = $this->occupiedUnitIds();

        $vacant = $this->unitsQuery($ids)
            ->where('units.is_active', true)
            ->get($this->unitCols())
            ->reject(fn($u) => $occupied->has($u->id))
            ->values();

        $last = Contract::whereIn('unit_id', $vacant->pluck('id')->all())
            ->whereDate('end_date', '<', $today->format(Constants::DATE_FORMAT))
            ->orderByDesc('end_date')
            ->get(['unit_id', 'end_date', 'currency'])
            ->groupBy('unit_id')
            ->map(fn($g) => $g->first());

        $monthlyLoss = $annualLoss = [];

        $items = $vacant->map(function ($u) use ($last, $today, &$monthlyLoss, &$annualLoss) {
            $l     = $last->get($u->id);
            $cur   = $this->cur($l?->currency);
            $since = $l ? $l->end_date->copy()->addDay() : null;
            $rent  = (float) $u->monthly_rent;

            $this->add($monthlyLoss, $cur, $rent);
            $this->add($annualLoss, $cur, $rent * 12);

            return [
                'property'     => $this->pname($u->property_name, $u->property_name_en),
                'floor'        => $u->floor_name,
                'floor_number' => (int) $u->floor_number,
                'unit'         => $u->name,
                'area'         => $u->area !== null ? (float) $u->area : null,
                'monthly_rent' => $rent,
                'currency'     => $cur,
                'vacant_since' => $since?->format(Constants::DATE_FORMAT),
                'vacant_days'  => $since ? max(0, (int) $since->diffInDays($today, false)) : null,
            ];
        })->sortBy([['property', 'asc'], ['floor_number', 'asc'], ['unit', 'asc']])->values();

        return [
            'items' => $items,
            'stats' => [
                'vacant_count' => $items->count(),
                'monthly_loss' => $monthlyLoss,
                'annual_loss'  => $annualLoss,
            ],
        ];
    }

    // ================================================================
    // 7) إيراد كل عقار
    // ================================================================
    private function propertyRevenue(array $f): array
    {
        $ids      = $this->propertyIds($f);
        $today    = Carbon::today();
        $invoices = $this->issuedInvoices($f, $ids);

        $rows = [];
        foreach ($invoices as $inv) {
            $pid = $inv->contract?->property_id;
            if (!$pid) continue;

            $cur    = $this->cur($inv->currency ?: $inv->contract->currency);
            $amount = (float) $inv->total;
            $k      = "$pid|$cur";
            $paid   = $inv->status === Constants::INVOICE_STATUS_PAID;
            $late   = $inv->status === Constants::INVOICE_STATUS_OVERDUE
                || ($inv->status === Constants::INVOICE_STATUS_SENT && Carbon::parse($inv->due_date)->startOfDay()->lt($today));

            $rows[$k] ??= ['pid' => $pid, 'currency' => $cur, 'invoiced' => 0.0, 'collected' => 0.0, 'overdue' => 0.0];
            $rows[$k]['invoiced'] = round($rows[$k]['invoiced'] + $amount, 2);
            if ($paid) $rows[$k]['collected'] = round($rows[$k]['collected'] + $amount, 2);
            if ($late) $rows[$k]['overdue'] = round($rows[$k]['overdue'] + $amount, 2);
        }

        $props  = $this->propertyMap(collect($rows)->pluck('pid')->unique()->all());
        $active = Contract::inEffect()->selectRaw('property_id, COUNT(*) as n')->groupBy('property_id')->pluck('n', 'property_id');
        $inv = $col = $out = $over = [];

        $items = collect($rows)->map(function ($r) use ($props, $active, &$inv, &$col, &$out, &$over) {
            $outstanding = round($r['invoiced'] - $r['collected'], 2);
            $this->add($inv, $r['currency'], $r['invoiced']);
            $this->add($col, $r['currency'], $r['collected']);
            $this->add($out, $r['currency'], $outstanding);
            $this->add($over, $r['currency'], $r['overdue']);

            return [
                'property'         => $this->pname($props[$r['pid']]->name ?? null, $props[$r['pid']]->name_en ?? null),
                'currency'         => $r['currency'],
                'invoiced'         => $r['invoiced'],
                'collected'        => $r['collected'],
                'outstanding'      => $outstanding,
                'overdue'          => $r['overdue'],
                'rate'             => $r['invoiced'] > 0 ? round($r['collected'] / $r['invoiced'] * 100, 1) : 0,
                'active_contracts' => (int) ($active[$r['pid']] ?? 0),
            ];
        })->sortBy([['property', 'asc'], ['currency', 'asc']])->values();

        return [
            'items' => $items,
            'stats' => ['invoiced' => $inv, 'collected' => $col, 'outstanding' => $out, 'overdue' => $over],
        ];
    }

    // ================================================================
    // Helpers
    // ================================================================

    /**
     * null = بلا تقييد (سوبر أدمن بلا اختيار)، مصفوفة = العقارات المسموحة فقط ([] = لا شيء).
     * ترتيب الأولوية: فلتر الصفحة، ثم العقار المختار في الشريط العلوي (إلا إذا طُلب all)، ثم كل المسموح.
     */
    private function propertyIds(array $f): ?array
    {
        $attrs   = request()->attributes;
        $allowed = $attrs->get('allowed_property_ids');

        $wanted = !empty($f['property_id'])
            ? (int) $f['property_id']
            : (empty($f['all']) ? (int) $attrs->get('selected_property_id') : 0);

        if ($wanted) {
            return (is_array($allowed) && !in_array($wanted, $allowed, true)) ? [] : [$wanted];
        }

        return $allowed;
    }

    private function inProperties($q, ?array $ids): void
    {
        if (is_array($ids)) {
            $q->whereIn('property_id', $ids);
        }
    }

    private function unitsQuery(?array $ids)
    {
        return Unit::query()
            ->join('floors', 'floors.id', '=', 'units.floor_id')
            ->join('properties', 'properties.id', '=', 'floors.property_id')
            ->whereNull('floors.deleted_at')
            ->whereNull('properties.deleted_at')
            ->when(is_array($ids), fn($q) => $q->whereIn('floors.property_id', $ids));
    }

    private function unitCols(): array
    {
        return [
            'units.id',
            'units.name',
            'units.area',
            'units.monthly_rent',
            'units.is_active',
            'floors.id as floor_id',
            'floors.name as floor_name',
            'floors.floor_number',
            'properties.id as property_id',
            'properties.name as property_name',
            'properties.name_en as property_name_en',
        ];
    }

    private function occupiedUnitIds(): Collection
    {
        return Contract::inEffect()->pluck('unit_id')->flip();
    }

    private function contractRelations(): array
    {
        return [
            'tenant:id,name,phone',
            'unit:id,name,floor_id',
            'unit.floor:id,name',
            'property:id,name,name_en',
        ];
    }

    // فواتير الإيجار المُصدرة (غير المسودة) ضمن فترة الاستحقاق
    private function issuedInvoices(array $f, ?array $ids): Collection
    {
        return Invoice::select(['id', 'contract_id', 'due_date', 'total', 'status', 'currency'])
            ->whereNotNull('contract_id')
            ->whereIn('status', [Constants::INVOICE_STATUS_SENT, Constants::INVOICE_STATUS_OVERDUE, Constants::INVOICE_STATUS_PAID])
            ->when(!empty($f['start_date']), fn($q) => $q->whereDate('due_date', '>=', $f['start_date']))
            ->when(!empty($f['end_date']), fn($q) => $q->whereDate('due_date', '<=', $f['end_date']))
            ->whereHas('contract', fn($c) => $this->inProperties($c, $ids))
            ->with('contract:id,property_id,currency')
            ->get();
    }

    private function propertyMap(array $ids): Collection
    {
        return Property::withTrashed()->whereIn('id', $ids)->get(['id', 'name', 'name_en'])->keyBy('id');
    }

    private function pname($name, $nameEn): string
    {
        if ($name === null && $nameEn === null) return '-';
        return (app()->getLocale() === 'en' && !empty($nameEn)) ? $nameEn : (string) $name;
    }

    private function cur($value): string
    {
        return $value ?: Constants::CURRENCY_SAR;
    }

    private function add(array &$map, string $cur, float $amount): void
    {
        $map[$cur] = round(($map[$cur] ?? 0) + $amount, 2);
    }

    // rent_amount هو إيجار الدفعة الواحدة، فالمكافئ الشهري = المبلغ / عدد أشهر الدورية
    private function monthly(Contract $c): float
    {
        $months = Constants::CONTRACT_FREQUENCY_MONTHS[$c->payment_frequency] ?? 1;
        return round((float) $c->rent_amount / $months, 2);
    }
}
