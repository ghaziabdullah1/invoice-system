<?php

namespace App\Http\Requests\Admin\Contract;

use App\Constants\Constants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenewContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date'              => ['nullable', 'date'],
            'end_date'                => ['required', 'date'],
            'rent_amount'             => ['nullable', 'numeric', 'min:0.01', 'max:9999999999'],
            'payment_frequency'       => ['nullable', Rule::in(array_keys(Constants::CONTRACT_FREQUENCY_MONTHS))],
            'payment_day'             => ['nullable', 'integer', 'between:1,28'],
            'security_deposit'        => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'grace_days'              => ['nullable', 'integer', 'between:0,60'],
            'annual_increase_percent' => ['nullable', 'numeric', 'between:0,100'],
            'notes'                   => ['nullable', 'string', 'max:5000'],
        ];
    }
}
