<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProcurementPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('admin_menus')->updateOrInsert(
            ['title_en' => 'Procurement'],
            [
                'title_ar'   => 'المشتريات والتوريد',
                'link'       => null,
                'icon_class' => 'fa-truck',
                'sort_order' => 8,
                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $menuId = DB::table('admin_menus')->where('title_en', 'Procurement')->value('id');

        $permissions = [
            ['view_suppliers', 'View Suppliers', 'عرض الموردين'],
            ['create_supplier', 'Create Supplier', 'إنشاء مورد'],
            ['edit_supplier', 'Edit Supplier', 'تعديل مورد'],
            ['delete_supplier', 'Delete Supplier', 'حذف مورد'],
            ['view_purchase_orders', 'View Purchase Orders', 'عرض أوامر الشراء'],
            ['create_purchase_order', 'Create Purchase Order', 'إنشاء أمر شراء'],
            ['edit_purchase_order', 'Edit Purchase Order', 'تعديل أمر شراء'],
            ['delete_purchase_order', 'Delete Purchase Order', 'حذف أمر شراء'],
            ['approve_purchase_order', 'Approve / Reject Purchase Order', 'اعتماد أو رفض أمر شراء'],
            ['send_purchase_order', 'Send Purchase Order to Supplier', 'إرسال أمر الشراء للمورد'],
            ['receive_purchase_order', 'Receive Goods', 'استلام البضاعة'],
            ['cancel_purchase_order', 'Cancel Purchase Order', 'إلغاء أمر شراء'],
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
