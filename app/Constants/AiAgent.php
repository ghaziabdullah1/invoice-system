<?php

namespace App\Constants;

class AiAgent
{
    const VIEW_DRAFTS    = 'view_ai_drafts';
    const APPROVE_DRAFTS = 'approve_ai_drafts';
    const MANAGE_CHATS   = 'manage_ai_conversations';

    // أدوات القراءة التي يستدعيها النموذج
    const TOOL_INVOICE_STATUS = 'get_invoice_status';
    const TOOL_LIST_INVOICES  = 'list_my_invoices';
    const TOOL_BALANCE        = 'get_my_balance';
}
