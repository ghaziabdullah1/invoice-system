<?php

namespace App\Traits;

trait BranchScoped
{
    /**
     * تقييد الاستعلام بالفروع المسموح بها للمستخدم الحالي.
     * القيمة null تعني بلا تقييد (السوبر أدمن، أو سياق بلا مستخدم كالأوامر المجدولة).
     */
    public function scopeInAllowedBranches($query, ?array $branchIds)
    {
        return $query->when(
            is_array($branchIds),
            fn ($q) => $q->whereIn($this->qualifyColumn('branch_id'), $branchIds)
        );
    }
}
