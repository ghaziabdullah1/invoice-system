<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AiAgentPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('admin_menus')->updateOrInsert(
            ['title_en' => 'AI Agent'],
            [
                'title_ar'   => 'الموظف الذكي',
                'link'       => null,
                'icon_class' => 'fa-robot',
                'sort_order' => 9,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $menuId = DB::table('admin_menus')->where('title_en', 'AI Agent')->value('id');

        $permissions = [
            ['view_ai_drafts', 'View AI Drafts', 'عرض مسودات الموظف الذكي'],
            ['approve_ai_drafts', 'Approve / Reject AI Drafts', 'اعتماد أو رفض المسودات'],
            ['manage_ai_conversations', 'Manage AI Conversations', 'إدارة محادثات الموظف الذكي'],
        ];

        // لا تُمنح لأي مجموعة تلقائياً: السوبر أدمن يملكها ضمناً، والباقي من شاشة تعيين الصلاحيات.
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
