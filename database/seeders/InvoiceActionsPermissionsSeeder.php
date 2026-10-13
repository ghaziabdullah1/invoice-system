<?php

namespace Database\Seeders;

use App\Constants\Constants;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InvoiceActionsPermissionsSeeder extends Seeder
{
    /**
     * الصلاحية => [الصلاحية المرجعية، الوصف بالإنجليزية، الوصف بالعربية].
     * تُمنح كل صلاحية لكل مجموعة تملك صلاحيتها المرجعية، حتى لا تتغير قدرات المجموعات الحالية.
     */
    private const PERMISSIONS = [
        Constants::SEND_INVOICE     => [Constants::VIEW_INVOICES, 'Send Invoice', 'إرسال الفاتورة'],
        Constants::DOWNLOAD_INVOICE => [Constants::VIEW_INVOICES, 'Download Invoice', 'تحميل الفاتورة'],
        Constants::CREATE_PAYMENT   => [Constants::EDIT_INVOICE, 'Create Payment', 'إنشاء دفعة'],
    ];

    public function run(): void
    {
        $template = DB::table('admin_permissions')->where('title', Constants::EDIT_INVOICE)->first();

        if (!$template) {
            $this->command->error(Constants::EDIT_INVOICE . ' غير موجودة - لم يُنفَّذ أي شيء');

            return;
        }

        DB::transaction(function () use ($template) {
            foreach (self::PERMISSIONS as $title => [$basedOn, $descriptionEn, $descriptionAr]) {
                $permissionId = DB::table('admin_permissions')->where('title', $title)->value('id');

                if (!$permissionId) {
                    $permissionId = DB::table('admin_permissions')->insertGetId([
                        'title'             => $title,
                        'description_en'    => $descriptionEn,
                        'description_ar'    => $descriptionAr,
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
                    ->where('p.title', $basedOn)
                    ->pluck('gp.admin_group_id');

                foreach ($groupIds as $groupId) {
                    DB::table('admin_group_permissions')->updateOrInsert(
                        ['admin_group_id' => $groupId, 'admin_permission_id' => $permissionId],
                        ['created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        });
    }
}
