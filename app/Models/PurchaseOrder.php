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
    ];

    protected $casts = [
        'order_date'      => 'date',
        'expected_date'   => 'date',
        'submitted_at'    => 'datetime',
        'approved_at'     => 'datetime',
        'rejected_at'     => 'datetime',
        'sent_at'         => 'datetime',
        'cancelled_at'    => 'datetime',
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
}
