<?php

namespace App\Exports;

use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PaymentsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function collection(): \Illuminate\Support\Collection
    {
        $query = Invoice::with(['client'])
            ->where('status', 'paid');

        // Apply filters
        if (isset($this->filters['date_from']) && isset($this->filters['date_to'])) {
            $query->whereBetween('paid_at', [
                $this->filters['date_from'],
                $this->filters['date_to']
            ]);
        }

        if (isset($this->filters['client_id'])) {
            $query->where('client_id', $this->filters['client_id']);
        }

        return $query->get();
    }

    public function headings(): array
    {
        return [
            'Invoice Number',
            'Client',
            'Invoice Date',
            'Payment Date',
            'Amount',
            'Currency',
            'Payment Method',
            'Notes'
        ];
    }

    public function map($invoice): array
    {
        return [
            $invoice->invoice_number,
            $invoice->client ? $invoice->client->name : 'N/A',
            $invoice->invoice_date->format('Y-m-d'),
            $invoice->paid_at ? $invoice->paid_at->format('Y-m-d H:i:s') : 'N/A',
            $invoice->total,
            $invoice->currency,
            $invoice->payment_method ?? 'N/A',
            $invoice->notes ?? ''
        ];
    }

    public function styles(Worksheet $sheet): ?array
    {
        return [
            1 => ['font' => ['bold' => true]],

            'A1:H1' => [
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FFE0E0E0']
                ]
            ],

            'E' => ['numberFormat' => ['formatCode' => '#,##0.00']],
        ];
    }
}
