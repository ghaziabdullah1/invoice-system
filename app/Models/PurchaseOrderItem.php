<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'sort_order', 'item_code', 'description', 'unit',
        'quantity', 'unit_price', 'tax_rate', 'total', 'received_quantity',
    ];

    protected $casts = [
        'quantity'          => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'unit_price'        => 'decimal:2',
        'tax_rate'          => 'decimal:2',
        'total'             => 'decimal:2',
    ];

    protected $appends = ['remaining_quantity'];

    public function getRemainingQuantityAttribute(): float
    {
        return round((float) $this->quantity - (float) $this->received_quantity, 3);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
