<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'unit_id', 'client_id', 'name', 'email', 'phone', 'id_number', 'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // حساب الفوترة: عميل في نظام الفواتير
    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
