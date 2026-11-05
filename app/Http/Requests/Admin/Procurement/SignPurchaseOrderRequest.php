<?php

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class SignPurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Base64 PNG من canvas — الحد الأقصى 1MB بعد الترميز
            'signature' => 'required|string|max:1048576',
            'reason'    => 'nullable|string|max:500',
        ];
    }
}
