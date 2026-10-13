<?php

namespace App\Models;

use App\Constants\Constants;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Client extends Model
{
    use HasFactory, BranchScoped;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'company_name',
        'tax_number',
        'payment_terms',
        'credit_limit',
        'currency',
        'notes',
        'is_active',
        'created_by',
        'branch_id',
    ];

    protected $hidden = [
        'created_by',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'created_by'   => 'integer',
        'credit_limit' => 'decimal:2',
        'branch_id'    => 'integer',
    ];

    // ================================================================
    // Scopes
    // ================================================================
    public function scopeActive($query)
    {
        return $query->where('is_active', Constants::ACTIVE);
    }

    public function scopeSearch($query, string $search)
    {
        $search = substr(trim($search), 0, 100);

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    // ✅ جديد: فلترة حسب الفرع
    public function scopeByBranch($query, ?int $branchId)
    {
        if ($branchId) {
            return $query->where('branch_id', $branchId);
        }
        return $query;
    }

    // ================================================================
    // Relations
    // ================================================================
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function paidInvoices()
    {
        return $this->hasMany(Invoice::class)
            ->where('status', Constants::INVOICE_STATUS_PAID);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    // ✅ جديد: علاقة الفرع
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // ================================================================
    // Methods
    // ================================================================
    public function totalInvoiced(): float
    {
        return (float) $this->invoices()->sum('total');
    }

    public function totalPaid(): float
    {
        return (float) $this->paidInvoices()->sum('total');
    }

    public function totalDue(): float
    {
        return $this->totalInvoiced() - $this->totalPaid();
    }

    public function remainingCredit(): ?float
    {
        if ($this->credit_limit === null) {
            return null;
        }

        return (float) $this->credit_limit - $this->totalDue();
    }

    public function exceedsCreditLimit(float $additionalAmount = 0): bool
    {
        if ($this->credit_limit === null) {
            return false;
        }

        return ($this->totalDue() + $additionalAmount) > (float) $this->credit_limit;
    }
}
