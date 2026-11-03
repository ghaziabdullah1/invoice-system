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

class InvoiceReportExport implements FromArray, WithHeadings, WithMapping, WithStyles, WithEvents, WithTitle, ShouldAutoSize
{
    protected $data;
    protected $filters;
    protected $stats;

    public function __construct(array $data, array $filters = [])
    {
        $this->data = $data;
        $this->filters = $filters;
        $this->stats = $data['stats'] ?? [];
    }

    public function array(): array
    {
        return $this->data['items'] ?? [];
    }

    public function headings(): array
    {
        return [
            __('reports.invoice_number'),
            __('reports.client'),
            __('reports.issue_date'),
            __('reports.due_date'),
            __('reports.total'),
            __('reports.paid'),
            __('reports.remaining'),
            __('reports.status')
        ];
    }

    public function map($invoice): array
    {
        $statusMap = [
            'paid' => __('invoices.status.paid'),
            'unpaid' => __('invoices.status.unpaid'),
            'partially_paid' => __('invoices.status.partially_paid'),
            'sent' => __('invoices.status.sent'),
            'draft' => __('invoices.status.draft'),
            'overdue' => __('invoices.status.overdue'),
        ];

        $status = $statusMap[$invoice['status']] ?? $invoice['status'];
        $paidAmount = $invoice['paid_amount'] ?? 0;
        $total = $invoice['total'] ?? $invoice['total_amount'] ?? 0;

        return [
            $invoice['invoice_number'] ?? __('reports.unknown'),
            $invoice['client']['name'] ?? __('reports.unknown'),
            $invoice['invoice_date'] ?? $invoice['issue_date'] ?? __('reports.unknown'),
            $invoice['due_date'] ?? __('reports.unknown'),
            $total,
            $paidAmount,
            $total - $paidAmount,
            $status
        ];
    }

    public function styles(Worksheet $sheet): ?array
    {
        // تنسيق العنوان
        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '2980B9']
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

        // تنسيق جميع الخلايا
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle('A2:H' . $lastRow)
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        // تنسيق الأرقام
        $sheet->getStyle('E2:G' . $lastRow)
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

        // إضافة حدود
        $sheet->getStyle('A1:H' . $lastRow)
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

                // إضافة صف الإجماليات
                $summaryRow = $lastRow + 2;

                $sheet->setCellValue('A' . $summaryRow, __('reports.statistics') . ':');
                $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);

                $sheet->setCellValue('A' . ($summaryRow + 1), __('reports.total_invoices') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 1), $this->stats['total_invoices'] ?? 0);

                $sheet->setCellValue('A' . ($summaryRow + 2), __('reports.total_amount') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 2), number_format($this->stats['total_amount'] ?? 0, 2));

                $sheet->setCellValue('A' . ($summaryRow + 3), __('reports.total_paid') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 3), number_format($this->stats['total_paid'] ?? 0, 2));

                $sheet->setCellValue('A' . ($summaryRow + 4), __('reports.total_due') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 4), number_format($this->stats['total_due'] ?? 0, 2));

                // إضافة معلومات التقرير
                $infoRow = $summaryRow + 6;
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
        return __('reports.invoice_report');
    }
}
