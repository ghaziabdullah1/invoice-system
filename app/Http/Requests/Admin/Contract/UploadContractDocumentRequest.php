<?php

namespace App\Http\Requests\Admin\Contract;

use Illuminate\Foundation\Http\FormRequest;

class UploadContractDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
