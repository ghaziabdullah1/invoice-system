<?php

namespace App\Http\Requests\Admin\User;

use App\Traits\ResponseTrait;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateUserRequest extends FormRequest
{
    use ResponseTrait;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $userId = $this->route('id');

        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $userId,
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'company_name' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:100',
            'admin_group_id' => 'sometimes|exists:admin_groups,id',
            'is_active' => 'nullable|boolean',
            'property_ids'   => 'nullable|array',
            'property_ids.*' => 'integer|exists:properties,id',
            'branch_ids'   => 'nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            $this->failureResponse($validator->errors()->first(), $validator->errors(), 422)
        );
    }
}
