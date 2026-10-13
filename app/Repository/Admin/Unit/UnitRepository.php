<?php

namespace App\Repository\Admin\Unit;

use App\Models\Unit;
use App\Models\Floor;
use App\Models\ActivityLog;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnitRepository implements UnitInterface
{
    public function index($request)
    {
        try {
            $query = Unit::with('floor:id,name,property_id', 'tenant:id,unit_id,name')
                ->orderBy('name');

            if ($request->has('floor_id') && $request->floor_id) {
                $query->where('floor_id', (int) $request->floor_id);
            }

            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', (bool) $request->is_active);
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = substr(trim($request->search), 0, 100);
                $query->where('name', 'like', "%{$search}%");
            }

            if ($request->has('per_page')) {
                $perPage = (int) $request->per_page;
                $perPage = min($perPage, Constants::MAX_PER_PAGE);
                $perPage = max($perPage, Constants::MIN_PER_PAGE);
                $units = $query->paginate($perPage);
            } else {
                $units = $query->paginate(Constants::DEFAULT_PER_PAGE);
            }

            return ['status' => true, 'message' => __('messages.units_fetched'), 'data' => $units];
        } catch (\Exception $e) {
            Log::error('UnitRepository index error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function show($id)
    {
        try {
            if (!is_numeric($id) || (int) $id <= 0) {
                return ['status' => false, 'message' => __('messages.unit_not_found'), 'data' => null];
            }

            $unit = Unit::with('floor:id,name,property_id', 'tenant')->find((int) $id);

            if (!$unit) {
                return ['status' => false, 'message' => __('messages.unit_not_found'), 'data' => null];
            }

            return ['status' => true, 'message' => __('messages.unit_fetched'), 'data' => $unit];
        } catch (\Exception $e) {
            Log::error('UnitRepository show error', ['id' => $id, 'error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            $floor = Floor::find((int) $request->floor_id);

            if (!$floor) {
                DB::rollBack();
                return ['status' => false, 'message' => __('messages.floor_not_found'), 'data' => null];
            }

            $unit = Unit::create([
                'floor_id'     => $floor->id,
                'name'         => $request->name,
                'area'         => $request->area,
                'monthly_rent' => $request->monthly_rent,
                'description'  => $request->description,
                'is_active'    => $request->is_active ?? true,
                'created_by'   => auth()->id(),
            ]);

            ActivityLog::log('CREATE', __('messages.unit_created') . ': ' . $unit->name, $unit);

            DB::commit();

            return ['status' => true, 'message' => __('messages.unit_created'), 'data' => $unit->load('floor:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UnitRepository store error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function update($request, $unit)
    {
        DB::beginTransaction();

        try {
            $oldValues = $unit->toArray();

            $allowedFields = ['name', 'area', 'monthly_rent', 'description', 'is_active'];

            $updateData = [];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->$field;
                }
            }

            if (isset($updateData['is_active'])) {
                $updateData['is_active'] = (bool) $updateData['is_active'];
            }

            $unit->update($updateData);

            ActivityLog::log('UPDATE', __('messages.unit_updated') . ': ' . $unit->name, $unit, $oldValues, $unit->fresh()->toArray());

            DB::commit();

            return ['status' => true, 'message' => __('messages.unit_updated'), 'data' => $unit->fresh()->load('floor:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UnitRepository update error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function destroy($unit)
    {
        DB::beginTransaction();

        try {
            $unit->loadCount('tenant');

            if ($unit->tenant_count > 0) {
                DB::rollBack();
                return ['status' => false, 'message' => __('messages.unit_has_tenant'), 'data' => null];
            }

            $unitName = $unit->name;
            $unitId   = $unit->id;

            ActivityLog::log('DELETE', __('messages.unit_deleted') . ': ' . $unitName, $unit);
            $unit->delete();

            DB::commit();

            return ['status' => true, 'message' => __('messages.unit_deleted'), 'data' => ['id' => $unitId]];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('UnitRepository destroy error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }
}
