<?php

namespace App\Http\Requests\Admin\Unit;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Unit;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $unitId = $this->route('id');
        $floorId = Unit::whereKey($unitId)->value('floor_id');

        return [
            'name'         => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('units')->where(fn ($q) => $q->where('floor_id', $floorId)->whereNull('deleted_at'))->ignore($unitId),
            ],
            'area'         => ['nullable', 'numeric', 'min:0'],
            'monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'description'  => ['nullable', 'string'],
            'is_active'    => ['boolean'],
        ];
    }
}
