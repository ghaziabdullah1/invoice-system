<?php

namespace App\Http\Requests\Admin\Floor;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Floor;
use Illuminate\Validation\Rule;

class UpdateFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $floorId = $this->route('id');
        $propertyId = Floor::whereKey($floorId)->value('property_id');

        return [
            'name'         => ['sometimes', 'required', 'string', 'max:255'],
            'name_en'      => ['nullable', 'string', 'max:255'],
            'floor_number' => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('floors')->where(fn ($q) => $q->where('property_id', $propertyId)->whereNull('deleted_at'))->ignore($floorId),
            ],
            'description'  => ['nullable', 'string'],
            'is_active'    => ['boolean'],
        ];
    }
}
