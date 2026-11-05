<?php

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class ReceivePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'received_date'                     => 'nullable|date',
            'notes'                             => 'nullable|string|max:500',
            'items'                             => 'required|array|min:1|max:200',
            'items.*.purchase_order_item_id'    => 'required|integer|exists:purchase_order_items,id',
            'items.*.quantity'                  => 'required|numeric|min:0|max:9999999',
        ];
    }
}
