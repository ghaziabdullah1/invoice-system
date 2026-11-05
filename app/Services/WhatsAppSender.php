<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppSender
{
    /** يرسل نصاً ويعيد معرّف الرسالة عند Meta */
    public function sendText(string $to, string $body): ?string
    {
        $cfg = config('services.whatsapp');

        if (empty($cfg['token']) || empty($cfg['phone_number_id'])) {
            throw new RuntimeException('WhatsApp is not configured');
        }

        $url = rtrim($cfg['base_url'], '/') . "/{$cfg['graph_version']}/{$cfg['phone_number_id']}/messages";

        $response = Http::withToken($cfg['token'])->timeout(15)->post($url, [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $body],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp send failed: ' . $response->status() . ' ' . $response->body());
        }

        return $response->json('messages.0.id');
    }
}
