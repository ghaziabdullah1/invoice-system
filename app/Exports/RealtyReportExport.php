<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RealtyReportExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles, WithTitle
{
    private const COLS = [
        'occupancy'          => ['property', 'floor', 'total_units', 'occupied', 'vacant', 'inactive', 'occupancy_rate'],
        'rent_roll'          => ['contract_number', 'property', 'floor', 'unit', 'tenant', 'start_date', 'end_date', 'payment_frequency', 'rent_amount', 'monthly_equivalent', 'currency', 'security_deposit', 'annual_increase_percent'],
        'expiring_contracts' => ['contract_number', 'property', 'unit', 'tenant', 'phone', 'end_date', 'days_remaining', 'rent_amount', 'currency', 'renewals_count'],
        'tenant_arrears'     => ['contract_number', 'tenant', 'phone', 'property', 'unit', 'currency', 'b0_30', 'b31_60', 'b61_90', 'b90_plus', 'total_due', 'invoices_count'],
        'collection'         => ['month', 'property', 'currency', 'due', 'collected', 'outstanding', 'rate'],
        'vacant_units'       => ['property', 'floor', 'unit', 'area', 'monthly_rent', 'currency', 'vacant_since', 'vacant_days'],
        'property_revenue'   => ['property', 'currency', 'invoiced', 'collected', 'outstanding', 'overdue', 'rate', 'active_contracts'],
    ];

    // [عربي, English]
    private const LABELS = [
        'property' => ['العقار', 'Property'], 'floor' => ['الطابق', 'Floor'], 'unit' => ['الوحدة', 'Unit'],
        'tenant' => ['المستأجر', 'Tenant'], 'phone' => ['الجوال', 'Phone'], 'contract_number' => ['رقم العقد', 'Contract No.'],
        'total_units' => ['إجمالي الوحدات', 'Total Units'], 'occupied' => ['مؤجرة', 'Occupied'], 'vacant' => ['شاغرة', 'Vacant'],
        'inactive' => ['غير نشطة', 'Inactive'], 'occupancy_rate' => ['نسبة الإشغال %', 'Occupancy %'],
        'start_date' => ['بداية العقد', 'Start Date'], 'end_date' => ['نهاية العقد', 'End Date'],
        'payment_frequency' => ['دورية الدفع', 'Frequency'], 'rent_amount' => ['الإيجار (لكل دفعة)', 'Rent (per payment)'],
        'monthly_equivalent' => ['المكافئ الشهري', 'Monthly Equivalent'], 'currency' => ['العملة', 'Currency'],
        'security_deposit' => ['التأمين', 'Security Deposit'], 'annual_increase_percent' => ['الزيادة السنوية %', 'Annual Increase %'],
        'days_remaining' => ['الأيام المتبقية', 'Days Remaining'], 'renewals_count' => ['عدد التجديدات', 'Renewals'],
        'b0_30' => ['0-30', '0-30'], 'b31_60' => ['31-60', '31-60'], 'b61_90' => ['61-90', '61-90'], 'b90_plus' => ['90+', '90+'],
        'total_due' => ['إجمالي المستحق', 'Total Due'], 'invoices_count' => ['عدد الفواتير', 'Invoices'],
        'month' => ['الشهر', 'Month'], 'due' => ['المستحق', 'Due'], 'collected' => ['المحصّل', 'Collected'],
        'outstanding' => ['المتبقي', 'Outstanding'], 'rate' => ['نسبة التحصيل %', 'Collection %'],
        'area' => ['المساحة', 'Area'], 'monthly_rent' => ['الإيجار الشهري', 'Monthly Rent'],
        'vacant_since' => ['شاغرة منذ', 'Vacant Since'], 'vacant_days' => ['أيام الشغور', 'Vacant Days'],
        'invoiced' => ['المفوتر', 'Invoiced'], 'overdue' => ['المتأخر', 'Overdue'], 'active_contracts' => ['عقود سارية', 'Active Contracts'],
    ];

    private const FREQ = [
        'monthly' => ['شهري', 'Monthly'], 'quarterly' => ['ربع سنوي', 'Quarterly'],
        'semi_annual' => ['نصف سنوي', 'Semi-annual'], 'annual' => ['سنوي', 'Annual'],
    ];

    public function __construct(private string $key, private array $items)
    {
    }

    private function idx(): int
    {
        return app()->getLocale() === 'en' ? 1 : 0;
    }

    public function headings(): array
    {
        return array_map(fn ($c) => self::LABELS[$c][$this->idx()] ?? $c, self::COLS[$this->key] ?? []);
    }

    public function array(): array
    {
        $cols = self::COLS[$this->key] ?? [];

        return array_map(function ($row) use ($cols) {
            return array_map(function ($c) use ($row) {
                $v = $row[$c] ?? '';
                if ($c === 'payment_frequency' && isset(self::FREQ[$v])) {
                    return self::FREQ[$v][$this->idx()];
                }
                return $v === null ? '' : $v;
            }, $cols);
        }, $this->items);
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->setRightToLeft(app()->getLocale() !== 'en');

        return [1 => ['font' => ['bold' => true]]];
    }

    public function title(): string
    {
        return substr(ucwords(str_replace('_', ' ', $this->key)), 0, 31);
    }
}
