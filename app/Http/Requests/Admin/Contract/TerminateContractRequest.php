<?php

namespace App\Http\Requests\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

class TerminateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'termination_reason' => ['required', 'string', 'max:255'],
        ];
    }
}
