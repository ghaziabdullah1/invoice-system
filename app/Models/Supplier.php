<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'supplier_code', 'name', 'name_en', 'contact_person', 'email', 'phone', 'address', 'city',
        'tax_number', 'payment_terms_days', 'currency', 'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'payment_terms_days' => 'integer',
    ];

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        $term = substr(trim($term), 0, 100);

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('name_en', 'like', "%{$term}%")
                ->orWhere('supplier_code', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('tax_number', 'like', "%{$term}%");
        });
    }
}
