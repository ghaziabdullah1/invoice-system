<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PropertyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['status' => false, 'success' => false, 'message' => 'غير مصرح', 'data' => null], 401);
        }

        $selected = $request->header('X-Property-Id');

        // السوبر أدمن: يرى كل العقارات، والهيدر اختياري
        if ($user->isSuperAdmin()) {
            $request->attributes->set('selected_property_id', $selected ? (int) $selected : null);
            $request->attributes->set('allowed_property_ids', null);
            return $next($request);
        }

        $allowed = $user->properties()
            ->where('properties.is_active', true)
            ->pluck('properties.id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (empty($allowed)) {
            return response()->json([
                'status' => false,
                'success' => false,
                'message' => 'لا يوجد لديك أي عقار مرتبط بحسابك، تواصل مع الإدارة',
                'data' => null,
            ], 403);
        }

        if ($selected) {
            if (!in_array((int) $selected, $allowed, true)) {
                return response()->json([
                    'status' => false,
                    'success' => false,
                    'message' => 'ليس لديك صلاحية للوصول إلى هذا العقار',
                    'data' => null,
                ], 403);
            }
            $request->attributes->set('selected_property_id', (int) $selected);
        } else {
            $request->attributes->set('selected_property_id', null);
        }

        $request->attributes->set('allowed_property_ids', $allowed);

        return $next($request);
    }
}
