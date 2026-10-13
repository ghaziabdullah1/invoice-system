<?php

namespace App\Repository\Admin\Tenant;

use App\Models\Tenant;
use App\Models\ActivityLog;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantRepository implements TenantInterface
{
    public function index($request)
    {
        try {
            $query = Tenant::with('unit:id,name,floor_id')
                ->orderBy('created_at', 'desc');

            if ($request->has('unit_id') && $request->unit_id) {
                $query->where('unit_id', (int) $request->unit_id);
            }

            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', (bool) $request->is_active);
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = substr(trim($request->search), 0, 100);
                $query->search($search);
            }

            if ($request->has('per_page')) {
                $perPage = (int) $request->per_page;
                $perPage = min($perPage, Constants::MAX_PER_PAGE);
                $perPage = max($perPage, Constants::MIN_PER_PAGE);
                $tenants = $query->paginate($perPage);
            } else {
                $tenants = $query->paginate(Constants::DEFAULT_PER_PAGE);
            }

            return ['status' => true, 'message' => __('messages.tenants_fetched'), 'data' => $tenants];
        } catch (\Exception $e) {
            Log::error('TenantRepository index error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function show($id)
    {
        try {
            if (!is_numeric($id) || (int) $id <= 0) {
                return ['status' => false, 'message' => __('messages.tenant_not_found'), 'data' => null];
            }

            $tenant = Tenant::with('unit:id,name,floor_id')->find((int) $id);

            if (!$tenant) {
                return ['status' => false, 'message' => __('messages.tenant_not_found'), 'data' => null];
            }

            return ['status' => true, 'message' => __('messages.tenant_fetched'), 'data' => $tenant];
        } catch (\Exception $e) {
            Log::error('TenantRepository show error', ['id' => $id, 'error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            $unitId = $request->unit_id ? (int) $request->unit_id : null;

            if ($unitId && Tenant::where('unit_id', $unitId)->exists()) {
                DB::rollBack();
                return ['status' => false, 'message' => __('messages.unit_already_taken'), 'data' => null];
            }

            $tenant = Tenant::create([
                'unit_id'    => $unitId,
                'name'       => $request->name,
                'email'      => $request->email,
                'phone'      => $request->phone,
                'id_number'  => $request->id_number,
                'notes'      => $request->notes,
                'is_active'  => $request->is_active ?? true,
                'created_by' => auth()->id(),
            ]);

            ActivityLog::log('CREATE', __('messages.tenant_created') . ': ' . $tenant->name, $tenant);

            DB::commit();

            return ['status' => true, 'message' => __('messages.tenant_created'), 'data' => $tenant->load('unit:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('TenantRepository store error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function update($request, $tenant)
    {
        DB::beginTransaction();

        try {
            $oldValues = $tenant->toArray();

            if ($request->has('unit_id')) {
                $newUnitId = $request->unit_id ? (int) $request->unit_id : null;

                if ($newUnitId && $newUnitId !== $tenant->unit_id && Tenant::where('unit_id', $newUnitId)->exists()) {
                    DB::rollBack();
                    return ['status' => false, 'message' => __('messages.unit_already_taken'), 'data' => null];
                }
            }

            $allowedFields = ['unit_id', 'name', 'email', 'phone', 'id_number', 'notes', 'is_active'];

            $updateData = [];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->$field;
                }
            }

            if (isset($updateData['is_active'])) {
                $updateData['is_active'] = (bool) $updateData['is_active'];
            }
            if (array_key_exists('unit_id', $updateData)) {
                $updateData['unit_id'] = $updateData['unit_id'] ? (int) $updateData['unit_id'] : null;
            }

            $tenant->update($updateData);

            ActivityLog::log('UPDATE', __('messages.tenant_updated') . ': ' . $tenant->name, $tenant, $oldValues, $tenant->fresh()->toArray());

            DB::commit();

            return ['status' => true, 'message' => __('messages.tenant_updated'), 'data' => $tenant->fresh()->load('unit:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('TenantRepository update error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function destroy($tenant)
    {
        DB::beginTransaction();

        try {
            $tenantName = $tenant->name;
            $tenantId   = $tenant->id;

            ActivityLog::log('DELETE', __('messages.tenant_deleted') . ': ' . $tenantName, $tenant);
            $tenant->update(['unit_id' => null]);
            $tenant->delete();

            DB::commit();

            return ['status' => true, 'message' => __('messages.tenant_deleted'), 'data' => ['id' => $tenantId]];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('TenantRepository destroy error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }
}
