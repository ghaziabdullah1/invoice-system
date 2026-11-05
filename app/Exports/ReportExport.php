<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReportExport implements FromArray, WithHeadings, WithStyles
{
    protected $reportType;
    protected $data;

    public function __construct($reportType, $data)
    {
        $this->reportType = $reportType;
        $this->data = $data;
    }

    public function array(): array
    {
        switch ($this->reportType) {
            case 'invoices':
                $items = $this->data['invoices']['data'] ?? $this->data['invoices'] ?? [];
                return array_map(function($item) {
                    return [
                        $item['invoice_number'] ?? '',
                        $item['client']['name'] ?? '',
                        $item['issue_date'] ?? '',
                        $item['due_date'] ?? '',
                        $item['total_amount'] ?? 0,
                        $this->getStatusText($item['status'] ?? ''),
                    ];
                }, $items);

            case 'clients':
                $items = $this->data['clients'] ?? [];
                return array_map(function($item) {
                    return [
                        $item['client_name'] ?? '',
                        $item['client_email'] ?? '',
                        $item['total_invoices'] ?? 0,
                        $item['total_amount'] ?? 0,
                        $item['total_paid'] ?? 0,
                        $item['total_due'] ?? 0,
                    ];
                }, $items);

            case 'revenue':
                $items = $this->data['revenue_data'] ?? [];
                return array_map(function($item) {
                    return [
                        $item['month'] ?? '',
                        $item['count'] ?? 0,
                        $item['total'] ?? 0,
                        $item['paid'] ?? 0,
                        $item['due'] ?? 0,
                    ];
                }, $items);

            case 'overdue':
                $items = $this->data['overdue_invoices'] ?? [];
                return array_map(function($item) {
                    return [
                        $item['invoice_number'] ?? '',
                        $item['client_name'] ?? '',
                        $item['issue_date'] ?? '',
                        $item['due_date'] ?? '',
                        $item['days_overdue'] ?? 0,
                        $item['total_amount'] ?? 0,
                        $this->getStatusText($item['status'] ?? ''),
                    ];
                }, $items);

            default:
                return [];
        }
    }

    public function headings(): array
    {
        switch ($this->reportType) {
            case 'invoices':
                return ['رقم الفاتورة', 'العميل', 'تاريخ الإصدار', 'تاريخ الاستحقاق', 'المجموع', 'الحالة'];
            case 'clients':
                return ['اسم العميل', 'البريد الإلكتروني', 'عدد الفواتير', 'إجمالي المبلغ', 'المبلغ المدفوع', 'المبلغ المتبقي'];
            case 'revenue':
                return ['الشهر', 'عدد الفواتير', 'الإجمالي', 'المتحصلات', 'المستحقات'];
            case 'overdue':
                return ['رقم الفاتورة', 'العميل', 'تاريخ الإصدار', 'تاريخ الاستحقاق', 'أيام التأخير', 'المبلغ', 'الحالة'];
            default:
                return [];
        }
    }

    public function styles(Worksheet $sheet): ?array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function getStatusText($status)
    {
        $statuses = [
            'paid' => 'مدفوعة',
            'unpaid' => 'غير مدفوعة',
            'partially_paid' => 'مدفوعة جزئياً',
            'sent' => 'مرسلة',
            'draft' => 'مسودة',
            'overdue' => 'متأخرة',
        ];

        return $statuses[$status] ?? $status;
    }
}
