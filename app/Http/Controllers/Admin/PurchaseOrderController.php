<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Constants\Procurement;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\PurchaseOrderReasonRequest;
use App\Http\Requests\Admin\Procurement\PurchaseOrderRequest;
use App\Http\Requests\Admin\Procurement\ReceivePurchaseOrderRequest;
use App\Repository\Admin\PurchaseOrder\PurchaseOrderInterface;
use App\Traits\ProcurementControllerTrait;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ResponseTrait, ProcurementControllerTrait;

    protected $orders;

    public function __construct(PurchaseOrderInterface $orders)
    {
        $this->orders = $orders;
    }

    public function index(Request $request)
    {
        if (!PermissionHelper::checkPermission(Procurement::VIEW_PO)) {
            return $this->denied();
        }

        return $this->respond($this->orders->index($request), __('procurement.fetched'));
    }

    public function show($id)
    {
        if (!PermissionHelper::checkPermission(Procurement::VIEW_PO)) {
            return $this->denied();
        }

        return $this->respond($this->orders->show($id), __('procurement.fetched'));
    }

    public function store(PurchaseOrderRequest $request)
    {
        if (!PermissionHelper::checkPermission(Procurement::CREATE_PO)) {
            return $this->denied();
        }

        return $this->respond($this->orders->store($request), __('procurement.po_created'), Constants::RESPONSE_CREATED);
    }

    public function update(PurchaseOrderRequest $request, $id)
    {
        return $this->act($id, Procurement::EDIT_PO, fn ($o) => $this->orders->update($request, $o), __('procurement.po_updated'));
    }

    public function destroy($id)
    {
        return $this->act($id, Procurement::DELETE_PO, fn ($o) => $this->orders->destroy($o), __('procurement.po_deleted'));
    }

    public function submit($id)
    {
        return $this->act($id, [Procurement::CREATE_PO, Procurement::EDIT_PO], fn ($o) => $this->orders->submit($o), __('procurement.submitted'));
    }

    public function approve($id)
    {
        return $this->act($id, Procurement::APPROVE_PO, fn ($o) => $this->orders->approve($o), __('procurement.approved'));
    }

    public function reject(PurchaseOrderReasonRequest $request, $id)
    {
        return $this->act($id, Procurement::APPROVE_PO, fn ($o) => $this->orders->reject($request, $o), __('procurement.rejected'));
    }

    public function send($id)
    {
        return $this->act($id, Procurement::SEND_PO, fn ($o) => $this->orders->send($o), __('procurement.sent'));
    }

    public function receive(ReceivePurchaseOrderRequest $request, $id)
    {
        return $this->act($id, Procurement::RECEIVE_PO, fn ($o) => $this->orders->receive($request, $o), __('procurement.received'));
    }

    public function cancel(PurchaseOrderReasonRequest $request, $id)
    {
        return $this->act($id, Procurement::CANCEL_PO, fn ($o) => $this->orders->cancel($request, $o), __('procurement.cancelled'));
    }

    // فحص الصلاحية، ثم جلب الأمر (مع تقييد الفرع)، ثم تنفيذ الإجراء
    private function act($id, $permission, callable $action, string $message)
    {
        $allowed = is_array($permission)
            ? PermissionHelper::canAny($permission)
            : PermissionHelper::checkPermission($permission);

        if (!$allowed) {
            return $this->denied();
        }

        $found = $this->orders->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($action($found['data']), $message);
    }
}
