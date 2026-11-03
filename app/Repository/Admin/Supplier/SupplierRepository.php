<?php

namespace App\Repository\Admin\Supplier;

use App\Constants\Constants;
use App\Models\ActivityLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Traits\ProcurementRepositoryTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SupplierRepository implements SupplierInterface
{
    use ProcurementRepositoryTrait;

    private const FIELDS = [
        'name', 'name_en', 'contact_person', 'email', 'phone', 'address', 'city',
        'tax_number', 'payment_terms_days', 'currency', 'notes', 'is_active',
    ];

    public function index($request)
    {
        try {
            $perPage = min(max((int) $request->input('per_page', 20), 5), 100);

            $page = Supplier::withCount('purchaseOrders')
                ->when($request->filled('search'), fn ($q) => $q->search((string) $request->input('search')))
                ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                ->orderBy('name')
                ->paginate($perPage);

            return $this->ok(['items' => $page->items(), 'pagination' => $this->pagination($page)]);
        } catch (\Throwable $e) {
            return $this->failure($e, 'supplier.index');
        }
    }

    public function show($id)
    {
        $supplier = Supplier::withCount('purchaseOrders')->find($id);

        if (!$supplier) {
            return $this->fail(__('procurement.supplier_not_found'), Constants::RESPONSE_NOT_FOUND);
        }

        return $this->ok($supplier);
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            $data = $this->data($request);

            $supplier = Supplier::create(array_merge($data, [
                'supplier_code'      => $this->nextNumber('suppliers', 'supplier_code', 'SUP', false),
                'currency'           => strtoupper($data['currency'] ?? '') ?: Constants::CURRENCY_SAR,
                'payment_terms_days' => $data['payment_terms_days'] ?? 0,
                'is_active'          => $data['is_active'] ?? true,
                'created_by'         => auth()->id(),
            ]));

            ActivityLog::log('CREATE', __('procurement.supplier_created') . ': ' . $supplier->supplier_code, $supplier);

            DB::commit();

            return $this->ok($supplier);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'supplier.store');
        }
    }

    public function update($request, $supplier)
    {
        DB::beginTransaction();

        try {
            $data = $this->data($request);
            if (isset($data['currency'])) {
                $data['currency'] = strtoupper($data['currency']) ?: Constants::CURRENCY_SAR;
            }
            if (array_key_exists('payment_terms_days', $data) && $data['payment_terms_days'] === null) {
                $data['payment_terms_days'] = 0;
            }

            $old = $supplier->toArray();
            $supplier->update($data);

            ActivityLog::log('UPDATE', __('procurement.supplier_updated') . ': ' . $supplier->supplier_code, $supplier, $old, $supplier->fresh()->toArray());

            DB::commit();

            return $this->ok($supplier->fresh());
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'supplier.update');
        }
    }

    public function destroy($supplier)
    {
        DB::beginTransaction();

        try {
            if (PurchaseOrder::where('supplier_id', $supplier->id)->exists()) {
                return $this->bail(__('procurement.supplier_in_use'));
            }

            $code = $supplier->supplier_code;
            $supplier->delete();

            ActivityLog::log('DELETE', __('procurement.supplier_deleted') . ': ' . $code, $supplier);

            DB::commit();

            return $this->ok(null);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'supplier.destroy');
        }
    }

    private function data($request): array
    {
        return Arr::only($request->all(), self::FIELDS);
    }
}
