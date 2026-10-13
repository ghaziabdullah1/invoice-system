<?php

namespace Database\Seeders;

use App\Constants\Constants;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ActivityLogsPermissionSeeder extends Seeder
{
    /**
     * تُمنح صلاحية سجل النشاط لكل مجموعة تملك صلاحية سجل OTP (المشرفون)،
     * ويمكن منحها لمجموعات أخرى من شاشة توزيع الصلاحيات.
     */
    public function run(): void
    {
        $template = DB::table('admin_permissions')->where('title', Constants::VIEW_OTP_LOGS)->first();

        if (!$template) {
            $this->command->error(Constants::VIEW_OTP_LOGS . ' غير موجودة - لم يُنفَّذ أي شيء');

            return;
        }

        DB::transaction(function () use ($template) {
            $permissionId = DB::table('admin_permissions')
                ->where('title', Constants::VIEW_ACTIVITY_LOGS)
                ->value('id');

            if (!$permissionId) {
                $permissionId = DB::table('admin_permissions')->insertGetId([
                    'title'             => Constants::VIEW_ACTIVITY_LOGS,
                    'description_en'    => 'View Activity Logs',
                    'description_ar'    => 'عرض سجل النشاط',
                    'admin_menu_id'     => $template->admin_menu_id,
                    'admin_sub_menu_id' => $template->admin_sub_menu_id,
                    'parent_id'         => $template->parent_id,
                    'is_parent'         => false,
                    'is_active'         => true,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
            }

            $groupIds = DB::table('admin_group_permissions as gp')
                ->join('admin_permissions as p', 'p.id', '=', 'gp.admin_permission_id')
                ->where('p.title', Constants::VIEW_OTP_LOGS)
                ->pluck('gp.admin_group_id');

            foreach ($groupIds as $groupId) {
                DB::table('admin_group_permissions')->updateOrInsert(
                    ['admin_group_id' => $groupId, 'admin_permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        });
    }
}
