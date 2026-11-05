<?php

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id'          => 'nullable|integer|exists:branches,id',
            'supplier_id'        => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'beneficiary_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->whereNull('deleted_at')],
            'order_date'         => 'required|date',
            'expected_date'      => 'nullable|date|after_or_equal:order_date',
            'currency'           => 'nullable|string|size:3',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'discount_amount'    => 'nullable|numeric|min:0|max:999999999',
            'notes'              => 'nullable|string|max:2000',

            'items'                => 'required|array|min:1|max:200',
            'items.*.description'  => 'required|string|max:255',
            'items.*.item_code'    => 'nullable|string|max:50',
            'items.*.unit'         => 'nullable|string|max:20',
            'items.*.quantity'     => 'required|numeric|gt:0|max:9999999',
            'items.*.unit_price'   => 'required|numeric|min:0|max:999999999',
            'items.*.tax_rate'     => 'nullable|numeric|min:0|max:100',
        ];
    }
}
