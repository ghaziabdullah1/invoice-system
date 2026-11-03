<?php

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $name = $this->isMethod('POST') ? 'required' : 'sometimes|required';

        return [
            'name'               => $name . '|string|max:255',
            'name_en'            => 'nullable|string|max:255',
            'contact_person'     => 'nullable|string|max:255',
            'email'              => 'nullable|email|max:255',
            'phone'              => 'nullable|string|max:50',
            'address'            => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:100',
            'tax_number'         => 'nullable|string|max:50',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'currency'           => 'nullable|string|size:3',
            'notes'              => 'nullable|string|max:2000',
            'is_active'          => 'nullable|boolean',
        ];
    }
}
