<?php

namespace App\Models;

use App\Constants\Constants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contract_number',
        'property_id',
        'unit_id',
        'tenant_id',
        'start_date',
        'end_date',
        'rent_amount',
        'currency',
        'payment_frequency',
        'payment_day',
        'security_deposit',
        'grace_days',
        'annual_increase_percent',
        'status',
        'contract_document_path',
        'renewed_from_id',
        'terminated_at',
        'termination_reason',
        'expiry_alert_days',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'start_date'              => 'date',
        'end_date'                => 'date',
        'terminated_at'           => 'datetime',
        'rent_amount'             => 'decimal:2',
        'security_deposit'        => 'decimal:2',
        'annual_increase_percent' => 'decimal:2',
        'payment_day'             => 'integer',
        'grace_days'              => 'integer',
    ];

    // مسار المستند لا يُكشف في الـ API، ويُستبدل بعلامة وجود مستند
    protected $hidden = ['contract_document_path'];

    protected $appends = ['has_document'];

    public function getHasDocumentAttribute(): bool
    {
        return !empty($this->contract_document_path);
    }

    // ================================================================
    // Scopes
    // ================================================================
    public function scopeActive($query)
    {
        return $query->where('status', Constants::CONTRACT_STATUS_ACTIVE);
    }

    /**
     * العقود السارية فعلياً اليوم: حالتها سارية وتاريخ اليوم ضمن مدتها.
     */
    public function scopeInEffect($query)
    {
        $today = today()->format(Constants::DATE_FORMAT);

        return $query->active()
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);
    }

    public function scopeExpiringWithin($query, int $days)
    {
        return $query->active()->whereBetween('end_date', [
            today()->format(Constants::DATE_FORMAT),
            today()->addDays($days)->format(Constants::DATE_FORMAT),
        ]);
    }

    /**
     * العقود السارية على نفس الوحدة التي تتقاطع فترتها مع الفترة المعطاة.
     */
    public function scopeOverlapping($query, int $unitId, $startDate, $endDate, ?int $ignoreId = null)
    {
        return $query->active()
            ->where('unit_id', $unitId)
            ->where('start_date', '<=', $endDate)
            ->where('end_date', '>=', $startDate)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId));
    }

    public function scopeSearch($query, string $search)
    {
        $search = substr(trim($search), 0, 100);

        return $query->where(function ($q) use ($search) {
            $q->where('contract_number', 'like', "%{$search}%")
                ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$search}%"))
                ->orWhereHas('unit', fn ($unit) => $unit->where('name', 'like', "%{$search}%"));
        });
    }

    // ================================================================
    // Relations
    // ================================================================
    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function renewedFrom()
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function renewals()
    {
        return $this->hasMany(self::class, 'renewed_from_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
