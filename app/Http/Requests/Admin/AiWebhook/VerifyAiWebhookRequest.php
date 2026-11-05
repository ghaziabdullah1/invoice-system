<?php

namespace App\Http\Requests\Admin\AiWebhook;

use Illuminate\Foundation\Http\FormRequest;

class VerifyAiWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    // PHP يحوّل النقطة في hub.mode إلى شرطة سفلية، فيصل الاسم hub_mode
    public function rules(): array
    {
        return [
            'hub_mode'         => 'required|in:subscribe',
            'hub_verify_token' => 'required|string',
            'hub_challenge'    => 'required|string',
        ];
    }
}
