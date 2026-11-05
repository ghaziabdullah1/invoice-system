<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'name_en', 'type', 'address', 'city', 'phone', 'email',
        'notes', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function floors()
    {
        return $this->hasMany(Floor::class);
    }

    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('name_en', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%")
                ->orWhere('address', 'like', "%{$term}%");
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
