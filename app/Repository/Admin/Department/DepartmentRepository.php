<?php

namespace App\Repository\Admin\Department;

use App\Constants\Constants;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Traits\ProcurementRepositoryTrait;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DepartmentRepository
{
    use ProcurementRepositoryTrait;

    private const FIELDS = ['code', 'name', 'name_en', 'description', 'manager_id', 'is_active'];

    public function index($request)
    {
        try {
            $perPage = min(max((int) $request->input('per_page', 20), 5), 100);

            $page = Department::with('manager:id,name')
                ->when($request->filled('search'), fn ($q) => $q->search((string) $request->input('search')))
                ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
                ->orderBy('name')
                ->paginate($perPage);

            return $this->ok(['items' => $page->items(), 'pagination' => $this->pagination($page)]);
        } catch (\Throwable $e) {
            return $this->failure($e, 'department.index');
        }
    }

    public function show($id)
    {
        $department = Department::with(['manager:id,name', 'users:id,name,email'])->find($id);

        if (!$department) {
            return $this->fail(__('procurement.department_not_found'), Constants::RESPONSE_NOT_FOUND);
        }

        return $this->ok($department);
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            $data = Arr::only($request->all(), self::FIELDS);

            $department = Department::create(array_merge($data, [
                'is_active' => $data['is_active'] ?? true,
            ]));

            ActivityLog::log('CREATE', __('procurement.department_created') . ': ' . $department->name, $department);

            DB::commit();

            return $this->ok($department->load('manager:id,name'));
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'department.store');
        }
    }

    public function update($request, $department)
    {
        DB::beginTransaction();

        try {
            $data = Arr::only($request->all(), self::FIELDS);
            $old  = $department->toArray();
            $department->update($data);

            ActivityLog::log('UPDATE', __('procurement.department_updated') . ': ' . $department->name, $department, $old, $department->fresh()->toArray());

            DB::commit();

            return $this->ok($department->fresh()->load('manager:id,name'));
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'department.update');
        }
    }

    public function destroy($department)
    {
        DB::beginTransaction();

        try {
            $name = $department->name;
            $department->delete();

            ActivityLog::log('DELETE', __('procurement.department_deleted') . ': ' . $name, $department);

            DB::commit();

            return $this->ok(null);
        } catch (\Throwable $e) {
            DB::rollBack();

            return $this->failure($e, 'department.destroy');
        }
    }
}
