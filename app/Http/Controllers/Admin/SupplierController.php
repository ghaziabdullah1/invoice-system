<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Constants\Procurement;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Procurement\SupplierRequest;
use App\Repository\Admin\Supplier\SupplierInterface;
use App\Traits\ProcurementControllerTrait;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ResponseTrait, ProcurementControllerTrait;

    protected $suppliers;

    public function __construct(SupplierInterface $suppliers)
    {
        $this->suppliers = $suppliers;
    }

    public function index(Request $request)
    {
        // من ينشئ أو يعدّل أمر شراء يحتاج قائمة الموردين للاختيار منها
        if (!PermissionHelper::canAny([Procurement::VIEW_SUPPLIERS, Procurement::CREATE_PO, Procurement::EDIT_PO])) {
            return $this->denied();
        }

        return $this->respond($this->suppliers->index($request), __('procurement.fetched'));
    }

    public function show($id)
    {
        if (!PermissionHelper::checkPermission(Procurement::VIEW_SUPPLIERS)) {
            return $this->denied();
        }

        return $this->respond($this->suppliers->show($id), __('procurement.fetched'));
    }

    public function store(SupplierRequest $request)
    {
        if (!PermissionHelper::checkPermission(Procurement::CREATE_SUPPLIER)) {
            return $this->denied();
        }

        return $this->respond($this->suppliers->store($request), __('procurement.supplier_created'), Constants::RESPONSE_CREATED);
    }

    public function update(SupplierRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Procurement::EDIT_SUPPLIER)) {
            return $this->denied();
        }

        $found = $this->suppliers->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->suppliers->update($request, $found['data']), __('procurement.supplier_updated'));
    }

    public function destroy($id)
    {
        if (!PermissionHelper::checkPermission(Procurement::DELETE_SUPPLIER)) {
            return $this->denied();
        }

        $found = $this->suppliers->show($id);
        if (!$found['status']) {
            return $this->respond($found, '');
        }

        return $this->respond($this->suppliers->destroy($found['data']), __('procurement.supplier_deleted'));
    }
}
