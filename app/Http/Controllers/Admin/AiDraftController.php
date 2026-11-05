<?php

namespace App\Http\Controllers\Admin;

use App\Constants\AiAgent;
use App\Constants\Constants;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiDraft\ApproveAiDraftRequest;
use App\Repository\Admin\AiDraft\AiDraftInterface;
use App\Traits\ProcurementControllerTrait;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class AiDraftController extends Controller
{
    use ResponseTrait, ProcurementControllerTrait;

    protected $drafts;

    public function __construct(AiDraftInterface $drafts)
    {
        $this->drafts = $drafts;
    }

    public function index(Request $request)
    {
        if (!PermissionHelper::checkPermission(AiAgent::VIEW_DRAFTS)) {
            return $this->denied();
        }

        return $this->respond($this->drafts->index($request), __('messages.success'));
    }

    public function show($id)
    {
        if (!PermissionHelper::checkPermission(AiAgent::VIEW_DRAFTS)) {
            return $this->denied();
        }

        return $this->respond($this->drafts->show($id), __('messages.success'));
    }

    public function approve(ApproveAiDraftRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(AiAgent::APPROVE_DRAFTS)) {
            return $this->denied();
        }

        $found = $this->drafts->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->drafts->approve($found['data'], $request->input('body')), __('messages.success'));
    }

    public function reject($id)
    {
        if (!PermissionHelper::checkPermission(AiAgent::APPROVE_DRAFTS)) {
            return $this->denied();
        }

        $found = $this->drafts->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->drafts->reject($found['data']), __('messages.success'));
    }

    public function resumeBot($conversationId)
    {
        if (!PermissionHelper::checkPermission(AiAgent::MANAGE_CHATS)) {
            return $this->denied();
        }

        return $this->respond($this->drafts->resumeBot($conversationId), __('messages.success'));
    }
}
