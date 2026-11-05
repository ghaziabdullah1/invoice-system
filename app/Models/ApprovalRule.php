<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRule extends Model
{
    protected $fillable = [
        'name', 'requester_department_id', 'min_amount', 'max_amount', 'currency', 'step',
        'approver_type', 'approver_department_id', 'approver_user_id',
        'approver_email', 'approver_name', 'approver_title', 'sla_hours', 'is_active',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_active'  => 'boolean',
        'sla_hours'  => 'integer',
        'step'       => 'integer',
    ];

    public function requesterDepartment()
    {
        return $this->belongsTo(Department::class, 'requester_department_id');
    }

    public function approverDepartment()
    {
        return $this->belongsTo(Department::class, 'approver_department_id');
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * قاعدة تنطبق على المبلغ والقسم المحددين.
     * القسم null = ينطبق على كل الأقسام.
     */
    public function scopeForContext($query, ?int $departmentId, float $amount)
    {
        return $query->where('min_amount', '<=', $amount)
            ->where(function ($q) use ($amount) {
                $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
            })
            ->where(function ($q) use ($departmentId) {
                $q->whereNull('requester_department_id')
                  ->when($departmentId, fn ($qq) => $qq->orWhere('requester_department_id', $departmentId));
            });
    }
}
