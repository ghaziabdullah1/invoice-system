<?php

namespace App\Models;

use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes, BranchScoped;

    protected $fillable = [
        'po_number', 'branch_id', 'supplier_id', 'status', 'order_date', 'expected_date', 'currency',
        'payment_terms_days', 'subtotal', 'tax_amount', 'discount_amount', 'total', 'notes',
        'submitted_at', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason',
        'sent_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'created_by',
        'requester_id', 'requester_department_id', 'beneficiary_department_id', 'procurement_officer_id',
        'current_step', 'approval_chain_set_at',
    ];

    protected $casts = [
        'order_date'      => 'date:Y-m-d',
        'expected_date'   => 'date:Y-m-d',
        'submitted_at'    => 'datetime',
        'approved_at'     => 'datetime',
        'rejected_at'     => 'datetime',
        'sent_at'         => 'datetime',
        'cancelled_at'    => 'datetime',
        'approval_chain_set_at' => 'datetime',
        'current_step' => 'integer',
        'subtotal'        => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total'           => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('sort_order');
    }

    public function receipts()
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function attachments()
    {
        return $this->hasMany(PurchaseOrderAttachment::class)->orderBy('id');
    }

    public function signatures()
    {
        return $this->hasMany(PurchaseOrderSignature::class)->orderByDesc('id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function requesterDepartment()
    {
        return $this->belongsTo(Department::class, 'requester_department_id');
    }

    public function beneficiaryDepartment()
    {
        return $this->belongsTo(Department::class, 'beneficiary_department_id');
    }

    public function procurementOfficer()
    {
        return $this->belongsTo(User::class, 'procurement_officer_id');
    }

    public function approvals()
    {
        return $this->hasMany(PurchaseOrderApproval::class)->orderBy('step');
    }

    public function currentApproval()
    {
        return $this->hasOne(PurchaseOrderApproval::class)->whereIn('status', [PurchaseOrderApproval::STATUS_PENDING, PurchaseOrderApproval::STATUS_ESCALATED]);
    }

    /**
     * هل هذا المستخدم هو الموافق الحالي على هذا الطلب؟
     */
    public function isCurrentApproverFor(?int $userId): bool
    {
        if (!$userId || !$this->currentApproval) return false;
        $approval = $this->currentApproval;

        return (int) $approval->approver_id === $userId
            || (int) $approval->escalated_to_id === $userId;
    }
}
