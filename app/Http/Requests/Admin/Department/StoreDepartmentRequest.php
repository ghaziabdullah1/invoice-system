<?php

namespace App\Http\Requests\Admin\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'code'        => ['required', 'string', 'max:30', Rule::unique('departments', 'code')->whereNull('deleted_at')],
            'name'        => ['required', 'string', 'max:255'],
            'name_en'     => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'manager_id'  => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }
}
