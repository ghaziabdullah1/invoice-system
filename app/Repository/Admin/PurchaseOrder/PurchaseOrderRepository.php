<?php

namespace App\Repository\Admin\PurchaseOrder;

use App\Constants\Constants;
use App\Constants\Procurement;
use App\Helpers\PermissionHelper;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Traits\ProcurementRepositoryTrait;
use Illuminate\Support\Facades\DB;

class PurchaseOrderRepository implements PurchaseOrderInterface
{
    use ProcurementRepositoryTrait;

    private const RELATIONS = [
        'supplier:id,supplier_code,name,name_en,payment_terms_days,currency',
        'branch:id,name,name_en',
        'items',
        'receipts.items',
        'receipts.receiver:id,name',
        'creator:id,name',
        'approver:id,name',
    ];

    // ================================================================
    // القراءة
    // ================================================================
    public function index($request)
    {
        try {
            $perPage = min(max((int) $request->input('per_page', 20), 5), 100);

            $base = PurchaseOrder::query()
                ->inAllowedBranches($this->attr('allowed_branch_ids'))
                ->when($this->attr('selected_branch_id'), fn ($q, $b) => $q->where('purchase_orders.branch_id', (int) $b))
                ->when($request->filled('supplier_id'), fn ($q) => $q->where('purchase_orders.supplier_id', (int) $request->input('supplier_id')))
                ->when($request->filled('from'), fn ($q) => $q->whereDate('purchase_orders.order_date', '>=', $request->input('from')))
                ->when($request->filled('to'), fn ($q) => $q->whereDate('purchase_orders.order_date', '<=', $request->input('to')))
                ->when($request->filled('search'), function ($q) use ($request) {
                    $s = substr(trim((string) $request->input('search')), 0, 100);
                    $q->where(function ($w) use ($s) {
                        $w->where('purchase_orders.po_number', 'like', "%{$s}%")
                            ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$s}%")->orWhere('name_en', 'like', "%{$s}%"));
                    });
                });

            $counts = (clone $base)
                ->selectRaw('purchase_orders.status, COUNT(*) as n')
                ->groupBy('purchase_orders.status')
                ->pluck('n', 'status')
                ->map(fn ($n) => (int) $n)
                ->all();

            $openValue = (clone $base)
                ->whereIn('purchase_orders.status', Procurement::OPEN_STATUSES)
                ->selectRaw('purchase_orders.currency, SUM(purchase_orders.total) as s')
                ->groupBy('purchase_orders.currency')
                ->pluck('s', 'currency')
                ->map(fn ($v) => round((float) $v, 2))
                ->all();

            $status = (string) $request->input('status', '');

            $page = (clone $base)
                ->with(['supplier:id,supplier_code,name,name_en', 'branch:id,name,name_en'])
                ->when(in_array($status, Procurement::STATUSES, true), fn ($q) => $q->where('purchase_orders.status', $status))
                ->orderByDesc('purchase_orders.order_date')
                ->orderByDesc('purchase_orders.id')
                ->paginate($perPage);

            return $this->ok([
                'items'      => $page->items(),
                'stats'      => [
                    'by_status'  => array_merge(array_fill_keys(Procurement::STATUSES, 0), $counts),
                    'open_value' => $openValue,
                ],
                'pagination' => $this->pagination($page),
            ]);
        } catch (\Throwable $e) {
            return $this->failure($e, 'po.index');
        }
    }

    public function show($id)
    {
        $order = PurchaseOrder::with(self::RELATIONS)
            ->inAllowedBranches($this->attr('allowed_branch_ids'))
            ->find($id);

        if (!$order) {
            return $this->fail(__('procurement.po_not_found'), Constants::RESPONSE_NOT_FOUND);
        }

        return $this->ok($order);
    }

    // ================================================================
    // الإنشاء والتعديل والحذف
    // ================================================================
    public function store($request)
    {
        $branchId = (int) ($this->attr('selected_branch_id') ?: $request->input('branch_id'));

        if (!$branchId) {
            return $this->fail(__('procurement.branch_required'));
        }
        if (!$this->branchAllowed($branchId)) {
            return $this->fail(__('procurement.branch_forbidden'), Constants::RESPONSE_FORBIDDEN);
        }

        $supplier = Supplier::find($request->input('supplier_id'));
        if (!$supplier || !$supplier->is_active) {
            return $this->fail(__('procurement.supplier_inactive'));
        }

        $calc = $this->calculate((array) $request->input('items', []), (float) $request->input('discount_amount', 0));
        if ($calc === null) {
            return $this->fail(__('procurement.discount_too_high'));
        }

        DB::beginTransaction();

        try {
            $order = PurchaseOrder::create([
                'po_number'          => $this->nextNumber('purchase_orders', 'po_number', 'PO'),
                'branch_id'          => $branchId,
                'supplier_id'        => $supplier->id,
                'status'             => Procurement::STATUS_DRAFT,
                'order_date'         => $request->input('order_date'),
                'expected_date'      => $request->input('expected_date') ?: null,
                'currency'           => $this->currencyFor($request, $supplier),
                'payment_terms_days' => $request->filled('payment_terms_days') ? (int) $request->input('payment_terms_days') : $supplier->payment_terms_days,
                'subtotal'           => $calc['subtotal'],
                'tax_amount'         => $calc['tax_amount'],
                'discount_amount'    => $calc['discount_amount'],
                'total'              => $calc['total'],
                'notes'              => $request->input('notes'),
                'created_by'         => auth()->id(),
            ]);

            $order->items()->createMany($calc['rows']);

            ActivityLog::log('CREATE', __('procurement.po_created') . ': ' . $order->po_number, $order);

            DB::commit();

            return $this->ok($this->load($order->id));
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'po.store');
        }
    }

    public function update($request, $order)
    {
        $supplier = Supplier::find($request->input('supplier_id'));
        if (!$supplier || !$supplier->is_active) {
            return $this->fail(__('procurement.supplier_inactive'));
        }

        $calc = $this->calculate((array) $request->input('items', []), (float) $request->input('discount_amount', 0));
        if ($calc === null) {
            return $this->fail(__('procurement.discount_too_high'));
        }

        DB::beginTransaction();

        try {
            $locked = PurchaseOrder::lockForUpdate()->find($order->id);

            if (!$locked || !in_array($locked->status, Procurement::EDITABLE_STATUSES, true)) {
                return $this->bail(__('procurement.cannot_edit'));
            }

            $old = $locked->toArray();

            $locked->update([
                'supplier_id'         => $supplier->id,
                'status'              => Procurement::STATUS_DRAFT,
                'order_date'          => $request->input('order_date'),
                'expected_date'       => $request->input('expected_date') ?: null,
                'currency'            => $this->currencyFor($request, $supplier),
                'payment_terms_days'  => $request->filled('payment_terms_days') ? (int) $request->input('payment_terms_days') : $supplier->payment_terms_days,
                'subtotal'            => $calc['subtotal'],
                'tax_amount'          => $calc['tax_amount'],
                'discount_amount'     => $calc['discount_amount'],
                'total'               => $calc['total'],
                'notes'               => $request->input('notes'),
                'rejected_by'         => null,
                'rejected_at'         => null,
                'rejection_reason'    => null,
            ]);

            $locked->items()->delete();
            $locked->items()->createMany($calc['rows']);

            ActivityLog::log('UPDATE', __('procurement.po_updated') . ': ' . $locked->po_number, $locked, $old, $locked->fresh()->toArray());

            DB::commit();

            return $this->ok($this->load($locked->id));
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'po.update');
        }
    }

    public function destroy($order)
    {
        DB::beginTransaction();

        try {
            $locked = PurchaseOrder::lockForUpdate()->find($order->id);

            if (!$locked || !in_array($locked->status, Procurement::DELETABLE_STATUSES, true)) {
                return $this->bail(__('procurement.cannot_delete'));
            }

            $number = $locked->po_number;
            $locked->delete();

            ActivityLog::log('DELETE', __('procurement.po_deleted') . ': ' . $number, $locked);

            DB::commit();

            return $this->ok(null);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'po.destroy');
        }
    }

    // ================================================================
    // دورة الحالة
    // ================================================================
    public function submit($order)
    {
        return $this->move(
            $order->id,
            Procurement::EDITABLE_STATUSES,
            Procurement::STATUS_PENDING_APPROVAL,
            [
                'submitted_at'     => now(),
                'rejected_by'      => null,
                'rejected_at'      => null,
                'rejection_reason' => null,
            ],
            __('procurement.submitted')
        );
    }

    public function approve($order)
    {
        return $this->move(
            $order->id,
            [Procurement::STATUS_PENDING_APPROVAL],
            Procurement::STATUS_APPROVED,
            ['approved_by' => auth()->id(), 'approved_at' => now()],
            __('procurement.approved'),
            function (PurchaseOrder $po) {
                // مبدأ فصل المهام: من أنشأ الأمر لا يعتمده، إلا السوبر أدمن
                if ($po->created_by && $po->created_by === auth()->id() && !PermissionHelper::isSuperAdmin()) {
                    return $this->fail(__('procurement.self_approval'), Constants::RESPONSE_FORBIDDEN);
                }

                return null;
            }
        );
    }

    public function reject($request, $order)
    {
        $reason = trim((string) $request->input('reason'));

        if ($reason === '') {
            return $this->fail(__('procurement.reason_required'));
        }

        return $this->move(
            $order->id,
            [Procurement::STATUS_PENDING_APPROVAL],
            Procurement::STATUS_REJECTED,
            ['rejected_by' => auth()->id(), 'rejected_at' => now(), 'rejection_reason' => $reason, 'approved_by' => null, 'approved_at' => null],
            __('procurement.rejected')
        );
    }

    public function send($order)
    {
        return $this->move(
            $order->id,
            [Procurement::STATUS_APPROVED],
            Procurement::STATUS_SENT,
            ['sent_at' => now()],
            __('procurement.sent')
        );
    }

    public function cancel($request, $order)
    {
        $reason = trim((string) $request->input('reason'));

        if ($reason === '') {
            return $this->fail(__('procurement.reason_required'));
        }

        return $this->move(
            $order->id,
            Procurement::CANCELLABLE_STATUSES,
            Procurement::STATUS_CANCELLED,
            ['cancelled_by' => auth()->id(), 'cancelled_at' => now(), 'cancellation_reason' => $reason],
            __('procurement.cancelled')
        );
    }

    // ================================================================
    // الاستلام (جزئي أو كامل)
    // ================================================================
    public function receive($request, $order)
    {
        DB::beginTransaction();

        try {
            $po = PurchaseOrder::lockForUpdate()->find($order->id);

            if (!$po || !in_array($po->status, Procurement::RECEIVABLE_STATUSES, true)) {
                return $this->bail(__('procurement.invalid_state'));
            }

            $wanted = [];
            foreach ((array) $request->input('items', []) as $line) {
                $qty = round((float) ($line['quantity'] ?? 0), 3);
                if ($qty > 0) {
                    $id = (int) ($line['purchase_order_item_id'] ?? 0);
                    $wanted[$id] = round(($wanted[$id] ?? 0) + $qty, 3);
                }
            }

            if (empty($wanted)) {
                return $this->bail(__('procurement.receipt_empty'));
            }

            $items = $po->items()->lockForUpdate()->get()->keyBy('id');

            foreach ($wanted as $id => $qty) {
                $item = $items->get($id);

                if (!$item) {
                    return $this->bail(__('procurement.receipt_item_invalid'));
                }
                if ($qty > round((float) $item->quantity - (float) $item->received_quantity, 3)) {
                    return $this->bail(__('procurement.receipt_exceeds') . ': ' . $item->description);
                }
            }

            $receipt = GoodsReceipt::create([
                'receipt_number'    => $this->nextNumber('goods_receipts', 'receipt_number', 'GRN'),
                'purchase_order_id' => $po->id,
                'received_date'     => $request->input('received_date') ?: today()->format(Constants::DATE_FORMAT),
                'received_by'       => auth()->id(),
                'notes'             => $request->input('notes'),
            ]);

            foreach ($wanted as $id => $qty) {
                $receipt->items()->create(['purchase_order_item_id' => $id, 'quantity' => $qty]);

                $item = $items->get($id);
                $item->received_quantity = round((float) $item->received_quantity + $qty, 3);
                $item->save();
            }

            $complete = $items->every(fn ($i) => round((float) $i->quantity - (float) $i->received_quantity, 3) <= 0);
            $old      = $po->toArray();

            $po->update(['status' => $complete ? Procurement::STATUS_RECEIVED : Procurement::STATUS_PARTIALLY_RECEIVED]);

            ActivityLog::log('UPDATE', __('procurement.received') . ': ' . $po->po_number . ' (' . $receipt->receipt_number . ')', $po, $old, $po->fresh()->toArray());

            DB::commit();

            return $this->ok($this->load($po->id));
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'po.receive');
        }
    }

    // ================================================================
    // Helpers
    // ================================================================
    private function move(int $id, array $from, string $to, array $extra, string $message, ?callable $guard = null): array
    {
        DB::beginTransaction();

        try {
            $po = PurchaseOrder::lockForUpdate()->find($id);

            if (!$po || !in_array($po->status, $from, true)) {
                return $this->bail(__('procurement.invalid_state'));
            }

            if ($guard && ($error = $guard($po))) {
                DB::rollBack();

                return $error;
            }

            $old = $po->toArray();
            $po->update(array_merge(['status' => $to], $extra));

            ActivityLog::log('UPDATE', $message . ': ' . $po->po_number, $po, $old, $po->fresh()->toArray());

            DB::commit();

            return $this->ok($this->load($po->id));
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'po.move');
        }
    }

    private function load(int $id)
    {
        return PurchaseOrder::with(self::RELATIONS)->find($id);
    }

    private function attr(string $key)
    {
        return request()->attributes->get($key);
    }

    private function branchAllowed(int $branchId): bool
    {
        $allowed = $this->attr('allowed_branch_ids');

        return !is_array($allowed) || in_array($branchId, $allowed, true);
    }

    private function currencyFor($request, Supplier $supplier): string
    {
        return strtoupper((string) $request->input('currency')) ?: ($supplier->currency ?: Constants::CURRENCY_SAR);
    }

    // الإجمالي = المجموع قبل الضريبة + الضريبة - الخصم. null إذا كان الخصم أكبر من الإجمالي.
    private function calculate(array $items, float $discount): ?array
    {
        $rows = [];
        $subtotal = 0.0;
        $tax = 0.0;

        foreach (array_values($items) as $i => $item) {
            $qty   = round((float) ($item['quantity'] ?? 0), 3);
            $price = round((float) ($item['unit_price'] ?? 0), 2);
            $rate  = round((float) ($item['tax_rate'] ?? 0), 2);
            $line  = round($qty * $price, 2);
            $lineTax = round($line * $rate / 100, 2);

            $rows[] = [
                'sort_order'  => $i + 1,
                'item_code'   => $item['item_code'] ?? null,
                'description' => $item['description'] ?? '',
                'unit'        => $item['unit'] ?? null,
                'quantity'    => $qty,
                'unit_price'  => $price,
                'tax_rate'    => $rate,
                'total'       => round($line + $lineTax, 2),
            ];

            $subtotal += $line;
            $tax += $lineTax;
        }

        $subtotal = round($subtotal, 2);
        $tax      = round($tax, 2);
        $discount = round($discount, 2);

        if ($discount > round($subtotal + $tax, 2)) {
            return null;
        }

        return [
            'rows'            => $rows,
            'subtotal'        => $subtotal,
            'tax_amount'      => $tax,
            'discount_amount' => $discount,
            'total'           => round($subtotal + $tax - $discount, 2),
        ];
    }
}
