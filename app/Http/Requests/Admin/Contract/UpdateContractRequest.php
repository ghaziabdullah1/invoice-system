<?php

namespace App\Http\Requests\Admin\Contract;

use App\Constants\Constants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // الوحدة والمستأجر لا يُعدَّلان: لتغييرهما يُنهى العقد ويُنشأ غيره
    public function rules(): array
    {
        return [
            'start_date'              => ['sometimes', 'required', 'date'],
            'end_date'                => ['sometimes', 'required', 'date'],
            'rent_amount'             => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999'],
            'payment_frequency'       => ['sometimes', Rule::in(array_keys(Constants::CONTRACT_FREQUENCY_MONTHS))],
            'currency'                => ['sometimes', 'nullable', Rule::in(['USD', 'EUR', 'GBP', 'SAR', 'AED', 'KWD'])],
            'payment_day'             => ['sometimes', 'nullable', 'integer', 'between:1,28'],
            'security_deposit'        => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'grace_days'              => ['sometimes', 'nullable', 'integer', 'between:0,60'],
            'annual_increase_percent' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'status'                  => ['sometimes', Rule::in([Constants::CONTRACT_STATUS_DRAFT, Constants::CONTRACT_STATUS_ACTIVE])],
            'notes'                   => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
