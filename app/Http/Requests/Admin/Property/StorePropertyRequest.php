<?php

namespace App\Http\Requests\Admin\Property;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['required', 'string', 'max:255'],
            'name_en'   => ['nullable', 'string', 'max:255'],
            'address'   => ['nullable', 'string'],
            'city'      => ['nullable', 'string', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:20'],
            'email'     => ['nullable', 'email', 'max:255'],
            'notes'     => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
