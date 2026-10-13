<?php

namespace App\Models;

use App\Constants\Constants;
use App\Traits\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invoice extends Model
{
    use HasFactory, BranchScoped;

    protected $fillable = [
        'client_id',
        'user_id',
        'branch_id',
        'invoice_number',
        'invoice_date',
        'due_date',
        'payment_date',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'currency',
        'notes',
        'terms',
        'footer',
        'enable_stripe_checkout',
        'sent_at',
        'due_reminder_sent_at',
        'paid_at',
        'created_by',
        'is_active',
    ];

    protected $hidden = [
        'created_by',
    ];

    protected $casts = [
        'invoice_date'           => 'date',
        'due_date'               => 'date',
        'payment_date'           => 'date',
        'sent_at'                => 'datetime',
        'due_reminder_sent_at'   => 'datetime',
        'paid_at'                => 'datetime',
        'subtotal'               => 'decimal:2',
        'tax_amount'             => 'decimal:2',
        'discount_amount'        => 'decimal:2',
        'total'                  => 'decimal:2',
        'enable_stripe_checkout' => 'boolean',
        'is_active'              => 'boolean',
        'client_id'              => 'integer',
        'user_id'                => 'integer',
        'branch_id'              => 'integer',
        'created_by'             => 'integer',
    ];

    // ================================================================
    // Scopes
    // ================================================================
    public function scopeActive($query)
    {
        return $query->where('is_active', Constants::ACTIVE);
    }

    public function scopeDraft($query)
    {
        return $query->where('status', Constants::INVOICE_STATUS_DRAFT);
    }

    public function scopeSent($query)
    {
        return $query->where('status', Constants::INVOICE_STATUS_SENT);
    }

    public function scopePaid($query)
    {
        return $query->where('status', Constants::INVOICE_STATUS_PAID);
    }

    public function scopeOverdue($query)
    {
        return $query->where(function ($q) {
            $q->where('status', Constants::INVOICE_STATUS_OVERDUE)
                ->orWhere(function ($sub) {
                    $sub->where('status', Constants::INVOICE_STATUS_SENT)
                        ->where('due_date', '<', now());
                });
        });
    }

    public function scopeSearch($query, string $search)
    {
        $search = substr(trim($search), 0, 100);

        return $query->where(function ($q) use ($search) {
            $q->where('invoice_number', 'like', "%{$search}%")
                ->orWhereHas('client', function ($client) use ($search) {
                    $client->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
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
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment()
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function installmentPlan()
    {
        return $this->hasOne(InstallmentPlan::class)->where('status', 'active');
    }

    public function installmentPlans()
    {
        return $this->hasMany(InstallmentPlan::class);
    }

    public function paymentLinks()
    {
        return $this->hasMany(PaymentLink::class)->orderBy('created_at', 'desc');
    }

    // ✅ جديد: علاقة الفرع
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // ================================================================
    // Methods
    // ================================================================
    public function generateInvoiceNumber(): string
    {
        $year   = date('Y');
        $month  = date('m');
        $prefix = "INV-{$year}{$month}-";

        $lastInvoice = self::where('invoice_number', 'like', $prefix . '%')
            ->orderBy('invoice_number', 'desc')
            ->lockForUpdate()
            ->first();

        $nextNumber = $lastInvoice
            ? ((int) substr($lastInvoice->invoice_number, strlen($prefix))) + 1
            : 1001;

        return $prefix . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    public function markAsSent(): void
    {
        $this->update([
            'status'  => Constants::INVOICE_STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function markAsPaid(): void
    {
        $this->update([
            'status'       => Constants::INVOICE_STATUS_PAID,
            'paid_at'      => now(),
            'payment_date' => now()->format('Y-m-d'),
        ]);
    }

    public function isOverdue(): bool
    {
        return $this->status === Constants::INVOICE_STATUS_OVERDUE
            || ($this->status === Constants::INVOICE_STATUS_SENT && $this->due_date < now());
    }

    public function canBePaid(): bool
    {
        return $this->status !== Constants::INVOICE_STATUS_PAID
            && $this->total > 0;
    }

    public function isPaid(): bool
    {
        return $this->status === Constants::INVOICE_STATUS_PAID;
    }

    public function calculateTotals(): float
    {
        $subtotal       = $this->items()->sum('total');
        $taxAmount      = $this->tax_amount ?? 0;
        $discountAmount = $this->discount_amount ?? 0;
        $total          = $subtotal + $taxAmount - $discountAmount;

        $this->update(['subtotal' => $subtotal, 'total' => $total]);

        return $total;
    }

    public function getStripeCheckoutStatusAttribute(): string
    {
        if (!$this->enable_stripe_checkout) {
            return 'معطل';
        }

        return $this->status === Constants::INVOICE_STATUS_PAID ? 'مدفوع' : 'نشط';
    }
}
