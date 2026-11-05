<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class PaymentLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id', 'client_id', 'token', 'amount', 'currency', 'status',
        'expires_at', 'paid_at', 'payment_method', 'stripe_session_id',
        'metadata', 'sent_via', 'sent_at',
    ];

    protected $appends = ['public_url'];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'sent_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($link) {
            if (empty($link->token)) $link->token = self::generateUniqueToken();
        });
    }

    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function client() { return $this->belongsTo(Client::class); }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', now()); });
    }

    public static function generateUniqueToken(): string
    {
        do { $token = Str::random(64); } while (self::where('token', $token)->exists());
        return $token;
    }

    public function getPublicUrlAttribute(): string
    {
        return config('app.frontend_url') . '/pay/' . $this->token;
    }

    public function markAsPaid(string $paymentMethod = 'card'): void
    {
        $this->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => $paymentMethod]);
    }

    public function markAsExpired(): void { $this->update(['status' => 'expired']); }
    public function isExpired(): bool { return $this->expires_at && $this->expires_at->isPast(); }
    public function isActive(): bool { return $this->status === 'active' && !$this->isExpired(); }
}
