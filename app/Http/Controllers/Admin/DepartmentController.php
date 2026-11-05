<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Constants\Procurement;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Department\StoreDepartmentRequest;
use App\Http\Requests\Admin\Department\UpdateDepartmentRequest;
use App\Repository\Admin\Department\DepartmentRepository;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ResponseTrait;

    public function __construct(private DepartmentRepository $departments) {}

    // ✅ القراءة متاحة لأي مستخدم مسجل (تحتاجها كل النماذج التي تعرض الأقسام)
    public function index(Request $request)
    {
        $result = $this->departments->index($request);
        return $result['status']
            ? $this->successResponse(__('procurement.fetched'), $result['data'])
            : $this->failureResponse($result['message'], null, $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }

    // ✅ تفاصيل قسم واحد أيضاً متاحة للجميع
    public function show($id)
    {
        $result = $this->departments->show($id);
        return $result['status']
            ? $this->successResponse(__('procurement.fetched'), $result['data'])
            : $this->failureResponse($result['message'], null, $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }

    // ⚠️ الإنشاء والتعديل والحذف تبقى محمية
    public function store(StoreDepartmentRequest $request)
    {
        if (!PermissionHelper::checkPermission(Procurement::MANAGE_DEPARTMENTS)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }
        $result = $this->departments->store($request);
        return $result['status']
            ? $this->successResponse(__('procurement.department_created'), $result['data'], Constants::RESPONSE_CREATED)
            : $this->failureResponse($result['message'], null, $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }

    public function update(UpdateDepartmentRequest $request, $id)
    {
        if (!PermissionHelper::checkPermission(Procurement::MANAGE_DEPARTMENTS)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }
        $found = $this->departments->show($id);
        if (!$found['status']) {
            return $this->failureResponse($found['message'], null, $found['code']);
        }
        $result = $this->departments->update($request, $found['data']);
        return $result['status']
            ? $this->successResponse(__('procurement.department_updated'), $result['data'])
            : $this->failureResponse($result['message'], null, $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }

    public function destroy($id)
    {
        if (!PermissionHelper::checkPermission(Procurement::MANAGE_DEPARTMENTS)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }
        $found = $this->departments->show($id);
        if (!$found['status']) {
            return $this->failureResponse($found['message'], null, $found['code']);
        }
        $result = $this->departments->destroy($found['data']);
        return $result['status']
            ? $this->successResponse(__('procurement.department_deleted'), null)
            : $this->failureResponse($result['message'], null, $result['code'] ?? Constants::RESPONSE_SERVER_ERROR);
    }
}