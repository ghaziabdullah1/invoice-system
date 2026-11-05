<?php

namespace App\Repository\Admin\AiDraft;

interface AiDraftInterface
{
    public function index($request);
    public function show($id);
    public function approve($draft, $body = null);
    public function reject($draft);
    public function resumeBot($conversationId);
}
