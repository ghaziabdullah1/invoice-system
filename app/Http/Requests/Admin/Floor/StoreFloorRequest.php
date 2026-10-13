<?php

namespace App\Http\Requests\Admin\Floor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_id'  => ['required', Rule::exists('properties', 'id')->whereNull('deleted_at')],
            'name'         => ['required', 'string', 'max:255'],
            'name_en'      => ['nullable', 'string', 'max:255'],
            'floor_number' => [
                'required',
                'integer',
                Rule::unique('floors')->where(fn ($q) => $q->where('property_id', $this->property_id)->whereNull('deleted_at')),
            ],
            'description'  => ['nullable', 'string'],
            'is_active'    => ['boolean'],
        ];
    }
}
