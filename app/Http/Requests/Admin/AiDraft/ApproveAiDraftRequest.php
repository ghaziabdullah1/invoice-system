<?php

namespace App\Http\Requests\Admin\AiDraft;

use Illuminate\Foundation\Http\FormRequest;

class ApproveAiDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => 'nullable|string|max:1000',
        ];
    }
}
