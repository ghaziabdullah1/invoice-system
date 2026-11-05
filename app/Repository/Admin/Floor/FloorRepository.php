<?php

namespace App\Repository\Admin\Floor;

use App\Models\Floor;
use App\Models\Property;
use App\Models\ActivityLog;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FloorRepository implements FloorInterface
{
    public function index($request)
    {
        try {
            $query = Floor::withCount('units')
                ->with('property:id,name')
                ->orderBy('floor_number');

            $this->limitToProperties($query, true);

            if ($request->has('property_id') && $request->property_id) {
                $query->where('property_id', (int) $request->property_id);
            }

            if ($request->has('is_active') && $request->is_active !== '') {
                $query->where('is_active', (bool) $request->is_active);
            }

            if ($request->has('search') && !empty($request->search)) {
                $search = substr(trim($request->search), 0, 100);
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")->orWhere('name_en', 'like', "%{$search}%");
                });
            }

            if ($request->has('per_page')) {
                $perPage = (int) $request->per_page;
                $perPage = min($perPage, Constants::MAX_PER_PAGE);
                $perPage = max($perPage, Constants::MIN_PER_PAGE);
                $floors = $query->paginate($perPage);
            } else {
                $floors = $query->paginate(Constants::DEFAULT_PER_PAGE);
            }

            return ['status' => true, 'message' => __('messages.floors_fetched'), 'data' => $floors];
        } catch (\Exception $e) {
            Log::error('FloorRepository index error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function show($id)
    {
        try {
            if (!is_numeric($id) || (int) $id <= 0) {
                return ['status' => false, 'message' => __('messages.floor_not_found'), 'data' => null];
            }

            $query = Floor::withCount('units')->with('property:id,name');
            $this->limitToProperties($query);
            $floor = $query->find((int) $id);

            if (!$floor) {
                return ['status' => false, 'message' => __('messages.floor_not_found'), 'data' => null];
            }

            return ['status' => true, 'message' => __('messages.floor_fetched'), 'data' => $floor];
        } catch (\Exception $e) {
            Log::error('FloorRepository show error', ['id' => $id, 'error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            $property = Property::find((int) $request->property_id);

            // لا يُنشأ إلا في عقار مسموح للمستخدم
            $allowed = $this->allowedPropertyIds();
            if ($property && is_array($allowed) && !in_array((int) $property->id, $allowed, true)) {
                $property = null;
            }

            if (!$property) {
                DB::rollBack();
                return ['status' => false, 'message' => __('messages.property_not_found'), 'data' => null];
            }

            $floor = Floor::create([
                'property_id'  => $property->id,
                'name'         => $request->name,
                'name_en'      => $request->name_en,
                'floor_number' => $request->floor_number,
                'description'  => $request->description,
                'is_active'    => $request->is_active ?? true,
                'created_by'   => auth()->id(),
            ]);

            ActivityLog::log('CREATE', __('messages.floor_created') . ': ' . $floor->name, $floor);

            DB::commit();

            return ['status' => true, 'message' => __('messages.floor_created'), 'data' => $floor->load('property:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('FloorRepository store error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function update($request, $floor)
    {
        DB::beginTransaction();

        try {
            $oldValues = $floor->toArray();

            $allowedFields = ['name', 'name_en', 'floor_number', 'description', 'is_active'];

            $updateData = [];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->$field;
                }
            }

            if (isset($updateData['is_active'])) {
                $updateData['is_active'] = (bool) $updateData['is_active'];
            }

            $floor->update($updateData);

            ActivityLog::log('UPDATE', __('messages.floor_updated') . ': ' . $floor->name, $floor, $oldValues, $floor->fresh()->toArray());

            DB::commit();

            return ['status' => true, 'message' => __('messages.floor_updated'), 'data' => $floor->fresh()->load('property:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('FloorRepository update error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function destroy($floor)
    {
        DB::beginTransaction();

        try {
            $floor->loadCount('units');

            if ($floor->units_count > 0) {
                DB::rollBack();
                return ['status' => false, 'message' => __('messages.floor_has_units'), 'data' => null];
            }

            $floorName = $floor->name;
            $floorId   = $floor->id;

            ActivityLog::log('DELETE', __('messages.floor_deleted') . ': ' . $floorName, $floor);
            $floor->delete();

            DB::commit();

            return ['status' => true, 'message' => __('messages.floor_deleted'), 'data' => ['id' => $floorId]];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('FloorRepository destroy error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    // ───────── نطاق العقارات المسموحة (null = بلا تقييد للسوبر أدمن) ─────────
    private function allowedPropertyIds(): ?array
    {
        $allowed = request()->attributes->get('allowed_property_ids');

        return is_array($allowed) ? $allowed : null;
    }

    private function selectedPropertyId(): ?int
    {
        $selected = request()->attributes->get('selected_property_id');

        return $selected ? (int) $selected : null;
    }

    // القائمة تتبع العقار المختار في الشريط العلوي، وغيرها يقيَّد بالعقارات المسموحة فقط
    private function limitToProperties($query, bool $followSelected = false): void
    {
        $allowed  = $this->allowedPropertyIds();
        $selected = $followSelected ? $this->selectedPropertyId() : null;

        if (is_array($allowed)) {
            $query->whereIn('floors.property_id', $allowed);
        }
        if ($selected) {
            $query->where('floors.property_id', $selected);
        }
    }
}
