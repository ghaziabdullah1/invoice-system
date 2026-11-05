<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RealtyReportsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $menuId = DB::table('admin_permissions')->where('title', 'view_reports')->value('admin_menu_id');

        if (!$menuId) {
            $this->command?->error('صلاحية view_reports غير موجودة.');
            return;
        }

        $permissions = [
            ['view_report_occupancy', 'View Occupancy Report', 'عرض تقرير الإشغال'],
            ['view_report_rent_roll', 'View Rent Roll Report', 'عرض تقرير كشف الإيجارات'],
            ['view_report_expiring_contracts', 'View Expiring Contracts Report', 'عرض تقرير العقود المنتهية والقريبة من الانتهاء'],
            ['view_report_tenant_arrears', 'View Tenant Arrears Report', 'عرض تقرير متأخرات المستأجرين'],
            ['view_report_collection', 'View Collection vs Due Report', 'عرض تقرير التحصيل مقابل المستحق'],
            ['view_report_vacant_units', 'View Vacant Units Report', 'عرض تقرير الوحدات الشاغرة'],
            ['view_report_property_revenue', 'View Property Revenue Report', 'عرض تقرير إيراد العقارات'],
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
