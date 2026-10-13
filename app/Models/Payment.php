<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    /**
     * ✅ $fillable محدد — يمنع Mass Assignment
     */
    protected $fillable = [
        'invoice_id',
        'client_id',
        'user_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'payment_gateway',
        'stripe_payment_intent_id',
        'stripe_checkout_session_id',
        'stripe_customer_id',
        'metadata',
        'failure_reason',
        'paid_at',
    ];

    /**
     * ✅ $hidden — إخفاء بيانات Stripe الحساسة من الاستجابات العامة
     */
    protected $hidden = [
        'stripe_customer_id',       // لا يُكشف للعملاء
        'metadata',                 // قد يحتوي بيانات داخلية
    ];

    /**
     * ✅ Casts صحيحة
     */
    protected $casts = [
        'amount'     => 'decimal:2',
        'metadata'   => 'array',
        'paid_at'    => 'datetime',
        'invoice_id' => 'integer',
        'client_id'  => 'integer',
        'user_id'    => 'integer',
    ];

    // ================================================================
    // Scopes
    // ================================================================

    /**
     * تقييد المدفوعات بالفروع المسموحة عبر فاتورتها (لا يوجد branch_id في المدفوعات).
     */
    public function scopeInAllowedBranches($query, ?array $branchIds)
    {
        return $query->when(
            is_array($branchIds),
            fn ($q) => $q->whereHas('invoice', fn ($invoice) => $invoice->inAllowedBranches($branchIds))
        );
    }

    public function scopePending($query)
    {
        return $query->where('status', Constants::PAYMENT_STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', Constants::PAYMENT_STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', Constants::PAYMENT_STATUS_FAILED);
    }

    public function scopeSearch($query, string $search)
    {
        $search = substr(trim($search), 0, 100);

        return $query->where(function ($q) use ($search) {
            $q->where('stripe_payment_intent_id', 'like', "%{$search}%")
                ->orWhereHas('invoice', function ($invoice) use ($search) {
                    $invoice->where('invoice_number', 'like', "%{$search}%");
                })
                ->orWhereHas('client', function ($client) use ($search) {
                    $client->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
        });
    }

    // ================================================================
    // Relations
    // ================================================================

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ================================================================
    // Methods
    // ================================================================

    public function markAsCompleted(string $paymentMethod = 'card'): static
    {
        $this->update([
            'status'         => Constants::PAYMENT_STATUS_COMPLETED,
            'payment_method' => $paymentMethod,
            'paid_at'        => now(),
        ]);

        if ($this->invoice) {
            $this->invoice->update([
                'status'  => Constants::INVOICE_STATUS_PAID,
                'paid_at' => now(),
            ]);
        }

        return $this;
    }

    public function markAsFailed(?string $reason = null): static
    {
        $this->update([
            'status'         => Constants::PAYMENT_STATUS_FAILED,
            'failure_reason' => $reason,
        ]);

        return $this;
    }

    public function isSuccessful(): bool
    {
        return $this->status === Constants::PAYMENT_STATUS_COMPLETED;
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format((float) $this->amount, 2) . ' ' . $this->currency;
    }
}
