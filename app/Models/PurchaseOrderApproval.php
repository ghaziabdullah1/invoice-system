<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderApproval extends Model
{
    public const STATUS_WAITING   = 'waiting';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_SKIPPED   = 'skipped';

    protected $fillable = [
        'purchase_order_id', 'step', 'approver_id', 'status',
        'signature_path', 'reason', 'deadline_at', 'sla_hours', 'signed_at',
        'escalated_at', 'escalated_to_id',
    ];

    protected $casts = [
        'deadline_at'  => 'datetime',
        'signed_at'    => 'datetime',
        'escalated_at' => 'datetime',
        'step'         => 'integer',
    ];

    protected $hidden = ['signature_path'];

    protected $appends = ['signature_url'];

    public function getSignatureUrlAttribute(): ?string
    {
        return $this->signature_path ? asset('storage/' . $this->signature_path) : null;
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function escalatedTo()
    {
        return $this->belongsTo(User::class, 'escalated_to_id');
    }
}
