<?php

namespace App\Models;

use App\Constants\Constants;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'img',
        'password',
        'phone',
        'address',
        'company_name',
        'tax_number',
        'is_active',
        'admin_group_id',
        'email_verified_at',
        'remember_token',
        'otp',
        'otp_via',
        'otp_created_at',
        'otp_attempts',
        'otp_verified_at',
        'last_login_ip',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'otp',
        'otp_via',
        'otp_created_at',
        'otp_attempts',
        'otp_verified_at',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active'         => 'boolean',
        'otp_created_at'    => 'datetime',
        'otp_verified_at'   => 'datetime',
        'last_login_at'     => 'datetime',
    ];

    protected $appends = ['img_url'];

    public function getImgUrlAttribute()
    {
        return $this->img ? asset('storage/' . $this->img) : null;
    }

    // ── Scopes ───────────────────────────────────────────────
    public function scopeActive($query)
    {
        return $query->where('is_active', Constants::ACTIVE);
    }

    public function scopeClients($query)
    {
        return $query->where('admin_group_id', Constants::CLIENT_GROUP_ID);
    }

    public function scopeStaff($query)
    {
        return $query->where('admin_group_id', '!=', Constants::CLIENT_GROUP_ID);
    }

    // ── Relations ────────────────────────────────────────────
    public function adminGroup()
    {
        return $this->belongsTo(AdminGroup::class, 'admin_group_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'user_id');
    }

    public function createdInvoices()
    {
        return $this->hasMany(Invoice::class, 'created_by');
    }

    public function activities()
    {
        return $this->hasMany(ActivityLog::class, 'user_id');
    }

    // ✅ جديد: علاقة المستخدم بالفروع (Many-to-Many)
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'user_branches')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    // ✅ جديد: الفرع الافتراضي للمستخدم
    public function defaultBranch()
    {
        return $this->belongsToMany(Branch::class, 'user_branches')
            ->wherePivot('is_default', true)
            ->withPivot('is_default')
            ->withTimestamps();
    }

    // ── Properties (Many-to-Many) ────────────────────────────
    public function properties()
    {
        return $this->belongsToMany(Property::class, 'user_properties')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function hasPropertyAccess(int $propertyId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->properties()->where('properties.id', $propertyId)->exists();
    }

    // ── Methods ──────────────────────────────────────────────
    public function isSuperAdmin(): bool
    {
        return $this->admin_group_id === Constants::SUPER_ADMIN_GROUP_ID;
    }

    public function isAdmin(): bool
    {
        return $this->admin_group_id === Constants::ADMIN_GROUP_ID;
    }

    public function isClient(): bool
    {
        return $this->admin_group_id === Constants::CLIENT_GROUP_ID;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $adminGroup = $this->adminGroup;
        if (!$adminGroup) {
            return false;
        }

        return $adminGroup->permissions()
            ->where('title', $permission)
            ->where('is_active', true)
            ->exists();
    }

    // ✅ جديد: هل لدى المستخدم صلاحية لهذا الفرع؟
    public function hasBranchAccess(int $branchId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->branches()->where('branch_id', $branchId)->exists();
    }
}
