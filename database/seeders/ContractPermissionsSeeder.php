<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContractPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $menuId = DB::table('admin_permissions')->where('title', 'view_properties')->value('admin_menu_id');

        if (!$menuId) {
            $this->command?->error('صلاحية view_properties غير موجودة. شغّل PropertyModulePermissionsSeeder أولاً.');
            return;
        }

        $permissions = [
            ['view_contracts', 'View Contracts', 'عرض العقود'],
            ['create_contract', 'Create Contract', 'إنشاء عقد'],
            ['edit_contract', 'Edit Contract', 'تعديل عقد'],
            ['delete_contract', 'Delete Contract', 'حذف عقد'],
            ['renew_contract', 'Renew Contract', 'تجديد عقد'],
            ['terminate_contract', 'Terminate Contract', 'إنهاء عقد'],
        ];

        // لا تُمنح لأي مجموعة تلقائياً: السوبر أدمن يملكها ضمناً، والباقي يُعيَّن من شاشة تعيين الصلاحيات.
        foreach ($permissions as [$title, $en, $ar]) {
            DB::table('admin_permissions')->updateOrInsert(
                ['title' => $title],
                [
                    'description_en'    => $en,
                    'description_ar'    => $ar,
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
}
