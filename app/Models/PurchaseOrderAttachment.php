<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderAttachment extends Model
{
    protected $fillable = [
        'purchase_order_id', 'path', 'original_name', 'mime_type', 'size', 'uploaded_by',
    ];

    protected $hidden = ['path'];

    protected $appends = ['url', 'is_pdf'];

    public function getUrlAttribute(): ?string
    {
        return $this->path ? asset('storage/' . $this->path) : null;
    }

    public function getIsPdfAttribute(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
