<?php

namespace App\Http\Requests\Admin\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'code'        => ['sometimes', 'string', 'max:30', Rule::unique('departments', 'code')->ignore($id)->whereNull('deleted_at')],
            'name'        => ['sometimes', 'string', 'max:255'],
            'name_en'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'manager_id'  => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
            'is_active'   => ['sometimes', 'boolean'],
        ];
    }
}
