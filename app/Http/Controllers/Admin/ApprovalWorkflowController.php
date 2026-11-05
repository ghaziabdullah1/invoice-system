<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Constants\Procurement;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Repository\Admin\ApprovalWorkflow\ApprovalWorkflowRepository;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class ApprovalWorkflowController extends Controller
{
    use ResponseTrait;

    public function __construct(private ApprovalWorkflowRepository $repo) {}

    public function index(Request $request)
    {
        if (!PermissionHelper::checkPermission(Procurement::VIEW_APPROVAL_RULES)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }
        $r = $this->repo->index($request);
        return $this->respond($r, __('procurement.fetched'));
    }

    public function show($key)
    {
        if (!PermissionHelper::checkPermission(Procurement::VIEW_APPROVAL_RULES)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }
        $r = $this->repo->show(urldecode($key));
        return $this->respond($r, __('procurement.fetched'));
    }

    public function save(Request $request)
    {
        if (!PermissionHelper::checkPermission(Procurement::MANAGE_APPROVAL_RULES)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }
        $r = $this->repo->save($request);
        return $this->respond($r, __('procurement.workflow_saved'));
    }

    public function destroy($key)
    {
        if (!PermissionHelper::checkPermission(Procurement::MANAGE_APPROVAL_RULES)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }
        $r = $this->repo->destroy(urldecode($key));
        return $this->respond($r, __('procurement.workflow_deleted'));
    }

    private function respond(array $result, string $message)
    {
        if ($result['status']) {
            return $this->successResponse($message, $result['data']);
        }
        return $this->failureResponse($result['message'], null, $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }
}
