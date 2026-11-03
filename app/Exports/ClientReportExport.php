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

class ClientReportExport implements FromArray, WithHeadings, WithMapping, WithStyles, WithEvents, WithTitle, ShouldAutoSize
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
            __('clients.name'),
            __('clients.email'),
            __('clients.phone'),
            __('clients.company'),
            __('reports.invoices_count'),
            __('reports.total_amount'),
            __('reports.average_invoice'),
            __('reports.created_at'),
            __('reports.client_status')
        ];
    }

    public function map($client): array
    {
        $status = ($client['invoices_count'] ?? 0) > 0 ? __('reports.active') : __('reports.inactive');

        return [
            $client['name'] ?? __('reports.unknown'),
            $client['email'] ?? __('reports.unknown'),
            $client['phone'] ?? __('reports.unknown'),
            $client['company_name'] ?? __('reports.unknown'),
            $client['invoices_count'] ?? 0,
            $client['total_spent'] ?? 0,
            $client['average_invoice'] ?? 0,
            $client['created_at'] ?? __('reports.unknown'),
            $status
        ];
    }

    public function styles(Worksheet $sheet): ?array
    {
        // تنسيق العنوان (9 أعمدة A-I)
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '27AE60']
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
        $sheet->getStyle('A2:I' . $lastRow)
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        // تنسيق الأعمدة الرقمية (F و G)
        $sheet->getStyle('F2:G' . $lastRow)
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

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

                // إضافة صف الإحصائيات
                $summaryRow = $lastRow + 2;

                $sheet->setCellValue('A' . $summaryRow, __('reports.statistics'));
                $sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);

                $sheet->setCellValue('A' . ($summaryRow + 1), __('reports.total_clients') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 1), $this->stats['total_clients'] ?? 0);

                $sheet->setCellValue('A' . ($summaryRow + 2), __('reports.active_clients') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 2), $this->stats['active_clients'] ?? 0);

                $sheet->setCellValue('A' . ($summaryRow + 3), __('reports.total_invoices') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 3), $this->stats['total_invoices'] ?? 0);

                $sheet->setCellValue('A' . ($summaryRow + 4), __('reports.total_revenue') . ':');
                $sheet->setCellValue('B' . ($summaryRow + 4), number_format($this->stats['total_revenue'] ?? 0, 2));

                // ملاحظة: تم إزالة collection_rate لأنه غير موجود في إحصائيات العملاء

                $infoRow = $summaryRow + 6; // تعديل الرقم بعد إزالة صف collection_rate
                $sheet->setCellValue('A' . $infoRow, __('reports.report_info') . ':');
                $sheet->getStyle('A' . $infoRow)->getFont()->setBold(true);

                $sheet->setCellValue('A' . ($infoRow + 1), __('reports.generated_at') . ': ' . date('Y-m-d H:i:s'));
            }
        ];
    }

    public function title(): string
    {
        return __('reports.client_report');
    }
}
