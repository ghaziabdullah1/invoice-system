<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportAssignPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $menuId = DB::table('admin_permissions')->where('title', 'view_support_tickets')->value('admin_menu_id');

        if (!$menuId) {
            $this->command?->error('صلاحية view_support_tickets غير موجودة. شغّل PermissionSystemSeeder أولاً.');
            return;
        }

        // لا تُمنح لأي مجموعة تلقائياً: السوبر أدمن يملكها ضمناً، والباقي يُعيَّن من شاشة تعيين الصلاحيات.
        DB::table('admin_permissions')->updateOrInsert(
            ['title' => 'assign_support_ticket'],
            [
                'description_en'    => 'Assign Support Tickets',
                'description_ar'    => 'إسناد تذاكر الدعم',
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
