<?php

namespace App\Repository\Admin\Contract;

use App\Constants\Constants;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Tenant;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ContractRepository implements ContractInterface
{
    private const DOCUMENT_DISK = 'local';

    private const STATUSES = [
        Constants::CONTRACT_STATUS_DRAFT,
        Constants::CONTRACT_STATUS_ACTIVE,
        Constants::CONTRACT_STATUS_EXPIRED,
        Constants::CONTRACT_STATUS_TERMINATED,
        Constants::CONTRACT_STATUS_RENEWED,
    ];

    // حقول لا تقبل null في الجدول: تُحدَّث فقط إذا أُرسلت بقيمة
    private const REQUIRED_FIELDS = ['rent_amount', 'payment_frequency', 'payment_day', 'security_deposit', 'grace_days'];

    // حقول تقبل null: تُحدَّث إذا أُرسلت (حتى لو فارغة)
    private const NULLABLE_FIELDS = ['annual_increase_percent', 'notes', 'currency'];

    // ================================================================
    // القراءة
    // ================================================================
    public function index($request)
    {
        try {
            $query = Contract::with($this->relations())->withCount('renewals')->orderByDesc('created_at');
            $this->limitToAccessible($query);

            // العقار المختار في الشريط العلوي، و all=1 يعرض كل العقارات المسموحة
            $selected = $request->attributes->get('selected_property_id');
            if ($selected && !$request->boolean('all')) {
                $query->where('property_id', (int) $selected);
            }

            if ($request->filled('status') && in_array($request->status, self::STATUSES, true)) {
                $query->where('status', $request->status);
            }

            if ($request->filled('unit_id')) {
                $query->where('unit_id', (int) $request->unit_id);
            }

            if ($request->filled('tenant_id')) {
                $query->where('tenant_id', (int) $request->tenant_id);
            }

            if ($request->filled('expiring_within')) {
                $query->expiringWithin(max(1, min((int) $request->expiring_within, 365)));
            }

            if ($request->filled('search')) {
                $query->search(substr(trim($request->search), 0, 100));
            }

            $perPage = (int) ($request->per_page ?? Constants::DEFAULT_PER_PAGE);
            $perPage = max(Constants::MIN_PER_PAGE, min($perPage, Constants::MAX_PER_PAGE));

            return ['status' => true, 'message' => __('contracts.fetched'), 'data' => $query->paginate($perPage)];
        } catch (\Exception $e) {
            Log::error('ContractRepository index error', ['error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    public function show($id)
    {
        try {
            if (!is_numeric($id) || (int) $id <= 0) {
                return $this->fail(__('contracts.not_found'), Constants::RESPONSE_NOT_FOUND);
            }

            $query = Contract::with(array_merge($this->relations(), [
                'creator:id,name',
                'renewedFrom:id,contract_number,start_date,end_date,status',
                'renewals:id,renewed_from_id,contract_number,start_date,end_date,status',
            ]));
            $this->limitToAccessible($query);

            $contract = $query->find((int) $id);

            if (!$contract) {
                return $this->fail(__('contracts.not_found'), Constants::RESPONSE_NOT_FOUND);
            }

            return ['status' => true, 'message' => __('contracts.fetched_one'), 'data' => $contract];
        } catch (\Exception $e) {
            Log::error('ContractRepository show error', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    // ================================================================
    // الإنشاء والتعديل والحذف
    // ================================================================
    public function store($request)
    {
        DB::beginTransaction();

        try {
            $unit = Unit::with('floor:id,property_id')->find((int) $request->unit_id);

            if (!$unit || !$unit->floor) {
                DB::rollBack();
                return $this->fail(__('messages.unit_not_found'), Constants::RESPONSE_NOT_FOUND);
            }

            // العقار يُشتق من الوحدة ولا يُقبل من الطلب
            $propertyId = (int) $unit->floor->property_id;

            if (!$this->canAccessProperty($propertyId)) {
                DB::rollBack();
                return $this->fail(__('contracts.property_forbidden'), Constants::RESPONSE_FORBIDDEN);
            }

            if (!$unit->is_active) {
                DB::rollBack();
                return $this->fail(__('contracts.unit_inactive'));
            }

            $tenant = Tenant::find((int) $request->tenant_id);

            if (!$tenant) {
                DB::rollBack();
                return $this->fail(__('messages.tenant_not_found'), Constants::RESPONSE_NOT_FOUND);
            }

            if ((int) $tenant->unit_id !== (int) $unit->id) {
                DB::rollBack();
                return $this->fail(__('contracts.tenant_unit_mismatch'));
            }

            $start = Carbon::parse($request->start_date)->startOfDay();
            $end   = Carbon::parse($request->end_date)->startOfDay();

            if ($end->lte($start)) {
                DB::rollBack();
                return $this->fail(__('contracts.invalid_dates'));
            }

            $status = $request->filled('status') ? $request->status : Constants::CONTRACT_STATUS_ACTIVE;

            if ($status === Constants::CONTRACT_STATUS_ACTIVE && $this->overlaps($unit->id, $this->fmt($start), $this->fmt($end))) {
                DB::rollBack();
                return $this->fail(__('contracts.overlap'));
            }

            $contract = Contract::create([
                'contract_number'         => $this->nextContractNumber(),
                'property_id'             => $propertyId,
                'unit_id'                 => $unit->id,
                'tenant_id'               => $tenant->id,
                'start_date'              => $this->fmt($start),
                'end_date'                => $this->fmt($end),
                'rent_amount'             => round((float) $request->rent_amount, 2),
                'payment_frequency'       => $request->payment_frequency,
                'currency'                => $request->filled('currency') ? strtoupper($request->currency) : null,
                'payment_day'             => $request->filled('payment_day') ? (int) $request->payment_day : 1,
                'security_deposit'        => $request->filled('security_deposit') ? round((float) $request->security_deposit, 2) : 0,
                'grace_days'              => $request->filled('grace_days') ? (int) $request->grace_days : 0,
                'annual_increase_percent' => $request->filled('annual_increase_percent') ? $request->annual_increase_percent : null,
                'status'                  => $status,
                'notes'                   => $request->notes,
                'created_by'              => auth()->id(),
            ]);

            ActivityLog::log('CREATE', __('contracts.created') . ': ' . $contract->contract_number, $contract);

            DB::commit();

            return ['status' => true, 'message' => __('contracts.created'), 'data' => $contract->load($this->relations())];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ContractRepository store error', ['error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    public function update($request, $contract)
    {
        DB::beginTransaction();

        try {
            if (!in_array($contract->status, [Constants::CONTRACT_STATUS_DRAFT, Constants::CONTRACT_STATUS_ACTIVE], true)) {
                DB::rollBack();
                return $this->fail(__('contracts.not_editable'));
            }

            $oldValues = $contract->toArray();

            $start = $request->filled('start_date') ? Carbon::parse($request->start_date)->startOfDay() : $contract->start_date->copy();
            $end   = $request->filled('end_date') ? Carbon::parse($request->end_date)->startOfDay() : $contract->end_date->copy();

            if ($end->lte($start)) {
                DB::rollBack();
                return $this->fail(__('contracts.invalid_dates'));
            }

            // التغيير الوحيد المسموح للحالة: مسودة ← ساري
            $status = $contract->status;
            if ($request->filled('status') && $request->status !== $contract->status) {
                if ($contract->status === Constants::CONTRACT_STATUS_DRAFT && $request->status === Constants::CONTRACT_STATUS_ACTIVE) {
                    $status = Constants::CONTRACT_STATUS_ACTIVE;
                } else {
                    DB::rollBack();
                    return $this->fail(__('contracts.invalid_status_change'));
                }
            }

            if ($status === Constants::CONTRACT_STATUS_ACTIVE
                && $this->overlaps((int) $contract->unit_id, $this->fmt($start), $this->fmt($end), (int) $contract->id)) {
                DB::rollBack();
                return $this->fail(__('contracts.overlap'));
            }

            $data = [
                'start_date' => $this->fmt($start),
                'end_date'   => $this->fmt($end),
                'status'     => $status,
            ];

            // تغيير تاريخ النهاية يعيد تفعيل تنبيهات الانتهاء
            if ($this->fmt($end) !== $this->fmt($contract->end_date)) {
                $data['expiry_alert_days'] = null;
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if ($request->filled($field)) {
                    $data[$field] = $request->input($field);
                }
            }

            foreach (self::NULLABLE_FIELDS as $field) {
                if ($request->has($field)) {
                    $data[$field] = $request->input($field);
                }
            }

            $contract->update($data);

            ActivityLog::log('UPDATE', __('contracts.updated') . ': ' . $contract->contract_number, $contract, $oldValues, $contract->fresh()->toArray());

            DB::commit();

            return ['status' => true, 'message' => __('contracts.updated'), 'data' => $contract->fresh()->load($this->relations())];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ContractRepository update error', ['error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    public function destroy($contract)
    {
        DB::beginTransaction();

        try {
            if ($contract->status !== Constants::CONTRACT_STATUS_DRAFT) {
                DB::rollBack();
                return $this->fail(__('contracts.only_draft_deletable'));
            }

            $id     = $contract->id;
            $number = $contract->contract_number;

            ActivityLog::log('DELETE', __('contracts.deleted') . ': ' . $number, $contract);
            $contract->delete();

            DB::commit();

            return ['status' => true, 'message' => __('contracts.deleted'), 'data' => ['id' => $id]];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ContractRepository destroy error', ['error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    // ================================================================
    // التجديد والإنهاء
    // ================================================================
    public function renew($request, $contract)
    {
        DB::beginTransaction();

        try {
            if (!in_array($contract->status, [Constants::CONTRACT_STATUS_ACTIVE, Constants::CONTRACT_STATUS_EXPIRED], true)) {
                DB::rollBack();
                return $this->fail(__('contracts.not_renewable'));
            }

            if ($contract->renewals()->exists()) {
                DB::rollBack();
                return $this->fail(__('contracts.already_renewed'));
            }

            $start = $request->filled('start_date')
                ? Carbon::parse($request->start_date)->startOfDay()
                : $contract->end_date->copy()->addDay();
            $end = Carbon::parse($request->end_date)->startOfDay();

            if ($start->lte($contract->end_date)) {
                DB::rollBack();
                return $this->fail(__('contracts.renewal_start_invalid'));
            }

            if ($end->lte($start)) {
                DB::rollBack();
                return $this->fail(__('contracts.invalid_dates'));
            }

            if ($this->overlaps((int) $contract->unit_id, $this->fmt($start), $this->fmt($end))) {
                DB::rollBack();
                return $this->fail(__('contracts.overlap'));
            }

            // الإيجار: من الطلب، وإلا إيجار العقد الحالي مع الزيادة السنوية إن وُجدت
            if ($request->filled('rent_amount')) {
                $rent = round((float) $request->rent_amount, 2);
            } elseif ($contract->annual_increase_percent !== null) {
                $rent = round((float) $contract->rent_amount * (1 + (float) $contract->annual_increase_percent / 100), 2);
            } else {
                $rent = (float) $contract->rent_amount;
            }

            $renewed = Contract::create([
                'contract_number'         => $this->nextContractNumber(),
                'property_id'             => $contract->property_id,
                'unit_id'                 => $contract->unit_id,
                'tenant_id'               => $contract->tenant_id,
                'start_date'              => $this->fmt($start),
                'end_date'                => $this->fmt($end),
                'rent_amount'             => $rent,
                'payment_frequency'       => $request->filled('payment_frequency') ? $request->payment_frequency : $contract->payment_frequency,
                'currency'                => $contract->currency,
                'payment_day'             => $request->filled('payment_day') ? (int) $request->payment_day : $contract->payment_day,
                'security_deposit'        => $request->filled('security_deposit') ? round((float) $request->security_deposit, 2) : $contract->security_deposit,
                'grace_days'              => $request->filled('grace_days') ? (int) $request->grace_days : $contract->grace_days,
                'annual_increase_percent' => $request->has('annual_increase_percent') ? $request->annual_increase_percent : $contract->annual_increase_percent,
                'status'                  => Constants::CONTRACT_STATUS_ACTIVE,
                'renewed_from_id'         => $contract->id,
                'notes'                   => $request->has('notes') ? $request->notes : null,
                'created_by'              => auth()->id(),
            ]);

            // العقد القديم يبقى «ساري» حتى تنتهي مدته (الوحدة تبقى مشغولة)، إلا إن كانت مدته انتهت فعلاً
            if ($contract->end_date->lt(today())) {
                $contract->update(['status' => Constants::CONTRACT_STATUS_RENEWED]);
            }

            ActivityLog::log('CREATE', __('contracts.renewed') . ': ' . $contract->contract_number . ' → ' . $renewed->contract_number, $renewed);

            DB::commit();

            return ['status' => true, 'message' => __('contracts.renewed'), 'data' => $renewed->load($this->relations())];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ContractRepository renew error', ['error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    public function terminate($request, $contract)
    {
        DB::beginTransaction();

        try {
            if ($contract->status !== Constants::CONTRACT_STATUS_ACTIVE) {
                DB::rollBack();
                return $this->fail(__('contracts.not_active'));
            }

            $oldValues = $contract->toArray();

            $contract->update([
                'status'             => Constants::CONTRACT_STATUS_TERMINATED,
                'terminated_at'      => now(),
                'termination_reason' => $request->termination_reason,
            ]);

            ActivityLog::log('UPDATE', __('contracts.terminated') . ': ' . $contract->contract_number, $contract, $oldValues, $contract->fresh()->toArray());

            DB::commit();

            return ['status' => true, 'message' => __('contracts.terminated'), 'data' => $contract->fresh()->load($this->relations())];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ContractRepository terminate error', ['error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    // ================================================================
    // مستند العقد (تخزين خاص)
    // ================================================================
    public function uploadDocument($file, $contract)
    {
        try {
            $old  = $contract->contract_document_path;
            $path = $file->store('contracts/' . $contract->id, self::DOCUMENT_DISK);

            if (!$path) {
                return $this->fail(__('contracts.upload_failed'), Constants::RESPONSE_SERVER_ERROR);
            }

            $contract->update(['contract_document_path' => $path]);

            if ($old && $old !== $path && Storage::disk(self::DOCUMENT_DISK)->exists($old)) {
                Storage::disk(self::DOCUMENT_DISK)->delete($old);
            }

            ActivityLog::log('UPDATE', __('contracts.document_uploaded') . ': ' . $contract->contract_number, $contract);

            return ['status' => true, 'message' => __('contracts.document_uploaded'), 'data' => $contract->fresh()->load($this->relations())];
        } catch (\Exception $e) {
            Log::error('ContractRepository uploadDocument error', ['error' => $e->getMessage()]);
            return $this->fail(__('messages.operation_failed'), Constants::RESPONSE_SERVER_ERROR);
        }
    }

    public function document($contract)
    {
        $path = $contract->contract_document_path;

        if (!$path || !Storage::disk(self::DOCUMENT_DISK)->exists($path)) {
            return $this->fail(__('contracts.document_missing'), Constants::RESPONSE_NOT_FOUND);
        }

        return [
            'status'  => true,
            'message' => '',
            'data'    => ['disk' => self::DOCUMENT_DISK, 'path' => $path, 'name' => $contract->contract_number . '.pdf'],
        ];
    }

    // ================================================================
    // مساعدات
    // ================================================================
    private function relations(): array
    {
        return [
            'tenant:id,name,phone,email,unit_id',
            'unit:id,name,floor_id,monthly_rent',
            'unit.floor:id,name,property_id',
            'property:id,name,name_en',
        ];
    }

    // المستخدم يرى عقود عقاراته المسندة فقط (السوبر أدمن: null = كل العقارات)
    private function limitToAccessible($query): void
    {
        $allowed = request()->attributes->get('allowed_property_ids');

        if (is_array($allowed)) {
            $query->whereIn('contracts.property_id', $allowed);
        }
    }

    private function canAccessProperty(int $propertyId): bool
    {
        $allowed = request()->attributes->get('allowed_property_ids');

        return !is_array($allowed) || in_array($propertyId, $allowed, true);
    }

    private function overlaps(int $unitId, string $start, string $end, ?int $ignoreId = null): bool
    {
        return Contract::overlapping($unitId, $start, $end, $ignoreId)->exists();
    }

    private function fmt($date): string
    {
        return Carbon::parse($date)->format(Constants::DATE_FORMAT);
    }

    private function nextContractNumber(): string
    {
        $prefix = 'CT-' . date('Y') . '-';

        $last = Contract::withTrashed()
            ->where('contract_number', 'like', $prefix . '%')
            ->orderByDesc('contract_number')
            ->lockForUpdate()
            ->first();

        $next = $last ? ((int) substr($last->contract_number, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function fail($message, $code = Constants::RESPONSE_VALIDATION_ERROR): array
    {
        return ['status' => false, 'message' => $message, 'data' => null, 'code' => $code];
    }
}
