<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class OverdueReportExport implements FromArray, WithHeadings, WithMapping, WithStyles, WithEvents, WithTitle, ShouldAutoSize
{
    protected $data;
    protected $filters;
    protected $stats;

    public function __construct(array $data, array $filters = [])
    {
        $this->data = $data;
        $this->filters = $filters;
        $this->stats = $data['stats'] ?? [];

        // تسجيل البيانات للتشخيص
        Log::info('OverdueReportExport constructed', [
            'items_count' => isset($data['items']) ? (is_countable($data['items']) ? count($data['items']) : 'غير قابل للعد') : 0,
            'stats' => $this->stats,
        ]);
    }

    public function array(): array
    {
        $items = $this->data['items'] ?? [];

        // إذا كانت المجموعة من نوع Collection، قم بتحويلها إلى مصفوفة
        if ($items instanceof Collection) {
            $items = $items->toArray();
        }

        // إذا كانت مصفوفة من الكائنات (Eloquent models)، قم بتحويلها إلى مصفوفات
        if (is_array($items) && !empty($items) && is_object($items[0])) {
            $items = json_decode(json_encode($items), true);
        }

        // تسجيل للتشخيص
        if (empty($items)) {
            Log::warning('OverdueReportExport: No items to export', ['data_keys' => array_keys($this->data)]);
        } else {
            Log::info('OverdueReportExport: Items count', ['count' => count($items)]);
            // تسجيل أول عنصر لمعرفة هيكله (تجنب تسجيل البيانات الكبيرة)
            Log::debug('OverdueReportExport: First item sample', ['sample' => $items[0] ?? null]);
        }

        return $items;
    }

    public function headings(): array
    {
        return [
            __('reports.invoice_number'),
            __('reports.client'),
            __('reports.issue_date'),
            __('reports.due_date'),
            __('reports.overdue_days'),
            __('reports.total'),
            __('reports.paid'),
            __('reports.remaining'),
            __('reports.status')
        ];
    }

    public function map($invoice): array
    {
        // معالجة آمنة: تحويل الكائن إلى مصفوفة إذا لزم الأمر
        if (is_object($invoice)) {
            $invoice = (array) $invoice;
        }

        $statusMap = [
            'paid' => __('invoices.status.paid'),
            'unpaid' => __('invoices.status.unpaid'),
            'partially_paid' => __('invoices.status.partially_paid'),
            'sent' => __('invoices.status.sent'),
            'draft' => __('invoices.status.draft'),
            'overdue' => __('invoices.status.overdue'),
        ];

        $status = $statusMap[$invoice['status'] ?? ''] ?? ($invoice['status'] ?? __('reports.unknown'));
        $paidAmount = (float) ($invoice['paid_amount'] ?? 0);
        $total = (float) ($invoice['total_amount'] ?? $invoice['total'] ?? 0);

        // التعامل مع client (قد يكون مصفوفة أو كائن)
        $clientName = __('reports.unknown');
        if (isset($invoice['client'])) {
            if (is_array($invoice['client'])) {
                $clientName = $invoice['client']['name'] ?? __('reports.unknown');
            } elseif (is_object($invoice['client'])) {
                $clientName = $invoice['client']->name ?? __('reports.unknown');
            }
        }

        return [
            $invoice['invoice_number'] ?? __('reports.unknown'),
            $clientName,
            $invoice['issue_date'] ?? $invoice['invoice_date'] ?? __('reports.unknown'),
            $invoice['due_date'] ?? __('reports.unknown'),
            (int) ($invoice['days_overdue'] ?? 0),
            $total,
            $paidAmount,
            $total - $paidAmount,
            $status
        ];
    }

    public function styles(Worksheet $sheet): ?array
    {
        // تنسيق العنوان
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C0392B']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ]);

        $lastRow = $sheet->getHighestRow();
        if ($lastRow >= 2) {
            $sheet->getStyle('A2:I' . $lastRow)
                ->getAlignment()
                ->setVertical(Alignment::VERTICAL_CENTER);

            $sheet->getStyle('F2:H' . $lastRow)
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        }

        $sheet->getStyle('A1:I' . $lastRow)
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                $lastRow = $sheet->getHighestRow();

                $summaryRow = $lastRow + 2;

                $sheet->setCellValue('A' . $summaryRow, __('reports.statistics') . ':');
                $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);

                $sheet->setCellValue('A' . ($summaryRow + 1), __('reports.total_overdue') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 1), $this->stats['total_overdue'] ?? 0);

                $sheet->setCellValue('A' . ($summaryRow + 2), __('reports.total_amount') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 2), number_format($this->stats['total_amount'] ?? 0, 2));

                $sheet->setCellValue('A' . ($summaryRow + 3), __('reports.average_days_overdue') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 3), ($this->stats['average_days_overdue'] ?? 0) . ' ' . __('reports.days'));

                $infoRow = $summaryRow + 5;
                $sheet->setCellValue('A' . $infoRow, __('reports.report_info') . ':');
                $sheet->getStyle('A' . $infoRow)->getFont()->setBold(true);

                $sheet->setCellValue('A' . ($infoRow + 1), __('reports.generated_at') . ': ' . date('Y-m-d H:i:s'));

                if (!empty($this->filters['start_date'])) {
                    $sheet->setCellValue('A' . ($infoRow + 2), __('reports.start_date') . ': ' . $this->filters['start_date']);
                }
                if (!empty($this->filters['end_date'])) {
                    $sheet->setCellValue('A' . ($infoRow + 3), __('reports.end_date') . ': ' . $this->filters['end_date']);
                }
            }
        ];
    }

    public function title(): string
    {
        return __('reports.overdue_report');
    }
}
