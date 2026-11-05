<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupportStaffPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $menuId = DB::table('admin_permissions')->where('title', 'view_support_tickets')->value('admin_menu_id');

        if (!$menuId) {
            $this->command?->error('صلاحية view_support_tickets غير موجودة. شغّل PermissionSystemSeeder أولاً.');
            return;
        }

        if (DB::table('admin_permissions')->where('title', 'support_agent')->exists()) {
            return;
        }

        // لا تُمنح لأي مجموعة تلقائياً: السوبر أدمن يملكها ضمناً، والباقي من شاشة تعيين الصلاحيات
        DB::table('admin_permissions')->insert([
            'title'             => 'support_agent',
            'description_en'    => 'Support Agent (live chat inbox)',
            'description_ar'    => 'موظف دعم (صندوق المحادثات المباشرة)',
            'admin_menu_id'     => $menuId,
            'admin_sub_menu_id' => null,
            'parent_id'         => null,
            'is_parent'         => false,
            'is_active'         => true,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
