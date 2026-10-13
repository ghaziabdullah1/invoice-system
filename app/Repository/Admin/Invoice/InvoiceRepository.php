<?php

namespace App\Repository\Admin\Invoice;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ActivityLog;
use App\Models\InstallmentPlan;
use App\Models\Installment;
use App\Models\Client;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class InvoiceRepository implements InvoiceInterface
{
    // ✅ قائمة الـ statuses المسموح بها فقط
    private const ALLOWED_STATUSES = [
        Constants::INVOICE_STATUS_DRAFT,
        Constants::INVOICE_STATUS_SENT,
        Constants::INVOICE_STATUS_PAID,
        Constants::INVOICE_STATUS_OVERDUE,
    ];

    // ✅ قائمة الـ currencies المسموح بها
    private const ALLOWED_CURRENCIES = ['USD', 'EUR', 'GBP', 'SAR', 'AED', 'KWD'];

    // ✅ أنواع تكرار الأقساط المسموح بها
    private const ALLOWED_FREQUENCIES = ['weekly', 'monthly'];

    public function index($request)
    {
        try {
            $query = Invoice::with(['client', 'items', 'createdBy'])
                ->when($request->attributes->get('selected_branch_id'), fn($q, $branchId) => $q->where('branch_id', $branchId))
                ->orderBy('created_at', 'desc');

            // ✅ تحقق من الـ status قبل استخدامه في الـ Query
            if ($request->has('status') && !empty($request->status)) {
                if (in_array($request->status, self::ALLOWED_STATUSES, true)) {
                    $query->where('status', $request->status);
                }
            }

            if ($request->has('date_from') && !empty($request->date_from)) {
                // ✅ تحقق أنه تاريخ صالح
                if (strtotime($request->date_from)) {
                    $query->whereDate('invoice_date', '>=', $request->date_from);
                }
            }

            if ($request->has('date_to') && !empty($request->date_to)) {
                if (strtotime($request->date_to)) {
                    $query->whereDate('invoice_date', '<=', $request->date_to);
                }
            }

            if ($request->has('search') && !empty($request->search)) {
                // ✅ الحد بـ 100 حرف لمنع Regex DoS
                $search = substr(trim($request->search), 0, 100);
                $query->search($search);
            }

            // ✅ per_page محدود بين 5 و 100
            $perPage = (int) ($request->per_page ?? Constants::DEFAULT_PER_PAGE);
            $perPage = max(Constants::MIN_PER_PAGE, min($perPage, Constants::MAX_PER_PAGE));

            $invoices = $query->paginate($perPage);

            return [
                'status'  => true,
                'message' => __('messages.invoices_fetched'),
                'data'    => $invoices,
            ];
        } catch (\Exception $e) {
            Log::error('InvoiceRepository index error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function show($id)
    {
        try {
            // ✅ تحقق أن الـ id عدد صحيح موجب
            if (!is_numeric($id) || (int) $id <= 0) {
                return [
                    'status'  => false,
                    'message' => __('messages.not_found'),
                    'data'    => null,
                ];
            }

            $invoice = Invoice::with(['client', 'items', 'createdBy', 'payments', 'installmentPlan.installments'])
                ->inAllowedBranches(request()->attributes->get('allowed_branch_ids'))
                ->find((int) $id);

            if (!$invoice) {
                return [
                    'status'  => false,
                    'message' => __('messages.not_found'),
                    'data'    => null,
                ];
            }

            return [
                'status'  => true,
                'message' => __('messages.invoice_fetched'),
                'data'    => $invoice,
            ];
        } catch (\Exception $e) {
            Log::error('InvoiceRepository show error', ['id' => $id, 'error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            // ── توليد رقم فاتورة فريد ─────────────────────────────────────
            $invoiceNumber = $request->invoice_number;
            if (empty($invoiceNumber)) {
                $invoice       = new Invoice();
                $invoiceNumber = $invoice->generateInvoiceNumber();
            }

            // ✅ تحقق من فريدية رقم الفاتورة
            if (Invoice::where('invoice_number', $invoiceNumber)->exists()) {
                DB::rollBack();

                return [
                    'status'  => false,
                    'message' => __('messages.unique', ['attribute' => 'رقم الفاتورة']),
                    'data'    => null,
                ];
            }

            // ✅ تحقق من الـ status
            $status = $request->status ?? Constants::INVOICE_STATUS_DRAFT;
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                $status = Constants::INVOICE_STATUS_DRAFT;
            }

            // ✅ تحقق من الـ currency
            $currency = strtoupper($request->currency ?? Constants::CURRENCY_SAR);
            if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
                $currency = Constants::CURRENCY_SAR;
            }

            // ✅ أرقام لا تقبل قيم سالبة
            $subtotal       = max(0, (float) ($request->subtotal ?? 0));
            $taxAmount      = max(0, (float) ($request->tax_amount ?? 0));
            $discountAmount = max(0, (float) ($request->discount_amount ?? 0));
            $total          = $subtotal + $taxAmount - $discountAmount;

            // ✅ الحصول على user_id من auth() - دائماً من auth()
            $userId = auth()->id();
            if (!$userId) {
                DB::rollBack();

                return [
                    'status'  => false,
                    'message' => 'المستخدم غير مسجل الدخول',
                    'data'    => null,
                ];
            }

            // ✅ الفرع: من الطلب صراحة إن وُجد، وإلا الفرع المختار حالياً؛
            // ونتحقق أن المستخدم فعلاً يملك صلاحية الوصول لهذا الفرع
            $branchId = $request->has('branch_id') && $request->branch_id
                ? (int) $request->branch_id
                : $request->attributes->get('selected_branch_id');

            $allowedBranchIds = $request->attributes->get('allowed_branch_ids');
            if ($branchId && is_array($allowedBranchIds) && !in_array((int) $branchId, $allowedBranchIds, true)) {
                DB::rollBack();

                return [
                    'status'  => false,
                    'message' => 'ليس لديك صلاحية لإضافة فاتورة لهذا الفرع',
                    'data'    => null,
                ];
            }

            $clientIsAccessible = Client::inAllowedBranches($allowedBranchIds)
                ->whereKey((int) $request->client_id)
                ->exists();

            if (!$clientIsAccessible) {
                DB::rollBack();

                return [
                    'status'  => false,
                    'message' => __('messages.client_not_found'),
                    'data'    => null,
                ];
            }

            $invoice = Invoice::create([
                'client_id'              => (int) $request->client_id,
                'user_id'                => $userId,
                'branch_id'              => $branchId ? (int) $branchId : null,
                'invoice_number'         => $invoiceNumber,
                'invoice_date'           => $request->invoice_date,
                'due_date'               => $request->due_date,
                'status'                 => $status,
                'subtotal'               => $subtotal,
                'tax_amount'             => $taxAmount,
                'discount_amount'        => $discountAmount,
                'total'                  => $total,
                'currency'               => $currency,
                'notes'                  => $request->notes,
                'enable_stripe_checkout' => (bool) ($request->enable_stripe_checkout ?? false),
                'terms'                  => $request->terms,
                'footer'                 => $request->footer,
                'created_by'             => $userId,
                'is_active'              => true,
            ]);

            // ── إضافة العناصر ──────────────────────────────────────────────
            if ($request->has('items') && is_array($request->items)) {
                $itemsTotal = $this->createInvoiceItems($invoice->id, $request->items);

                if ($itemsTotal > 0) {
                    $invoice->update([
                        'subtotal' => $itemsTotal,
                        'total'    => $itemsTotal + $taxAmount - $discountAmount,
                    ]);
                    $total = $itemsTotal + $taxAmount - $discountAmount;
                }
            }

            // ✅ التحقق من سقف الائتمان بعد احتساب الإجمالي النهائي للفاتورة
            $client = Client::find($invoice->client_id);
            if ($client && $client->exceedsCreditLimit($total)) {
                DB::rollBack();

                return [
                    'status'  => false,
                    'message' => __('messages.credit_limit_exceeded', [
                        'limit' => number_format((float) $client->credit_limit, 2),
                    ]),
                    'data'    => null,
                ];
            }

            // ── ✅ إنشاء خطة الأقساط إذا كانت مفعلة ─────────────────────────
            if ($request->has('installment_plan') && is_array($request->installment_plan)) {
                $installmentPlanData = $request->installment_plan;

                // التحقق من صحة بيانات خطة الأقساط
                $planValidation = $this->validateInstallmentPlan($installmentPlanData, $total);
                if (!$planValidation['valid']) {
                    DB::rollBack();
                    return [
                        'status'  => false,
                        'message' => $planValidation['message'],
                        'data'    => null,
                    ];
                }

                $this->createInstallmentPlan($invoice, $installmentPlanData, $total);
            }

            // ── Stripe Checkout ────────────────────────────────────────────
            if ($request->enable_stripe_checkout && $invoice->status !== Constants::INVOICE_STATUS_PAID) {
                $this->createStripeCheckoutSession($invoice->load('client'));
            }

            ActivityLog::log('CREATE', __('messages.invoice_created') . ': ' . $invoice->invoice_number, $invoice);

            DB::commit();

            return [
                'status'  => true,
                'message' => __('messages.invoice_created'),
                'data'    => $invoice->load(['client', 'items', 'createdBy', 'installmentPlan.installments']),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InvoiceRepository store error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function update($request, $invoice)
    {
        DB::beginTransaction();

        try {
            $oldValues = $invoice->toArray();

            // ✅ تحقق من فريدية رقم الفاتورة
            if ($request->has('invoice_number') && $request->invoice_number !== $invoice->invoice_number) {
                if (Invoice::where('invoice_number', $request->invoice_number)->where('id', '!=', $invoice->id)->exists()) {
                    return [
                        'status'  => false,
                        'message' => __('messages.unique', ['attribute' => 'رقم الفاتورة']),
                        'data'    => null,
                    ];
                }
            }

            // ✅ تحقق من الـ status
            $status = $request->status ?? $invoice->status;
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                $status = $invoice->status;
            }

            // ✅ تحقق من الـ currency
            $currency = $request->has('currency')
                ? strtoupper($request->currency)
                : $invoice->currency;

            if (!in_array($currency, self::ALLOWED_CURRENCIES, true)) {
                $currency = $invoice->currency;
            }

            $subtotal       = max(0, (float) ($request->subtotal ?? $invoice->subtotal));
            $taxAmount      = max(0, (float) ($request->tax_amount ?? $invoice->tax_amount));
            $discountAmount = max(0, (float) ($request->discount_amount ?? $invoice->discount_amount));

            // ✅ نقل الفاتورة لفرع آخر عند الطلب الصريح فقط - الفواتير لا
            // تتبع نقل العميل بين الفروع تلقائيًا، هذا هو المسار الوحيد لنقلها
            $branchId = $invoice->branch_id;
            if ($request->has('branch_id')) {
                $newBranchId = $request->branch_id ? (int) $request->branch_id : null;
                $allowedBranchIds = $request->attributes->get('allowed_branch_ids');

                if ($newBranchId && is_array($allowedBranchIds) && !in_array($newBranchId, $allowedBranchIds, true)) {
                    return [
                        'status'  => false,
                        'message' => 'ليس لديك صلاحية لنقل الفاتورة لهذا الفرع',
                        'data'    => null,
                    ];
                }

                $branchId = $newBranchId;
            }

            $invoice->update([
                'client_id'       => $request->client_id       ? (int) $request->client_id : $invoice->client_id,
                'branch_id'       => $branchId,
                'invoice_number'  => $request->invoice_number  ?? $invoice->invoice_number,
                'invoice_date'    => $request->invoice_date    ?? $invoice->invoice_date,
                'due_date'        => $request->due_date        ?? $invoice->due_date,
                'status'          => $status,
                'subtotal'        => $subtotal,
                'tax_amount'      => $taxAmount,
                'discount_amount' => $discountAmount,
                'total'           => $subtotal + $taxAmount - $discountAmount,
                'currency'        => $currency,
                'notes'           => $request->notes  ?? $invoice->notes,
                'terms'           => $request->terms  ?? $invoice->terms,
                'footer'          => $request->footer ?? $invoice->footer,
                'is_active'       => $request->has('is_active') ? (bool) $request->is_active : $invoice->is_active,
            ]);

            // ── تحديث العناصر ──────────────────────────────────────────────
            if ($request->has('items') && is_array($request->items)) {
                $invoice->items()->delete();
                $itemsTotal = $this->createInvoiceItems($invoice->id, $request->items);

                if ($itemsTotal > 0) {
                    $invoice->update([
                        'subtotal' => $itemsTotal,
                        'total'    => $itemsTotal + $taxAmount - $discountAmount,
                    ]);
                }
            }

            // ── ✅ تحديث خطة الأقساط إذا تم إرسالها ─────────────────────────
            if ($request->has('installment_plan') && is_array($request->installment_plan)) {
                $installmentPlanData = $request->installment_plan;

                // إلغاء الخطة القديمة إذا وجدت
                $oldPlan = $invoice->installmentPlan;
                if ($oldPlan) {
                    $oldPlan->update(['status' => 'cancelled']);
                    $oldPlan->installments()->update(['status' => 'cancelled']);
                }

                $planValidation = $this->validateInstallmentPlan($installmentPlanData, $invoice->total);
                if (!$planValidation['valid']) {
                    DB::rollBack();
                    return [
                        'status'  => false,
                        'message' => $planValidation['message'],
                        'data'    => null,
                    ];
                }

                $this->createInstallmentPlan($invoice, $installmentPlanData, $invoice->total);
            }

            ActivityLog::log('UPDATE', __('messages.invoice_updated') . ': ' . $invoice->invoice_number, $invoice, $oldValues, $invoice->fresh()->toArray());

            DB::commit();

            return [
                'status'  => true,
                'message' => __('messages.invoice_updated'),
                'data'    => $invoice->load(['client', 'items', 'createdBy', 'installmentPlan.installments']),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InvoiceRepository update error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function destroy($invoice)
    {
        DB::beginTransaction();

        try {
            $invoiceNumber = $invoice->invoice_number;
            $invoiceId     = $invoice->id;

            ActivityLog::log('DELETE', __('messages.invoice_deleted') . ': ' . $invoiceNumber, $invoice);

            $invoice->items()->delete();
            $invoice->installmentPlans()->delete();
            $invoice->delete();

            DB::commit();

            return [
                'status'  => true,
                'message' => __('messages.invoice_deleted'),
                'data'    => ['id' => $invoiceId],
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InvoiceRepository destroy error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function sendInvoice($invoice)
    {
        try {
            $invoice->markAsSent();
            ActivityLog::log('UPDATE', __('messages.invoice_sent') . ': ' . $invoice->invoice_number, $invoice);

            return [
                'status'  => true,
                'message' => __('messages.invoice_sent'),
                'data'    => $invoice->load(['client', 'items', 'createdBy']),
            ];
        } catch (\Exception $e) {
            Log::error('InvoiceRepository sendInvoice error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function markAsPaid($request, $invoice)
    {
        DB::beginTransaction();

        try {
            $oldValues = $invoice->toArray();

            if ($invoice->status === Constants::INVOICE_STATUS_PAID) {
                return [
                    'status'  => false,
                    'message' => __('messages.invoice_already_paid'),
                    'data'    => null,
                ];
            }

            // ✅ جلب تاريخ الدفع بأمان
            $paymentDate = null;
            if ($request instanceof \Illuminate\Http\Request) {
                $paymentDate = $request->input('payment_date');
            } elseif (is_array($request)) {
                $paymentDate = $request['payment_date'] ?? null;
            }

            // ✅ تحقق أن التاريخ صالح
            if ($paymentDate && !strtotime($paymentDate)) {
                $paymentDate = null;
            }

            $paymentDate = $paymentDate ?? now()->format('Y-m-d');

            $updateData = [
                'status'  => Constants::INVOICE_STATUS_PAID,
                'paid_at' => now(),
            ];

            if (Schema::hasColumn('invoices', 'payment_date')) {
                $updateData['payment_date'] = $paymentDate;
            }

            $invoice->update($updateData);

            // ✅ إذا كانت هناك خطة أقساط، تحديث جميع الأقساط المتبقية كمدفوعة
            $installmentPlan = $invoice->installmentPlan;
            if ($installmentPlan) {
                $installmentPlan->installments()
                    ->where('status', 'pending')
                    ->update([
                        'status'  => 'paid',
                        'paid_at' => now(),
                    ]);
                $installmentPlan->update(['status' => 'completed']);
            }

            ActivityLog::log('UPDATE', __('messages.invoice_marked_paid') . ': #' . $invoice->invoice_number, $invoice, $oldValues, $invoice->fresh()->toArray());

            DB::commit();

            return [
                'status'  => true,
                'message' => __('messages.invoice_marked_paid'),
                'data'    => $invoice->load(['client', 'items', 'installmentPlan.installments']),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InvoiceRepository markAsPaid error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function duplicate($invoice)
    {
        DB::beginTransaction();

        try {
            $newInvoice                 = $invoice->replicate();
            $newInvoice->invoice_number = $invoice->generateInvoiceNumber();
            $newInvoice->status         = Constants::INVOICE_STATUS_DRAFT;
            $newInvoice->sent_at        = null;
            $newInvoice->paid_at        = null;
            $newInvoice->created_by     = auth()->id();
            $newInvoice->user_id        = auth()->id();
            $newInvoice->save();

            foreach ($invoice->items as $item) {
                $newItem             = $item->replicate();
                $newItem->invoice_id = $newInvoice->id;
                $newItem->save();
            }

            ActivityLog::log('CREATE', __('messages.invoice_duplicated') . ': ' . $invoice->invoice_number . ' → ' . $newInvoice->invoice_number, $newInvoice);

            DB::commit();

            return [
                'status'  => true,
                'message' => __('messages.invoice_duplicated'),
                'data'    => $newInvoice->load(['client', 'items', 'createdBy']),
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('InvoiceRepository duplicate error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function generatePDF($invoice)
    {
        try {
            $filePath = 'invoices/invoice_' . $invoice->invoice_number . '.pdf';

            return [
                'status'  => true,
                'message' => __('messages.pdf_generated'),
                'data'    => [
                    'file_path' => $filePath,
                    'file_name' => 'invoice_' . $invoice->invoice_number . '.pdf',
                ],
            ];
        } catch (\Exception $e) {
            Log::error('InvoiceRepository generatePDF error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function getDashboardStats(?int $branchId = null)
    {
        try {
            $stats = Invoice::when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->selectRaw('
                COUNT(*) as total_invoices,
                SUM(total) as total_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as paid_invoices,
                SUM(CASE WHEN status = ? THEN total ELSE 0 END) as paid_amount,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as overdue_invoices,
                SUM(CASE WHEN status = ? THEN total ELSE 0 END) as overdue_amount
            ', [
                Constants::INVOICE_STATUS_PAID,
                Constants::INVOICE_STATUS_PAID,
                Constants::INVOICE_STATUS_OVERDUE,
                Constants::INVOICE_STATUS_OVERDUE,
            ])->first();

            return [
                'status'  => true,
                'message' => __('messages.dashboard_stats_fetched'),
                'data'    => [
                    'total_invoices'   => (int) $stats->total_invoices,
                    'total_amount'     => (float) $stats->total_amount,
                    'paid_invoices'    => (int) $stats->paid_invoices,
                    'paid_amount'      => (float) $stats->paid_amount,
                    'overdue_invoices' => (int) $stats->overdue_invoices,
                    'overdue_amount'   => (float) $stats->overdue_amount,
                    'currency'         => Constants::CURRENCY_SAR,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('InvoiceRepository getDashboardStats error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function getRecentInvoices($limit = 10, ?int $branchId = null)
    {
        try {
            $limit    = min((int) $limit, 50);
            $invoices = Invoice::with(['client'])
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return [
                'status'  => true,
                'message' => __('messages.recent_invoices_fetched'),
                'data'    => $invoices,
            ];
        } catch (\Exception $e) {
            Log::error('InvoiceRepository getRecentInvoices error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    public function getOverdueInvoices(?int $branchId = null)
    {
        try {
            $invoices = Invoice::with(['client'])
                ->where(function ($query) {
                    $query->where('status', Constants::INVOICE_STATUS_OVERDUE)
                        ->orWhere(function ($q) {
                            $q->where('status', Constants::INVOICE_STATUS_SENT)
                                ->where('due_date', '<', now());
                        });
                })
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
                ->orderBy('due_date', 'asc')
                ->get();

            return [
                'status'  => true,
                'message' => __('messages.overdue_invoices_fetched'),
                'data'    => $invoices,
            ];
        } catch (\Exception $e) {
            Log::error('InvoiceRepository getOverdueInvoices error', ['error' => $e->getMessage()]);

            return [
                'status'  => false,
                'message' => __('messages.operation_failed'),
                'data'    => null,
            ];
        }
    }

    // ================================================================
    // Private Helpers
    // ================================================================

    /**
     * إنشاء عناصر الفاتورة بأمان
     */
    private function createInvoiceItems(int $invoiceId, array $items): float
    {
        $itemsTotal = 0;

        foreach ($items as $item) {
            $quantity  = max(0.01, (float) ($item['quantity'] ?? 0));
            $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));
            $taxRate   = min(100, max(0, (float) ($item['tax_rate'] ?? 0)));

            $invoiceItem = InvoiceItem::create([
                'invoice_id'  => $invoiceId,
                'description' => substr(trim($item['description'] ?? ''), 0, 500),
                'quantity'    => $quantity,
                'unit_price'  => $unitPrice,
                'tax_rate'    => $taxRate,
                'total'       => 0,
                'item_type'   => $item['item_type'] ?? 'product',
                'notes'       => $item['notes'] ?? null,
            ]);

            $itemsTotal += $invoiceItem->total;
        }

        return $itemsTotal;
    }

    /**
     * ✅ إضافة: التحقق من صحة بيانات خطة الأقساط
     */
    private function validateInstallmentPlan(array $planData, float $invoiceTotal): array
    {
        // التحقق من عدد الأقساط
        $numberOfInstallments = (int) ($planData['number_of_installments'] ?? 0);
        if ($numberOfInstallments < 2 || $numberOfInstallments > 24) {
            return [
                'valid'   => false,
                'message' => 'عدد الأقساط يجب أن يكون بين 2 و 24',
            ];
        }

        // التحقق من نسبة الفائدة
        $interestRate = (float) ($planData['interest_rate'] ?? 0);
        if ($interestRate < 0 || $interestRate > 100) {
            return [
                'valid'   => false,
                'message' => 'نسبة الفائدة يجب أن تكون بين 0% و 100%',
            ];
        }

        // التحقق من نوع التكرار
        $frequency = $planData['frequency'] ?? 'monthly';
        if (!in_array($frequency, self::ALLOWED_FREQUENCIES, true)) {
            return [
                'valid'   => false,
                'message' => 'نوع التكرار يجب أن يكون weekly أو monthly',
            ];
        }

        // التحقق من تاريخ البدء
        $startDate = $planData['start_date'] ?? null;
        if (!$startDate || !strtotime($startDate)) {
            return [
                'valid'   => false,
                'message' => 'تاريخ بدء خطة الأقساط غير صالح',
            ];
        }

        // التحقق من الملاحظات (اختياري)
        $notes = $planData['notes'] ?? null;
        if ($notes && strlen($notes) > 1000) {
            return [
                'valid'   => false,
                'message' => 'ملاحظات خطة الأقساط لا يمكن أن تتجاوز 1000 حرف',
            ];
        }

        return [
            'valid'   => true,
            'message' => 'بيانات خطة الأقساط صالحة',
        ];
    }

    /**
     * ✅ إضافة: إنشاء خطة الأقساط مع الأقساط الفرعية
     */
    private function createInstallmentPlan(Invoice $invoice, array $planData, float $invoiceTotal): InstallmentPlan
    {
        $numberOfInstallments = (int) $planData['number_of_installments'];
        $interestRate         = (float) ($planData['interest_rate'] ?? 0);
        $frequency            = $planData['frequency'] ?? 'monthly';
        $startDate            = Carbon::parse($planData['start_date']);
        $notes                = $planData['notes'] ?? null;

        // حساب مبلغ الفائدة
        $interestAmount = $invoiceTotal * ($interestRate / 100);
        $totalAmount    = $invoiceTotal + $interestAmount;

        // إنشاء خطة الأقساط
        $installmentPlan = InstallmentPlan::create([
            'invoice_id'             => $invoice->id,
            'number_of_installments' => $numberOfInstallments,
            'interest_rate'          => $interestRate,
            'original_amount'        => $invoiceTotal,
            'interest_amount'        => $interestAmount,
            'total_amount'           => $totalAmount,
            'start_date'             => $startDate->format('Y-m-d'),
            'frequency'              => $frequency,
            'status'                 => 'active',
            'notes'                  => $notes,
            'created_by'             => auth()->id(),
        ]);

        // حساب مبلغ القسط
        $installmentAmount = round($totalAmount / $numberOfInstallments, 2);

        // إنشاء الأقساط الفرعية
        for ($i = 1; $i <= $numberOfInstallments; $i++) {
            $dueDate = $frequency === 'monthly'
                ? $startDate->copy()->addMonths($i - 1)
                : $startDate->copy()->addWeeks($i - 1);

            // القسط الأخير يأخذ الباقي لتجنب مشاكل التقريب
            $amount = ($i === $numberOfInstallments)
                ? round($totalAmount - ($installmentAmount * ($numberOfInstallments - 1)), 2)
                : $installmentAmount;

            Installment::create([
                'installment_plan_id' => $installmentPlan->id,
                'installment_number'  => $i,
                'due_date'            => $dueDate->format('Y-m-d'),
                'amount'              => $amount,
                'status'              => 'pending',
                'payment_id'          => null,
                'paid_at'             => null,
            ]);
        }

        return $installmentPlan;
    }

    /**
     * إنشاء Stripe Checkout Session
     */
    private function createStripeCheckoutSession($invoice): mixed
    {
        try {
            if (!class_exists('\Stripe\Stripe')) {
                Log::error('Stripe PHP library not installed');
                return null;
            }

            \Stripe\Stripe::setApiKey(Config::get('services.stripe.secret'));

            return \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items'           => [[
                    'price_data' => [
                        'currency'     => strtolower($invoice->currency ?? 'sar'),
                        'product_data' => [
                            'name'        => 'فاتورة #' . $invoice->invoice_number,
                            'description' => 'فاتورة من ' . ($invoice->client->company_name ?: $invoice->client->name),
                        ],
                        'unit_amount'  => (int) ($invoice->total * 100),
                    ],
                    'quantity'   => 1,
                ]],
                'mode'           => 'payment',
                'success_url'    => Config::get('app.frontend_url') . '/invoices/' . $invoice->id . '?payment_success=true&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'     => Config::get('app.frontend_url') . '/invoices/' . $invoice->id . '?payment_cancelled=true',
                'customer_email' => $invoice->client->email,
                'metadata'       => [
                    'invoice_id'     => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'client_id'      => $invoice->client_id,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create Stripe Checkout Session', ['error' => $e->getMessage()]);
            return null;
        }
    }
}