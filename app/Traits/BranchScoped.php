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

    /**
     * هل هذا السجل ضمن الفروع المسموح بها؟ (null = بلا تقييد)
     */
    public function isInAllowedBranches(?array $branchIds): bool
    {
        return !is_array($branchIds) || in_array($this->branch_id, $branchIds, true);
    }
}
