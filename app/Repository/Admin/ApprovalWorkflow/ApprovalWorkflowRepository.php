<?php

namespace App\Repository\Admin\ApprovalWorkflow;

use App\Constants\Constants;
use App\Models\ActivityLog;
use App\Models\ApprovalRule;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApprovalWorkflowRepository
{
    public function index($request)
    {
        try {
            $rules = ApprovalRule::active()
                ->orderBy('currency')
                ->orderBy('min_amount')
                ->orderBy('step')
                ->get();

            $workflows = [];
            foreach ($rules as $rule) {
                $key = $this->contextKey($rule);
                if (!isset($workflows[$key])) {
                    $workflows[$key] = [
                        'key' => $key,
                        'currency' => $rule->currency,
                        'min_amount' => (float) $rule->min_amount,
                        'max_amount' => $rule->max_amount ? (float) $rule->max_amount : null,
                        'requester_department_id' => $rule->requester_department_id,
                        'requester_department_name' => $rule->requester_department_id
                            ? Department::find($rule->requester_department_id)?->name
                            : null,
                        'steps' => [],
                    ];
                }
                $workflows[$key]['steps'][] = $this->formatStep($rule);
            }

            return ['status' => true, 'data' => array_values($workflows)];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => Constants::RESPONSE_SERVER_ERROR];
        }
    }

    public function show(string $key)
    {
        try {
            $parts = $this->parseKey($key);
            if (!$parts) {
                return ['status' => false, 'message' => __('procurement.workflow_not_found'), 'code' => Constants::RESPONSE_NOT_FOUND];
            }

            $rules = $this->queryForContext($parts)->orderBy('step')->get();

            if ($rules->isEmpty()) {
                return ['status' => false, 'message' => __('procurement.workflow_not_found'), 'code' => Constants::RESPONSE_NOT_FOUND];
            }

            return ['status' => true, 'data' => [
                'key' => $key,
                'currency' => $parts['currency'],
                'min_amount' => $parts['min_amount'],
                'max_amount' => $parts['max_amount'],
                'requester_department_id' => $parts['requester_department_id'],
                'requester_department_name' => $parts['requester_department_id']
                    ? Department::find($parts['requester_department_id'])?->name
                    : null,
                'steps' => $rules->map(fn ($r) => $this->formatStep($r))->values(),
            ]];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'code' => Constants::RESPONSE_SERVER_ERROR];
        }
    }

    public function save($request)
    {
        $context = $request->input('context', []);
        $steps = $request->input('steps', []);

        $currency = strtoupper(trim((string) ($context['currency'] ?? 'SAR')));
        $minAmount = (float) ($context['min_amount'] ?? 0);
        $maxAmount = $context['max_amount'] !== null && $context['max_amount'] !== '' ? (float) $context['max_amount'] : null;
        $deptId = $context['requester_department_id'] ?? null;
        $deptId = $deptId === '' ? null : ($deptId ? (int) $deptId : null);

        if ($maxAmount !== null && $maxAmount <= $minAmount) {
            return ['status' => false, 'message' => __('procurement.workflow_range_invalid'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
        }

        if (empty($steps)) {
            return ['status' => false, 'message' => __('procurement.workflow_empty'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
        }

        foreach ($steps as $i => $s) {
            if (empty($s['approver_type'])) {
                return ['status' => false, 'message' => __('procurement.workflow_invalid_step') . ' #' . ($i + 1), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
            }
            if ($s['approver_type'] === 'by_email' && empty($s['approver_email'])) {
                return ['status' => false, 'message' => __('procurement.workflow_email_required'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
            }
            if ($s['approver_type'] === 'specific_dept_manager' && empty($s['approver_department_id'])) {
                return ['status' => false, 'message' => __('procurement.workflow_dept_required'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
            }
            if ($s['approver_type'] === 'specific_user' && empty($s['approver_user_id'])) {
                return ['status' => false, 'message' => __('procurement.workflow_user_required'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
            }
        }

        DB::beginTransaction();

        try {
            $ctx = [
                'currency' => $currency,
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
                'requester_department_id' => $deptId,
            ];

            $original = $this->parseKey((string) $request->input('original_key', ''));

            if ($this->overlaps($ctx, $original)) {
                DB::rollBack();

                return ['status' => false, 'message' => __('procurement.workflow_overlap'), 'code' => Constants::RESPONSE_VALIDATION_ERROR];
            }

            if ($original) {
                $this->queryForContext($original)->delete();
            }

            $this->queryForContext($ctx)->delete();

            foreach ($steps as $i => $s) {
                ApprovalRule::create([
                    'name' => $currency . ' step ' . ($i + 1) . ($deptId ? ' (dept ' . $deptId . ')' : ''),
                    'currency' => $currency,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount,
                    'requester_department_id' => $deptId,
                    'step' => $i + 1,
                    'approver_type' => $s['approver_type'],
                    'approver_department_id' => $s['approver_department_id'] ?? null,
                    'approver_user_id' => $s['approver_user_id'] ?? null,
                    'approver_email' => $s['approver_email'] ?? null,
                    'approver_name' => $s['approver_name'] ?? null,
                    'approver_title' => $s['approver_title'] ?? null,
                    'sla_hours' => isset($s['sla_hours']) && $s['sla_hours'] !== '' ? (int) $s['sla_hours'] : 48,
                    'is_active' => true,
                ]);
            }

            ActivityLog::log('UPDATE', __('procurement.workflow_saved') . ': ' . $currency . ' ' . count($steps) . ' steps');

            DB::commit();

            return ['status' => true, 'data' => ['key' => $this->contextKeyFromArray($ctx)]];
        } catch (\Throwable $e) {
            DB::rollBack();
            return ['status' => false, 'message' => $e->getMessage(), 'code' => Constants::RESPONSE_SERVER_ERROR];
        }
    }

    public function destroy(string $key)
    {
        $parts = $this->parseKey($key);
        if (!$parts) {
            return ['status' => false, 'message' => __('procurement.workflow_not_found'), 'code' => Constants::RESPONSE_NOT_FOUND];
        }

        DB::beginTransaction();
        try {
            $this->queryForContext($parts)->delete();
            ActivityLog::log('DELETE', __('procurement.workflow_deleted') . ': ' . $key);
            DB::commit();
            return ['status' => true, 'data' => null];
        } catch (\Throwable $e) {
            DB::rollBack();
            return ['status' => false, 'message' => $e->getMessage(), 'code' => Constants::RESPONSE_SERVER_ERROR];
        }
    }

    // ═══════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════

    private function contextKey(ApprovalRule $rule): string
    {
        return $rule->currency . '|' . $rule->min_amount . '|' . ($rule->max_amount ?? '') . '|' . ($rule->requester_department_id ?? '');
    }

    private function contextKeyFromArray(array $ctx): string
    {
        return $ctx['currency'] . '|' . $ctx['min_amount'] . '|' . ($ctx['max_amount'] ?? '') . '|' . ($ctx['requester_department_id'] ?? '');
    }

    private function parseKey(string $key): ?array
    {
        $parts = explode('|', $key);
        if (count($parts) !== 4) return null;
        return [
            'currency' => $parts[0],
            'min_amount' => (float) $parts[1],
            'max_amount' => $parts[2] === '' ? null : (float) $parts[2],
            'requester_department_id' => $parts[3] === '' ? null : (int) $parts[3],
        ];
    }

    private function queryForContext(array $ctx)
    {
        $q = ApprovalRule::query()
            ->where('currency', $ctx['currency'])
            ->where('min_amount', $ctx['min_amount']);

        if ($ctx['max_amount'] === null) {
            $q->whereNull('max_amount');
        } else {
            $q->where('max_amount', $ctx['max_amount']);
        }

        if ($ctx['requester_department_id'] === null) {
            $q->whereNull('requester_department_id');
        } else {
            $q->where('requester_department_id', $ctx['requester_department_id']);
        }

        return $q;
    }

    // هل يتداخل نطاق المبلغ مع سير آخر لنفس العملة والقسم (باستثناء السير المُستبدل)؟
    private function overlaps(array $ctx, ?array $original): bool
    {
        $norm = fn ($c, $min, $max, $dept) => strtoupper((string) $c) . '|' . (float) $min . '|' . ($max === null ? '' : (float) $max) . '|' . ($dept ?? '');

        $newKey  = $norm($ctx['currency'], $ctx['min_amount'], $ctx['max_amount'], $ctx['requester_department_id']);
        $origKey = $original ? $norm($original['currency'], $original['min_amount'], $original['max_amount'], $original['requester_department_id']) : null;
        $newMax  = $ctx['max_amount'] ?? INF;

        $existing = ApprovalRule::query()
            ->where('currency', $ctx['currency'])
            ->when(
                $ctx['requester_department_id'] === null,
                fn ($q) => $q->whereNull('requester_department_id'),
                fn ($q) => $q->where('requester_department_id', $ctx['requester_department_id'])
            )
            ->get(['currency', 'min_amount', 'max_amount', 'requester_department_id']);

        foreach ($existing as $rule) {
            $key = $norm($rule->currency, $rule->min_amount, $rule->max_amount, $rule->requester_department_id);

            if ($key === $newKey || $key === $origKey) {
                continue;
            }

            $min = (float) $rule->min_amount;
            $max = $rule->max_amount === null ? INF : (float) $rule->max_amount;

            if ($ctx['min_amount'] <= $max && $min <= $newMax) {
                return true;
            }
        }

        return false;
    }

    private function formatStep(ApprovalRule $rule): array
    {
        $display = '—';
        if ($rule->approver_type === 'by_email') {
            $display = trim(($rule->approver_name ?? '') . ' <' . ($rule->approver_email ?? '') . '>');
            if ($rule->approver_title) $display .= ' — ' . $rule->approver_title;
        } elseif ($rule->approver_type === 'specific_user') {
            $u = User::find($rule->approver_user_id);
            $display = $u?->name ?? '—';
        } elseif ($rule->approver_type === 'specific_dept_manager') {
            $d = Department::find($rule->approver_department_id);
            $display = $d ? ('مدير ' . $d->name) : '—';
        } elseif ($rule->approver_type === 'requester_dept_manager') {
            $display = 'مدير القسم المرسل';
        } elseif ($rule->approver_type === 'beneficiary_dept_manager') {
            $display = 'مدير القسم المستفيد';
        } elseif ($rule->approver_type === 'ceo') {
            $display = 'المدير التنفيذي';
        }

        return [
            'id' => $rule->id,
            'step' => $rule->step,
            'approver_type' => $rule->approver_type,
            'approver_department_id' => $rule->approver_department_id,
            'approver_user_id' => $rule->approver_user_id,
            'approver_email' => $rule->approver_email,
            'approver_name' => $rule->approver_name,
            'approver_title' => $rule->approver_title,
            'sla_hours' => $rule->sla_hours ?? 48,
            'display' => $display,
        ];
    }
}
