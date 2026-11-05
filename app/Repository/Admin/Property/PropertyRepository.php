<?php

namespace App\Repository\Admin\Property;

use App\Models\Property;
use App\Models\ActivityLog;
use App\Constants\Constants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PropertyRepository implements PropertyInterface
{
    public function index($request)
    {
        try {
            $query = Property::withCount('floors')
                ->with('creator:id,name')
                ->orderBy('created_at', 'desc');

            $allowedPropertyIds = $request->attributes->get('allowed_property_ids');
            if (is_array($allowedPropertyIds)) {
                $query->whereIn('id', $allowedPropertyIds);
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
                $properties = $query->paginate($perPage);
            } else {
                $properties = $query->paginate(Constants::DEFAULT_PER_PAGE);
            }

            return ['status' => true, 'message' => __('messages.properties_fetched'), 'data' => $properties];
        } catch (\Exception $e) {
            Log::error('PropertyRepository index error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function show($id)
    {
        try {
            if (!is_numeric($id) || (int) $id <= 0) {
                return ['status' => false, 'message' => __('messages.property_not_found'), 'data' => null];
            }

            $propertyQuery = Property::withCount('floors')->with('creator:id,name');
            $allowedPropertyIds = request()->attributes->get('allowed_property_ids');
            if (is_array($allowedPropertyIds)) {
                $propertyQuery->whereIn('id', $allowedPropertyIds);
            }
            $property = $propertyQuery->find((int) $id);

            if (!$property) {
                return ['status' => false, 'message' => __('messages.property_not_found'), 'data' => null];
            }

            return ['status' => true, 'message' => __('messages.property_fetched'), 'data' => $property];
        } catch (\Exception $e) {
            Log::error('PropertyRepository show error', ['id' => $id, 'error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function store($request)
    {
        DB::beginTransaction();

        try {
            $property = Property::create([
                'name'       => $request->name,
                'name_en'    => $request->name_en,
                'type'       => $request->type,
                'address'    => $request->address,
                'city'       => $request->city,
                'phone'      => $request->phone,
                'email'      => $request->email,
                'notes'      => $request->notes,
                'is_active'  => $request->is_active ?? true,
                'created_by' => auth()->id(),
            ]);

            if (auth()->user() && !auth()->user()->isSuperAdmin()) {
                auth()->user()->properties()->syncWithoutDetaching([$property->id => ['is_default' => false]]);
            }

            ActivityLog::log('CREATE', __('messages.property_created') . ': ' . $property->name, $property);

            DB::commit();

            return ['status' => true, 'message' => __('messages.property_created'), 'data' => $property->load('creator:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PropertyRepository store error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function update($request, $property)
    {
        DB::beginTransaction();

        try {
            $oldValues = $property->toArray();

            $allowedFields = ['name', 'name_en', 'type', 'address', 'city', 'phone', 'email', 'notes', 'is_active'];

            $updateData = [];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->$field;
                }
            }

            if (isset($updateData['is_active'])) {
                $updateData['is_active'] = (bool) $updateData['is_active'];
            }

            $property->update($updateData);

            ActivityLog::log('UPDATE', __('messages.property_updated') . ': ' . $property->name, $property, $oldValues, $property->fresh()->toArray());

            DB::commit();

            return ['status' => true, 'message' => __('messages.property_updated'), 'data' => $property->fresh()->load('creator:id,name')];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PropertyRepository update error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    public function destroy($property)
    {
        DB::beginTransaction();

        try {
            $property->loadCount('floors');

            if ($property->floors_count > 0) {
                DB::rollBack();
                return ['status' => false, 'message' => __('messages.property_has_floors'), 'data' => null];
            }

            $propertyName = $property->name;
            $propertyId   = $property->id;

            ActivityLog::log('DELETE', __('messages.property_deleted') . ': ' . $propertyName, $property);
            $property->delete();

            DB::commit();

            return ['status' => true, 'message' => __('messages.property_deleted'), 'data' => ['id' => $propertyId]];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PropertyRepository destroy error', ['error' => $e->getMessage()]);
            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }
}
