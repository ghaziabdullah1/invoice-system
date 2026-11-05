<?php

namespace App\Http\Requests\Admin\Property;

use App\Constants\Constants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'required', 'string', 'max:255'],
            'name_en'   => ['nullable', 'string', 'max:255'],
            'type'      => ['sometimes', 'required', Rule::in(Constants::PROPERTY_TYPES)],
            'address'   => ['nullable', 'string'],
            'city'      => ['nullable', 'string', 'max:255'],
            'phone'     => ['nullable', 'string', 'max:20'],
            'email'     => ['nullable', 'email', 'max:255'],
            'notes'     => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
