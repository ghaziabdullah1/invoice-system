<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertyAccessPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $menuId = DB::table('admin_permissions')->where('title', 'view_properties')->value('admin_menu_id');

        if (!$menuId) {
            $this->command?->error('صلاحية view_properties غير موجودة. شغّل PropertyModulePermissionsSeeder أولاً.');
            return;
        }

        // لا تُمنح لأي مجموعة تلقائياً: السوبر أدمن يملكها ضمناً، والباقي يُعيَّن من شاشة تعيين الصلاحيات.
        DB::table('admin_permissions')->updateOrInsert(
            ['title' => 'assign_properties'],
            [
                'description_en'    => 'Assign Properties to Users',
                'description_ar'    => 'تعيين العقارات للمستخدمين',
                'admin_menu_id'     => $menuId,
                'admin_sub_menu_id' => null,
                'parent_id'         => null,
                'is_parent'         => false,
                'is_active'         => true,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]
        );
    }
}
