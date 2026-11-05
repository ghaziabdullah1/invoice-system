<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderSignature extends Model
{
    protected $fillable = [
        'purchase_order_id', 'user_id', 'signature_path', 'action', 'reason', 'signed_at',
    ];

    protected $hidden = ['signature_path'];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    protected $appends = ['signature_url'];

    public function getSignatureUrlAttribute(): ?string
    {
        return $this->signature_path ? asset('storage/' . $this->signature_path) : null;
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
