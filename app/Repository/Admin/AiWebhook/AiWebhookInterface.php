<?php

namespace App\Repository\Admin\AiWebhook;

interface AiWebhookInterface
{
    public function verify(?string $token): array;
    public function handle(string $payload, ?string $sigHeader): array;
}
