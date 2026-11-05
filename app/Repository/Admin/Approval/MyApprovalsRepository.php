<?php

namespace App\Repository\Admin\Approval;

use App\Models\PurchaseOrderApproval;
use App\Traits\ProcurementRepositoryTrait;

class MyApprovalsRepository
{
    use ProcurementRepositoryTrait;

    public function index($request)
    {
        try {
            $perPage = min(max((int) $request->input('per_page', 20), 5), 100);

            $page = $this->mine(PurchaseOrderApproval::query(), (int) auth()->id())
                ->with([
                    'purchaseOrder:id,po_number,supplier_id,branch_id,total,currency,order_date,status,requester_id',
                    'purchaseOrder.supplier:id,name,name_en',
                    'purchaseOrder.branch:id,name,name_en',
                    'purchaseOrder.requester:id,name',
                ])
                ->orderBy('deadline_at')
                ->paginate($perPage);

            return $this->ok(['items' => $page->items(), 'pagination' => $this->pagination($page)]);
        } catch (\Throwable $e) {
            return $this->failure($e, 'approvals.index');
        }
    }

    public function count(): int
    {
        return $this->mine(PurchaseOrderApproval::query(), (int) auth()->id())->count();
    }

    // المعلّق على الموظف، أو المُصعَّد إليه، أو المعيَّن أصلاً بعد التصعيد
    private function mine($query, int $userId)
    {
        return $query->where(function ($q) use ($userId) {
            $q->where(fn ($p) => $p
                    ->where('status', PurchaseOrderApproval::STATUS_PENDING)
                    ->where('approver_id', $userId))
                ->orWhere(fn ($e) => $e
                    ->where('status', PurchaseOrderApproval::STATUS_ESCALATED)
                    ->where(fn ($w) => $w->where('approver_id', $userId)->orWhere('escalated_to_id', $userId)));
        });
    }
}
