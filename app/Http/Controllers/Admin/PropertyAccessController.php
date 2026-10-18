<?php

namespace App\Http\Controllers\Admin;

use App\Constants\Constants;
use App\Helpers\PermissionHelper;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PropertyAccessController extends Controller
{
    use ResponseTrait;

    // عقارات المستخدم الحالي (للمنتقي في الـ navbar)
    public function myProperties(Request $request)
    {
        try {
            $user = $request->user();

            $query = $user->isSuperAdmin() ? Property::query() : $user->properties();

            $properties = $query->where('properties.is_active', true)
                ->orderBy('properties.name')
                ->get(['properties.id', 'properties.name', 'properties.name_en', 'properties.city']);

            $default = null;
            if (!$user->isSuperAdmin()) {
                $default = $properties->first(fn ($p) => $p->pivot && $p->pivot->is_default)?->id;
            }
            $default = $default ?? $properties->first()?->id;

            return $this->successResponse(
                __('messages.properties_fetched'),
                [
                    'properties'       => $properties,
                    'default_property' => $default,
                    'is_super_admin'   => $user->isSuperAdmin(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('My properties error: ' . $e->getMessage());
            return $this->failureResponse(__('messages.operation_failed'), null, Constants::RESPONSE_SERVER_ERROR);
        }
    }

    // كل العقارات النشطة لفورم إسناد العقارات (تتطلب صلاحية assign_properties)
    public function assignable()
    {
        if (!PermissionHelper::checkPermission(Constants::ASSIGN_PROPERTIES)) {
            return $this->failureResponse(__('messages.no_permission'), null, Constants::RESPONSE_FORBIDDEN);
        }

        try {
            $properties = Property::active()->orderBy('name')->get(['id', 'name', 'name_en', 'city']);

            return $this->successResponse(__('messages.properties_fetched'), $properties);
        } catch (\Exception $e) {
            Log::error('Assignable properties error: ' . $e->getMessage());
            return $this->failureResponse(__('messages.operation_failed'), null, Constants::RESPONSE_SERVER_ERROR);
        }
    }
}
