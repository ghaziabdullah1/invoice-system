<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Constants\Procurement;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\SignPurchaseOrderRequest;
use App\Models\PurchaseOrderApproval;
use App\Repository\Admin\Approval\MyApprovalsRepository;
use App\Repository\Admin\PurchaseOrder\PurchaseOrderInterface;
use App\Traits\ProcurementControllerTrait;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class MyApprovalsController extends Controller
{
    use ResponseTrait, ProcurementControllerTrait;

    public function __construct(
        private MyApprovalsRepository $approvals,
        private PurchaseOrderInterface $orders
    ) {}

    public function index(Request $request)
    {
        if (!$this->canViewApprovals()) {
            return $this->denied();
        }

        return $this->respond($this->approvals->index($request), __('procurement.fetched'));
    }

    public function count()
    {
        return $this->successResponse('', ['count' => $this->approvals->count()]);
    }

    // الموافق يفتح الطلب المعلّق عليه بغض النظر عن فرعه (المسار خارج BranchMiddleware)
    public function show($id)
    {
        if (!$this->canViewApprovals()) {
            return $this->denied();
        }

        return $this->withOrder($id, fn ($order) => $this->ok($order), __('procurement.fetched'));
    }

    public function approve(SignPurchaseOrderRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Procurement::APPROVE_PO)) {
            return $this->denied();
        }

        return $this->withOrder($id, fn ($order) => $this->orders->approve($request, $order), __('procurement.approved'));
    }

    public function reject(SignPurchaseOrderRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Procurement::APPROVE_PO)) {
            return $this->denied();
        }

        return $this->withOrder($id, fn ($order) => $this->orders->reject($request, $order), __('procurement.rejected'));
    }

    private function canViewApprovals(): bool
    {
        return PermissionHelper::canAny([Procurement::VIEW_MY_APPROVALS, Procurement::APPROVE_PO]);
    }

    private function withOrder($id, callable $action, string $message)
    {
        $found = $this->orders->show($id);

        if (!$found['status']) {
            return $this->respond($found, '');
        }

        if (!$this->isApproverOf($found['data']->id)) {
            return $this->denied();
        }

        return $this->respond($action($found['data']), $message);
    }

    private function isApproverOf(int $orderId): bool
    {
        if (PermissionHelper::isSuperAdmin()) {
            return true;
        }

        $userId = auth()->id();

        return PurchaseOrderApproval::where('purchase_order_id', $orderId)
            ->where(fn ($q) => $q->where('approver_id', $userId)->orWhere('escalated_to_id', $userId))
            ->exists();
    }

    private function ok($data): array
    {
        return ['status' => true, 'message' => '', 'data' => $data];
    }
}
