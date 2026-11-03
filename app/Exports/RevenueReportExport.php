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


class RevenueReportExport implements FromArray, WithHeadings, WithMapping, WithStyles, WithEvents, WithTitle, ShouldAutoSize
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
        $items = $this->data['items'] ?? [];
        if ($items instanceof \Illuminate\Support\Collection) {
            return $items->toArray();
        }
        return $items;
    }

    public function headings(): array
    {
        return [
            __('reports.month'),
            __('reports.invoices_count'),
            __('reports.total_revenue'),
            __('reports.collected_revenue'),
            __('reports.outstanding_revenue'),
            __('reports.collection_rate')
        ];
    }

    public function map($item): array
    {
        return [
            $item['month'] ?? __('reports.unknown'),
            $item['invoice_count'] ?? 0,
            $item['total_amount'] ?? 0,
            $item['paid_amount'] ?? 0,
            $item['due_amount'] ?? 0,
            $item['collection_rate'] ?? 0
        ];
    }

    public function styles(Worksheet $sheet): ?array
    {
        // تنسيق العنوان
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '8E44AD']
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
        $sheet->getStyle('A2:F' . $lastRow)
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        // تنسيق الأرقام
        $sheet->getStyle('C2:E' . $lastRow)
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

        $sheet->getStyle('F2:F' . $lastRow)
            ->getNumberFormat()
            ->setFormatCode('0.00"%"');

        // إضافة حدود
        $sheet->getStyle('A1:F' . $lastRow)
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

                $sheet->setCellValue('A' . $summaryRow, __('reports.statistics'));
                $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);

                $sheet->setCellValue('A' . ($summaryRow + 1), __('reports.total_revenue') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 1), number_format($this->stats['total_revenue'] ?? 0, 2));

                $sheet->setCellValue('A' . ($summaryRow + 2), __('reports.collected_revenue') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 2), number_format($this->stats['collected_revenue'] ?? 0, 2));

                $sheet->setCellValue('A' . ($summaryRow + 3), __('reports.outstanding_revenue') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 3), number_format($this->stats['outstanding_revenue'] ?? 0, 2));

                $sheet->setCellValue('A' . ($summaryRow + 4), __('reports.collection_rate') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 4), ($this->stats['collection_rate'] ?? 0) . '%');

                $sheet->setCellValue('A' . ($summaryRow + 5), __('reports.average_monthly_revenue') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 5), number_format($this->stats['average_monthly_revenue'] ?? 0, 2));

                // إضافة معلومات التقرير
                $infoRow = $summaryRow + 7; // تعديل المسافة لتكون مناسبة بعد إزالة المخطط
                $sheet->setCellValue('A' . $infoRow, __('reports.report_info'));
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
        return __('reports.revenue_report');
    }
}
