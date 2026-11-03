<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiWebhook\VerifyAiWebhookRequest;
use App\Repository\Admin\AiWebhook\AiWebhookInterface;
use Illuminate\Http\Request;

class AiWhatsAppWebhookController extends Controller
{
    protected $webhook;

    public function __construct(AiWebhookInterface $webhook)
    {
        $this->webhook = $webhook;
    }

    public function verify(VerifyAiWebhookRequest $request)
    {
        $data = $this->webhook->verify($request->input('hub_verify_token'));

        if ($data['status']) {
            return response($request->input('hub_challenge'), Constants::RESPONSE_SUCCESS)
                ->header('Content-Type', 'text/plain');
        }

        return response()->json(['error' => $data['message']], Constants::RESPONSE_FORBIDDEN);
    }

    public function receive(Request $request)
    {
        $data = $this->webhook->handle($request->getContent(), $request->header('X-Hub-Signature-256'));

        if ($data['status']) {
            return response()->json(['status' => 'success']);
        }

        return response()->json(['error' => $data['message']], Constants::RESPONSE_BAD_REQUEST);
    }
}
