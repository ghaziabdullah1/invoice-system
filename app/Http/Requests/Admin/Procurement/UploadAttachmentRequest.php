<?php

namespace App\Http\Requests\Admin\Procurement;

use Illuminate\Foundation\Http\FormRequest;

class UploadAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,docx|max:10240',
        ];
    }
}
