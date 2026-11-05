<?php

namespace App\Repository\Admin\AiTool;

use App\Constants\AiAgent;
use App\Constants\Constants;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;

class AiToolRepository implements AiToolInterface
{
    // أرقام عملائك غير موحّدة (+966... وأرقام محلية)، فنقارن آخر 8 خانات بعد التنظيف
    private const PHONE_KEY_LENGTH = 8;
    private const MAX_LIST = 5;

    // العميل لا يرى المسودات إطلاقاً
    private const VISIBLE_STATUSES = [
        Constants::INVOICE_STATUS_PAID,
        Constants::INVOICE_STATUS_SENT,
        Constants::INVOICE_STATUS_OVERDUE,
    ];

    // الفواتير غير المسددة
    private const OPEN_STATUSES = [
        Constants::INVOICE_STATUS_SENT,
        Constants::INVOICE_STATUS_OVERDUE,
    ];

    public function matchClient(string $phone): array
    {
        try {
            $digits = preg_replace('/\D/', '', $phone);

            if (strlen($digits) < self::PHONE_KEY_LENGTH) {
                return ['status' => false, 'message' => 'Invalid phone', 'data' => null];
            }

            $key = substr($digits, -self::PHONE_KEY_LENGTH);

            $ids = Client::active()
                ->whereNotNull('phone')
                ->whereRaw("RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), ?) = ?", [self::PHONE_KEY_LENGTH, $key])
                ->limit(2)
                ->pluck('id');

            if ($ids->count() !== 1) {
                return ['status' => false, 'message' => $ids->isEmpty() ? 'No match' : 'Ambiguous match', 'data' => null];
            }

            return ['status' => true, 'data' => Client::find($ids->first())];
        } catch (\Throwable $e) {
            Log::error('AiTool matchClient: ' . $e->getMessage());
            return ['status' => false, 'message' => 'Match failed', 'data' => null];
        }
    }

    public function definitions(): array
    {
        return [
            [
                'name'         => AiAgent::TOOL_INVOICE_STATUS,
                'description'  => "Get the status, amount, dates and instalment plan of ONE of this customer's invoices by its number (e.g. INV-202611-1001).",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'invoice_number' => ['type' => 'string', 'description' => 'The invoice number'],
                    ],
                    'required'   => ['invoice_number'],
                ],
            ],
            [
                'name'         => AiAgent::TOOL_LIST_INVOICES,
                'description'  => "List this customer's latest invoices (maximum 5), optionally filtered by status.",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['unpaid', 'overdue', 'paid']],
                        'limit'  => ['type' => 'integer', 'description' => 'How many invoices, 1 to 5'],
                    ],
                ],
            ],
            [
                'name'         => AiAgent::TOOL_BALANCE,
                'description'  => "Get the total this customer still owes (unpaid and overdue invoices), per currency.",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
        ];
    }

    public function run(string $name, array $input, int $clientId): array
    {
        try {
            return match ($name) {
                AiAgent::TOOL_INVOICE_STATUS => $this->invoiceStatus($clientId, (string) ($input['invoice_number'] ?? '')),
                AiAgent::TOOL_LIST_INVOICES  => $this->listInvoices($clientId, $input['status'] ?? null, (int) ($input['limit'] ?? self::MAX_LIST)),
                AiAgent::TOOL_BALANCE        => $this->balance($clientId),
                default                      => ['status' => false, 'message' => 'Unknown tool', 'data' => null],
            };
        } catch (\Throwable $e) {
            Log::error('AiTool ' . $name . ': ' . $e->getMessage());
            return ['status' => false, 'message' => 'Tool failed', 'data' => null];
        }
    }

    /** كل الاستعلامات تبدأ من هنا: عميل واحد، غير محذوفة، وغير مسودة */
    private function visible(int $clientId)
    {
        return Invoice::where('client_id', $clientId)
            ->whereNull('deleted_at')
            ->whereIn('status', self::VISIBLE_STATUSES);
    }

    private function invoiceStatus(int $clientId, string $number): array
    {
        $number = substr(trim($number), 0, 40);

        $invoice = $number === '' ? null : $this->visible($clientId)->where('invoice_number', $number)->first();

        if (!$invoice) {
            return ['status' => false, 'message' => 'Invoice not found for this customer', 'data' => null];
        }

        $data = $this->format($invoice);

        $plan = $invoice->installmentPlan;
        if ($plan) {
            $next = $plan->installments()->where('status', 'pending')->orderBy('due_date')->first();

            $data['installment_plan'] = [
                'total_installments' => $plan->number_of_installments,
                'paid_installments'  => $plan->paidInstallmentsCount(),
                'next_due_date'      => $next?->due_date?->toDateString(),
                'next_amount'        => $next?->amount,
            ];
        }

        return ['status' => true, 'data' => $data];
    }

    private function listInvoices(int $clientId, ?string $status, int $limit): array
    {
        $limit = min(max($limit, 1), self::MAX_LIST);
        $query = $this->visible($clientId);

        if ($status === 'unpaid') {
            $query->whereIn('status', self::OPEN_STATUSES);
        } elseif ($status === 'overdue') {
            $query->overdue();
        } elseif ($status === 'paid') {
            $query->where('status', Constants::INVOICE_STATUS_PAID);
        }

        $invoices = $query->orderByDesc('invoice_date')->orderByDesc('id')->limit($limit)->get();

        return ['status' => true, 'data' => ['invoices' => $invoices->map(fn ($i) => $this->format($i))->all()]];
    }

    /**
     * المستحق على العميل: الفواتير المرسلة والمتأخرة فقط.
     * (Client::totalDue يحتسب المسودات أيضاً، وهذا لا يصح أن يُقال للعميل.)
     */
    private function balance(int $clientId): array
    {
        $open = $this->visible($clientId)->whereIn('status', self::OPEN_STATUSES)->get();

        $rows = $open->groupBy('currency')->map(function ($group, $currency) {
            $overdue = $group->filter(fn ($i) => $i->isOverdue());

            return [
                'currency'         => $currency,
                'total_due'        => number_format((float) $group->sum('total'), 2, '.', ''),
                'open_invoices'    => $group->count(),
                'overdue_amount'   => number_format((float) $overdue->sum('total'), 2, '.', ''),
                'overdue_invoices' => $overdue->count(),
            ];
        })->values()->all();

        return ['status' => true, 'data' => ['by_currency' => $rows]];
    }

    private function format(Invoice $invoice): array
    {
        $open = in_array($invoice->status, self::OPEN_STATUSES, true);

        return [
            'invoice_number' => $invoice->invoice_number,
            'status'         => $invoice->status,
            'is_overdue'     => $invoice->isOverdue(),
            'total'          => $invoice->total,
            'amount_due'     => $open ? $invoice->total : '0.00',
            'currency'       => $invoice->currency,
            'invoice_date'   => $invoice->invoice_date?->toDateString(),
            'due_date'       => $invoice->due_date?->toDateString(),
            'paid_date'      => $invoice->payment_date?->toDateString(),
        ];
    }
}
