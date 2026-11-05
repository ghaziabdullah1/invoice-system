<?php

namespace App\Services\Procurement;

use App\Constants\Constants;
use App\Models\ApprovalRule;
use App\Models\Department;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Collection;

class ApprovalChainService
{
    /**
     * يبني سلسلة الموافقات من سير موافقات واحد كامل (الأخص أولاً).
     * يرمي ApprovalChainException إن لم يوجد سير مطابق أو تعذّر تحديد موافق.
     */
    public function build(PurchaseOrder $po): array
    {
        $requesterId       = $po->requester_id;
        $requesterDeptId   = $po->requester_department_id ?: User::find($requesterId)?->primaryDepartment()?->id;
        $beneficiaryDeptId = $po->beneficiary_department_id ?: $requesterDeptId;
        $currency          = $po->currency ?: Constants::CURRENCY_SAR;

        $rules = $this->selectWorkflow((float) $po->total, $currency, $requesterDeptId);

        if ($rules->isEmpty()) {
            throw new ApprovalChainException(__('procurement.no_approval_rules'));
        }

        $ceoId              = $this->ceoUserId();
        $requesterIsManager = $this->isManagerOf($requesterId, $requesterDeptId);

        $chain = [];
        $seen  = [];
        $step  = 1;

        foreach ($rules as $rule) {
            $approverId = $this->resolveApprover($rule, $requesterDeptId, $beneficiaryDeptId, $requesterIsManager, $ceoId);

            if ($approverId === null) {
                throw new ApprovalChainException(__('procurement.approval_step_unresolved', [
                    'step' => $rule->step,
                    'type' => __('procurement.approver_labels.' . $rule->approver_type),
                ]));
            }

            // فصل المهام: الطالب لا يوافق على طلبه، ولا يتكرر الموافق في السلسلة
            if ($approverId === $requesterId || in_array($approverId, $seen, true)) {
                continue;
            }

            $seen[] = $approverId;

            $chain[] = [
                'step'        => $step++,
                'approver_id' => $approverId,
                'rule_id'     => $rule->id,
                'rule_type'   => $rule->approver_type,
                'sla_hours'   => $rule->sla_hours ?: 48,
            ];
        }

        if (empty($chain)) {
            throw new ApprovalChainException(__('procurement.approval_chain_empty'));
        }

        return $chain;
    }

    /**
     * قواعد سير واحد فقط: المطابق للمبلغ والعملة والقسم، والأخص يفوز
     * (قسم محدد، ثم عملة محددة، ثم أضيق نطاق مبلغ، ثم أعلى حد أدنى).
     */
    private function selectWorkflow(float $amount, string $currency, ?int $deptId): Collection
    {
        $candidates = ApprovalRule::query()
            ->active()
            ->where('min_amount', '<=', $amount)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', $currency))
            ->where(function ($q) use ($deptId) {
                $q->whereNull('requester_department_id')
                    ->when($deptId, fn ($qq) => $qq->orWhere('requester_department_id', $deptId));
            })
            ->orderBy('step')
            ->get();

        $groups = $candidates->groupBy(fn ($r) => implode('|', [
            $r->currency ?? '',
            (float) $r->min_amount,
            $r->max_amount === null ? '' : (float) $r->max_amount,
            $r->requester_department_id ?? '',
        ]));

        $best = $groups->sortBy(function ($group) {
            $r = $group->first();

            return [
                $r->requester_department_id === null ? 1 : 0,
                $r->currency === null ? 1 : 0,
                $r->max_amount === null ? PHP_INT_MAX : (float) $r->max_amount - (float) $r->min_amount,
                -(float) $r->min_amount,
            ];
        })->first();

        return $best ? $best->sortBy('step')->values() : collect();
    }

    private function resolveApprover(
        ApprovalRule $rule,
        ?int $requesterDeptId,
        ?int $beneficiaryDeptId,
        bool $requesterIsManager,
        ?int $ceoId
    ): ?int {
        return match ($rule->approver_type) {
            'requester_dept_manager'   => $requesterIsManager ? $ceoId : $this->deptManager($requesterDeptId),
            'beneficiary_dept_manager' => $this->deptManager($beneficiaryDeptId),
            'specific_dept_manager'    => $this->deptManager($rule->approver_department_id),
            'specific_user'            => $rule->approver_user_id,
            'by_email'                 => $rule->approver_user_id
                ?? ($rule->approver_email ? User::where('email', $rule->approver_email)->value('id') : null),
            'ceo'                      => $ceoId,
            default                    => null,
        };
    }

    private function isManagerOf(?int $userId, ?int $deptId): bool
    {
        if (!$userId || !$deptId) {
            return false;
        }

        return Department::where('id', $deptId)->where('manager_id', $userId)->exists();
    }

    private function deptManager(?int $departmentId): ?int
    {
        if (!$departmentId) {
            return null;
        }

        return Department::find($departmentId)?->manager_id;
    }

    private function ceoUserId(): ?int
    {
        return User::where('admin_group_id', Constants::SUPER_ADMIN_GROUP_ID)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id');
    }
}
