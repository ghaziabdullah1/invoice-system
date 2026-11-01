<?php

namespace App\Services;

use App\Constants\Constants;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Repository\Admin\Branch\BranchRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * فوترة عقود الإيجار: جدول الاستحقاق + توليد فاتورة (مسودة) لفترة محددة.
 *
 * الاستحقاق = الأحدث من (بداية الفترة) و(يوم الدفع في شهر بداية الفترة).
 * آخر فترة تُحسب بالتناسب إن قصّ تاريخ نهاية العقد دورة كاملة.
 */
class ContractInvoiceService
{
    private const MAX_PERIODS = 240;
    private const ITEM_TYPE = 'service';
    private const CURRENCIES = ['USD', 'EUR', 'GBP', 'SAR', 'AED', 'KWD'];
    private const BILLABLE = [
        Constants::CONTRACT_STATUS_ACTIVE,
        Constants::CONTRACT_STATUS_EXPIRED,
        Constants::CONTRACT_STATUS_RENEWED,
    ];

    // ================================================================
    // جدول الاستحقاق
    // ================================================================
    public function periods(Contract $contract): array
    {
        $months = Constants::CONTRACT_FREQUENCY_MONTHS[$contract->payment_frequency] ?? null;

        if (!$months) {
            return [];
        }

        $start = Carbon::parse($contract->start_date)->startOfDay();
        $end   = Carbon::parse($contract->end_date)->startOfDay();
        $rent  = (float) $contract->rent_amount;
        $day   = max(1, min((int) $contract->payment_day, 28));

        $periods = [];

        for ($i = 0; $i < self::MAX_PERIODS; $i++) {
            $periodStart = $start->copy()->addMonthsNoOverflow($i * $months);

            if ($periodStart->gt($end)) {
                break;
            }

            $nextStart = $start->copy()->addMonthsNoOverflow(($i + 1) * $months);
            $periodEnd = $nextStart->copy()->subDay();
            $amount    = $rent;
            $prorated  = false;

            if ($periodEnd->gt($end)) {
                $totalDays   = max(1, $this->days($periodStart, $nextStart));
                $coveredDays = $this->days($periodStart, $end->copy()->addDay());
                $amount      = round($rent * $coveredDays / $totalDays, 2);
                $periodEnd   = $end->copy();
                $prorated    = true;
            }

            $due = $periodStart->copy()->setDate($periodStart->year, $periodStart->month, min($day, $periodStart->daysInMonth));
            if ($due->lt($periodStart)) {
                $due = $periodStart->copy();
            }

            $periods[] = [
                'number'   => $i + 1,
                'start'    => $periodStart->format(Constants::DATE_FORMAT),
                'end'      => $periodEnd->format(Constants::DATE_FORMAT),
                'due_date' => $due->format(Constants::DATE_FORMAT),
                'invoice_due_date' => $due->copy()->addDays(max(0, (int) $contract->grace_days))->format(Constants::DATE_FORMAT),
                'amount'   => round($amount, 2),
                'prorated' => $prorated,
            ];
        }

        return $periods;
    }

    public function schedule(Contract $contract): array
    {
        $contract->loadMissing('tenant');
        $invoices = $this->invoicesByPeriod((int) $contract->id);

        $periods = array_map(
            fn ($period) => $period + ['invoice' => $invoices[$period['start']] ?? null],
            $this->periods($contract)
        );

        return [
            'contract' => [
                'id'              => $contract->id,
                'contract_number' => $contract->contract_number,
                'status'          => $contract->status,
                'currency'        => $this->currencyOf($contract),
            ],
            'tenant' => [
                'name'       => $contract->tenant?->name,
                'has_email'  => !empty($contract->tenant?->email),
                'has_client' => !empty($contract->tenant?->client_id),
            ],
            'periods' => $periods,
        ];
    }

    public function existingInvoice(int $contractId, string $periodStart): bool
    {
        return Invoice::where('contract_id', $contractId)
            ->whereDate('period_start', $periodStart)
            ->where('status', '!=', 'cancelled')
            ->exists();
    }

    // ================================================================
    // توليد فاتورة لفترة
    // ================================================================
    public function generate(Contract $contract, int $number, ?int $userId, ?int $clientId = null): array
    {
        if (!$userId) {
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }

        $branchId = $this->branchIdFor($userId);

        DB::beginTransaction();

        try {
            $locked = Contract::with(['tenant', 'unit.floor', 'property'])->lockForUpdate()->find($contract->id);

            if (!$locked) {
                return $this->abort(__('contracts.not_found'), Constants::RESPONSE_NOT_FOUND);
            }

            if (!in_array($locked->status, self::BILLABLE, true)) {
                return $this->abort(__('contracts.not_billable'));
            }

            $period = collect($this->periods($locked))->firstWhere('number', $number);

            if (!$period) {
                return $this->abort(__('contracts.period_not_found'), Constants::RESPONSE_NOT_FOUND);
            }

            if ($this->existingInvoice((int) $locked->id, $period['start'])) {
                return $this->abort(__('contracts.invoice_exists'));
            }

            $currency = $this->currencyOf($locked);
            $resolved = $this->resolveClient($locked->tenant, $clientId, $userId, $branchId, $currency);

            if (isset($resolved['error'])) {
                return $this->abort($resolved['error'], $resolved['code']);
            }

            $client = $resolved['client'];
            $amount = (float) $period['amount'];

            if ($client->exceedsCreditLimit($amount)) {
                return $this->abort(__('messages.credit_limit_exceeded', [
                    'limit' => number_format((float) $client->credit_limit, 2),
                ]));
            }

            $invoice = Invoice::create([
                'client_id'       => $client->id,
                'user_id'         => $userId,
                'branch_id'       => $branchId,
                'contract_id'     => $locked->id,
                'period_start'    => $period['start'],
                'period_end'      => $period['end'],
                'invoice_number'  => (new Invoice())->generateInvoiceNumber(),
                'invoice_date'    => now()->format(Constants::DATE_FORMAT),
                'due_date'        => $period['invoice_due_date'],
                'status'          => Constants::INVOICE_STATUS_DRAFT,
                'subtotal'        => 0,
                'tax_amount'      => 0,
                'discount_amount' => 0,
                'total'           => 0,
                'currency'        => $currency,
                'notes'           => __('contracts.rent_invoice_note', ['number' => $locked->contract_number, 'period' => $period['number']]),
                'created_by'      => $userId,
                'is_active'       => true,
            ]);

            $unitName = trim(($locked->unit?->floor?->name ? $locked->unit->floor->name . ' — ' : '') . $locked->unit?->name);

            InvoiceItem::create([
                'invoice_id'  => $invoice->id,
                'description' => __('contracts.rent_description', [
                    'unit'     => $unitName,
                    'property' => $locked->property?->name,
                    'start'    => $period['start'],
                    'end'      => $period['end'],
                    'number'   => $locked->contract_number,
                ]),
                'quantity'    => 1,
                'unit_price'  => $amount,
                'tax_rate'    => 0,
                'total'       => 0,
                'item_type'   => self::ITEM_TYPE,
            ]);

            $invoice->calculateTotals();

            ActivityLog::log('CREATE', __('contracts.invoice_created') . ': ' . $invoice->invoice_number, $invoice);

            DB::commit();

            return [
                'status'  => true,
                'message' => __('contracts.invoice_created'),
                'data'    => $invoice->fresh()->load(['client:id,name,email', 'items']),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ContractInvoiceService generate error', ['contract_id' => $contract->id, 'error' => $e->getMessage()]);

            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    // ================================================================
    // مساعدات
    // ================================================================
    private function resolveClient($tenant, ?int $clientId, int $userId, ?int $branchId, string $currency): array
    {
        if ($tenant->client_id) {
            if ($clientId && $clientId !== (int) $tenant->client_id) {
                return ['error' => __('contracts.client_mismatch'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
            }

            $client = Client::find($tenant->client_id);

            if ($client) {
                return ['client' => $client];
            }
            // العميل المرتبط حُذف: نتابع كأن المستأجر بلا عميل
        }

        if ($clientId) {
            $client = Client::find($clientId);

            if (!$client) {
                return ['error' => __('contracts.client_not_found'), 'code' => Constants::RESPONSE_NOT_FOUND];
            }

            $tenant->update(['client_id' => $client->id]);

            return ['client' => $client];
        }

        if (empty($tenant->email)) {
            return ['error' => __('contracts.tenant_email_required'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
        }

        // بريد العميل فريد: نفس البريد = نفس الحساب فنعيد استخدامه
        $existing = Client::where('email', $tenant->email)->first();

        if ($existing) {
            $tenant->update(['client_id' => $existing->id]);

            return ['client' => $existing];
        }

        $client = Client::create([
            'name'       => $tenant->name,
            'email'      => $tenant->email,
            'phone'      => $tenant->phone,
            'currency'   => $currency,
            'notes'      => 'أُنشئ تلقائياً من المستأجر #' . $tenant->id,
            'is_active'  => true,
            'created_by' => $userId,
            'branch_id'  => $branchId,
        ]);

        $tenant->update(['client_id' => $client->id]);

        return ['client' => $client];
    }

    private function invoicesByPeriod(int $contractId): array
    {
        $map = [];

        $invoices = Invoice::where('contract_id', $contractId)
            ->whereNotNull('period_start')
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'status', 'total', 'period_start']);

        foreach ($invoices as $invoice) {
            $map[$invoice->period_start->format(Constants::DATE_FORMAT)] = [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status'         => $invoice->status,
                'total'          => (float) $invoice->total,
            ];
        }

        return $map;
    }

    private function currencyOf(Contract $contract): string
    {
        $currency = strtoupper((string) $contract->currency);

        return in_array($currency, self::CURRENCIES, true) ? $currency : Constants::CURRENCY_SAR;
    }

    // الفاتورة تتبع الفرع الافتراضي للمستخدم، وبدونه لا يراها من هو مقيّد بفروع
    private function branchIdFor(int $userId): ?int
    {
        try {
            $user = User::find($userId);

            if (!$user) {
                return null;
            }

            $repository = app(BranchRepository::class);
            $default    = $repository->getUserDefaultBranch($user)?->id;

            if ($default) {
                return (int) $default;
            }

            // السوبر أدمن يرى كل الفروع، فلا نُسند فاتورته لفرع عشوائي
            if ($user->admin_group_id == Constants::SUPER_ADMIN_GROUP_ID) {
                return null;
            }

            // بلا فرع افتراضي: أول فرع مسموح له، حتى لا تُنشأ فاتورة لا يراها من هو مقيّد بفروع
            $first = data_get(collect($repository->getUserBranches($user))->first(), 'id');

            return $first ? (int) $first : null;
        } catch (\Throwable $e) {
            Log::warning('ContractInvoiceService branch lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function days(Carbon $from, Carbon $to): int
    {
        return abs((int) round($from->diffInDays($to)));
    }

    private function abort(string $message, $code = Constants::RESPONSE_VALIDATION_ERROR): array
    {
        DB::rollBack();

        return $this->fail($message, $code);
    }

    private function fail(string $message, $code = Constants::RESPONSE_VALIDATION_ERROR): array
    {
        return ['status' => false, 'message' => $message, 'data' => null, 'code' => $code];
    }
}
