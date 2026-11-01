<?php

namespace App\Http\Requests\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateContractInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // ربط المستأجر بعميل موجود بدل إنشاء عميل جديد من بياناته (يُستخدم لأول فاتورة فقط)
            'client_id' => ['nullable', Rule::exists('clients', 'id')],
        ];
    }
}
