<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class RealtyReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'property_id' => 'nullable|integer|exists:properties,id',
            'all'         => 'nullable|boolean',
            'days'        => 'nullable|in:30,60,90',
            'start_date'  => 'nullable|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'lang'        => 'nullable|in:ar,en',
        ];
    }
}
