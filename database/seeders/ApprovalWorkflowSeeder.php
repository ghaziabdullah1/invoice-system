<?php

namespace Database\Seeders;

use App\Constants\Constants;
use App\Models\AdminGroup;
use App\Models\ApprovalRule;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Database\Seeder;

class ApprovalWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. إضافة الصلاحيات
            $this->call(ProcurementPermissionsSeeder::class);

            // 2. الأقسام الافتراضية
            $departments = $this->createDepartments();

            // 3. قواعد الموافقات (Progressive: 4 مستويات)
            $this->createRules($departments);

            // 4. ربط السوبر أدمن بكل الأقسام
            $this->linkSuperAdmin($departments);
        });
    }

    private function addPermissions(): void
    {
        // بنية جدول permissions الفعلية: id, name, description, created_at, updated_at
        $permissions = [
            ["name" => "view_departments",      "description" => "عرض الأقسام"],
            ["name" => "manage_departments",    "description" => "إدارة الأقسام"],
            ["name" => "view_approval_rules",   "description" => "عرض قواعد الموافقات"],
            ["name" => "manage_approval_rules", "description" => "إدارة قواعد الموافقات"],
            ["name" => "view_my_approvals",     "description" => "عرض موافقاتي"],
        ];

        foreach ($permissions as $perm) {
            $exists = DB::table("permissions")->where("name", $perm["name"])->exists();
            if (!$exists) {
                DB::table("permissions")->insert([
                    "name"        => $perm["name"],
                    "description" => $perm["description"],
                    "created_at"  => now(),
                    "updated_at"  => now(),
                ]);
            }
        }
    }

    private function createDepartments(): array
    {
        $superAdmin = User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)->first();

        $departments = [
            ['code' => 'GEN', 'name' => 'الإدارة العامة', 'name_en' => 'General', 'manager_id' => $superAdmin?->id],
            ['code' => 'PROC', 'name' => 'المشتريات', 'name_en' => 'Procurement', 'manager_id' => $superAdmin?->id],
            ['code' => 'FIN', 'name' => 'المالية', 'name_en' => 'Finance', 'manager_id' => $superAdmin?->id],
            ['code' => 'IT', 'name' => 'تقنية المعلومات', 'name_en' => 'IT', 'manager_id' => $superAdmin?->id],
        ];

        $created = [];
        foreach ($departments as $dept) {
            $created[$dept['code']] = Department::firstOrCreate(['code' => $dept['code']], $dept);
        }

        return $created;
    }

    

    private function createRules(array $departments): void
    {
        // لا نعيد الزرع إذا وُجدت قواعد: حماية لما أُنشئ من شاشة سير الموافقات
        if (\App\Models\ApprovalRule::query()->exists()) {
            return;
        }

        $procId = $departments['PROC']->id;
        $finId  = $departments['FIN']->id;

        $sets = [
            ['currency' => 'SAR',  'min' => 0,       'max' => 5000,      'withCeo' => false],
            ['currency' => 'SAR',  'min' => 5000.01, 'max' => null,      'withCeo' => true],
            ['currency' => 'KWD',  'min' => 0,       'max' => 1000,      'withCeo' => false],
            ['currency' => 'KWD',  'min' => 1000.01, 'max' => null,      'withCeo' => true],
        ];

        foreach ($sets as $set) {
            $base = [
                'currency'                 => $set['currency'],
                'min_amount'               => $set['min'],
                'max_amount'               => $set['max'],
                'requester_department_id'  => null,
                'is_active'                => true,
            ];

            $steps = [
                ['step' => 1, 'type' => 'requester_dept_manager',   'extra' => []],
                ['step' => 2, 'type' => 'beneficiary_dept_manager', 'extra' => []],
                ['step' => 3, 'type' => 'specific_dept_manager',    'extra' => ['approver_department_id' => $finId]],
            ];

            if ($set['withCeo']) {
                $steps[] = ['step' => 4, 'type' => 'ceo', 'extra' => []];
                $steps[] = ['step' => 5, 'type' => 'specific_dept_manager', 'extra' => ['approver_department_id' => $procId]];
            } else {
                $steps[] = ['step' => 4, 'type' => 'specific_dept_manager', 'extra' => ['approver_department_id' => $procId]];
            }

            foreach ($steps as $s) {
                \App\Models\ApprovalRule::create(array_merge($base, [
                    'name'          => $set['currency'] . ' ' . $s['step'] . ' — ' . $s['type'],
                    'step'          => $s['step'],
                    'approver_type' => $s['type'],
                ], $s['extra']));
            }
        }
    }

    private function linkSuperAdmin(array $departments): void
    {
        $superAdmin = User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)->first();
        if (!$superAdmin) return;

        foreach ($departments as $dept) {
            $superAdmin->departments()->syncWithoutDetaching([
                $dept->id => ['is_primary' => $dept->code === 'GEN'],
            ]);
        }
    }
}
