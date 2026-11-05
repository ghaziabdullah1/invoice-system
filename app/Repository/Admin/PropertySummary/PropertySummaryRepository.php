<?php

namespace App\Repository\Admin\PropertySummary;

use App\Constants\Constants;
use App\Models\Contract;
use App\Models\Floor;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ملخص العقارات لقسم العقارات في لوحة التحكم الرئيسية.
 *
 * حالة الوحدة تُشتق من العقود ولا تُخزَّن:
 *   inactive : الوحدة معطّلة
 *   occupied : عليها عقد ساري اليوم
 *   reserved : عليها عقد ساري يبدأ في تاريخ لاحق
 *   vacant   : غير ذلك
 */
class PropertySummaryRepository implements PropertySummaryInterface
{
    private const EXPIRING_SOON_DAYS = 60;
    private const TIMELINE_MONTHS = 12;
    private const UNSPECIFIED_TYPE = 'unspecified';

    public function summary($request)
    {
        try {
            $propertiesQuery = Property::withCount('floors')->orderBy('name');

            // المستخدم يرى العقارات المسندة له فقط (السوبر أدمن: null = كل العقارات)
            $allowedPropertyIds = $request->attributes->get('allowed_property_ids');
            if (is_array($allowedPropertyIds)) {
                $propertiesQuery->whereIn('id', $allowedPropertyIds);
            }

            $properties = $propertiesQuery
                ->get(['id', 'name', 'name_en', 'type', 'city', 'address', 'phone', 'email', 'is_active']);

            $selected = null;

            if ($request->filled('property_id')) {
                $selected = $properties->firstWhere('id', (int) $request->input('property_id'));

                if (!$selected) {
                    return [
                        'status'  => false,
                        'message' => __('messages.property_not_found'),
                        'data'    => null,
                        'code'    => Constants::RESPONSE_NOT_FOUND,
                    ];
                }
            }

            $today = today();

            $contracts = Contract::inEffect()
                ->get(['unit_id', 'property_id', 'end_date', 'payment_frequency', 'rent_amount']);

            $occupiedUnits = $contracts->pluck('unit_id')->flip();

            $reservedUnits = Contract::active()
                ->whereDate('start_date', '>', $today->format(Constants::DATE_FORMAT))
                ->pluck('unit_id')
                ->flip();

            $unitsByProperty = Unit::join('floors', 'floors.id', '=', 'units.floor_id')
                ->whereNull('floors.deleted_at')
                ->get(['units.id', 'units.name', 'units.floor_id', 'units.is_active', 'units.area', 'units.monthly_rent', 'floors.property_id'])
                ->groupBy('property_id');

            $contractsByProperty = $contracts->groupBy('property_id');

            $rows = $properties->map(fn ($property) => $this->propertyRow(
                $property,
                $unitsByProperty->get($property->id, collect()),
                $contractsByProperty->get($property->id, collect()),
                $occupiedUnits,
                $reservedUnits,
                $today
            ))->values();

            $scopeRows      = $selected ? $rows->where('id', $selected->id)->values() : $rows;
            $scopeContracts = $selected
                ? $contracts->where('property_id', $selected->id)
                : $contracts->whereIn('property_id', $properties->pluck('id')->all());
            $totals         = $this->totals($scopeRows);

            return [
                'status'  => true,
                'message' => __('messages.properties_fetched'),
                'data'    => [
                    'scope'           => ['property_id' => $selected?->id],
                    'totals'          => $totals,
                    'unit_status'     => [
                        Constants::UNIT_STATUS_OCCUPIED => $totals['occupied_units'],
                        Constants::UNIT_STATUS_RESERVED => $totals['reserved_units'],
                        Constants::UNIT_STATUS_VACANT   => $totals['vacant_units'],
                        Constants::UNIT_STATUS_INACTIVE => $totals['inactive_units'],
                    ],
                    'by_type'         => $this->byType($scopeRows),
                    'properties'      => $rows,
                    'property'        => $selected ? $this->propertyDetails($selected) : null,
                    'floors'          => $selected ? $this->floorsBreakdown($selected, $unitsByProperty->get($selected->id, collect()), $occupiedUnits, $reservedUnits) : [],
                    'expiry_timeline' => $this->expiryTimeline($scopeContracts, $today),
                    'expiring_days'   => self::EXPIRING_SOON_DAYS,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('PropertySummaryRepository summary error', ['error' => $e->getMessage()]);

            return ['status' => false, 'message' => __('messages.operation_failed'), 'data' => null];
        }
    }

    private function propertyRow($property, Collection $units, Collection $contracts, Collection $occupiedUnits, Collection $reservedUnits, Carbon $today): array
    {
        $tally  = $this->tally($units, $occupiedUnits, $reservedUnits);
        $counts = $tally['counts'];

        $expiringLimit = $today->copy()->addDays(self::EXPIRING_SOON_DAYS);

        return [
            'id'                      => $property->id,
            'name'                    => $property->name,
            'name_en'                 => $property->name_en,
            'type'                    => $property->type,
            'city'                    => $property->city,
            'is_active'               => (bool) $property->is_active,
            'floors_count'            => (int) $property->floors_count,
            'units_count'             => $units->count(),
            'occupied_units'          => $counts[Constants::UNIT_STATUS_OCCUPIED],
            'reserved_units'          => $counts[Constants::UNIT_STATUS_RESERVED],
            'vacant_units'            => $counts[Constants::UNIT_STATUS_VACANT],
            'inactive_units'          => $counts[Constants::UNIT_STATUS_INACTIVE],
            'occupancy_rate'          => $this->occupancyRate($counts),
            'active_contracts'        => $contracts->count(),
            'expiring_contracts'      => $contracts->filter(fn ($contract) => $contract->end_date->lte($expiringLimit))->count(),
            'contracted_monthly_rent' => round($contracts->sum(fn ($contract) => $this->monthlyRent($contract)), 2),
            'vacant_monthly_rent'     => round($tally['vacant_rent'], 2),
        ];
    }

    /**
     * عدّ الوحدات حسب الحالة + مجموع الإيجار المعروض للوحدات الشاغرة (الدخل الضائع).
     */
    private function tally(Collection $units, Collection $occupiedUnits, Collection $reservedUnits): array
    {
        $counts = [
            Constants::UNIT_STATUS_OCCUPIED => 0,
            Constants::UNIT_STATUS_RESERVED => 0,
            Constants::UNIT_STATUS_VACANT   => 0,
            Constants::UNIT_STATUS_INACTIVE => 0,
        ];
        $vacantRent = 0.0;

        foreach ($units as $unit) {
            $status = $this->unitStatus($unit, $occupiedUnits, $reservedUnits);
            $counts[$status]++;

            if ($status === Constants::UNIT_STATUS_VACANT) {
                $vacantRent += (float) $unit->monthly_rent;
            }
        }

        return ['counts' => $counts, 'vacant_rent' => $vacantRent];
    }

    private function unitStatus($unit, Collection $occupiedUnits, Collection $reservedUnits): string
    {
        if (!$unit->is_active) {
            return Constants::UNIT_STATUS_INACTIVE;
        }

        if ($occupiedUnits->has($unit->id)) {
            return Constants::UNIT_STATUS_OCCUPIED;
        }

        if ($reservedUnits->has($unit->id)) {
            return Constants::UNIT_STATUS_RESERVED;
        }

        return Constants::UNIT_STATUS_VACANT;
    }

    /**
     * نسبة الإشغال من الوحدات القابلة للتأجير (المعطّلة لا تدخل في المقام).
     */
    private function occupancyRate(array $counts): float
    {
        $rentable = $counts[Constants::UNIT_STATUS_OCCUPIED]
            + $counts[Constants::UNIT_STATUS_RESERVED]
            + $counts[Constants::UNIT_STATUS_VACANT];

        return $rentable > 0 ? round($counts[Constants::UNIT_STATUS_OCCUPIED] / $rentable * 100, 1) : 0.0;
    }

    private function totals(Collection $rows): array
    {
        $counts = [
            Constants::UNIT_STATUS_OCCUPIED => (int) $rows->sum('occupied_units'),
            Constants::UNIT_STATUS_RESERVED => (int) $rows->sum('reserved_units'),
            Constants::UNIT_STATUS_VACANT   => (int) $rows->sum('vacant_units'),
            Constants::UNIT_STATUS_INACTIVE => (int) $rows->sum('inactive_units'),
        ];

        return [
            'properties_count'        => $rows->count(),
            'floors_count'            => (int) $rows->sum('floors_count'),
            'units_count'             => (int) $rows->sum('units_count'),
            'occupied_units'          => $counts[Constants::UNIT_STATUS_OCCUPIED],
            'reserved_units'          => $counts[Constants::UNIT_STATUS_RESERVED],
            'vacant_units'            => $counts[Constants::UNIT_STATUS_VACANT],
            'inactive_units'          => $counts[Constants::UNIT_STATUS_INACTIVE],
            'occupancy_rate'          => $this->occupancyRate($counts),
            'active_contracts'        => (int) $rows->sum('active_contracts'),
            'expiring_contracts'      => (int) $rows->sum('expiring_contracts'),
            'contracted_monthly_rent' => round((float) $rows->sum('contracted_monthly_rent'), 2),
            'vacant_monthly_rent'     => round((float) $rows->sum('vacant_monthly_rent'), 2),
        ];
    }

    private function byType(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn ($row) => $row['type'] ?? self::UNSPECIFIED_TYPE)
            ->map(fn ($group, $type) => [
                'type'             => $type,
                'properties_count' => $group->count(),
                'units_count'      => (int) $group->sum('units_count'),
                'occupied_units'   => (int) $group->sum('occupied_units'),
            ])
            ->values();
    }

    private function propertyDetails($property): array
    {
        return [
            'id'           => $property->id,
            'name'         => $property->name,
            'name_en'      => $property->name_en,
            'type'         => $property->type,
            'city'         => $property->city,
            'address'      => $property->address,
            'phone'        => $property->phone,
            'email'        => $property->email,
            'is_active'    => (bool) $property->is_active,
            'floors_count' => (int) $property->floors_count,
        ];
    }

    /**
     * توزيع وحدات عقار واحد على طوابقه، مع حالة كل وحدة.
     */
    private function floorsBreakdown($property, Collection $units, Collection $occupiedUnits, Collection $reservedUnits): Collection
    {
        $unitsByFloor = $units->groupBy('floor_id');

        return Floor::where('property_id', $property->id)
            ->orderBy('floor_number')
            ->get(['id', 'name', 'floor_number'])
            ->map(function ($floor) use ($unitsByFloor, $occupiedUnits, $reservedUnits) {
                $floorUnits = $unitsByFloor->get($floor->id, collect())->sortBy('name', SORT_NATURAL)->values();
                $counts     = $this->tally($floorUnits, $occupiedUnits, $reservedUnits)['counts'];

                return [
                    'id'             => $floor->id,
                    'name'           => $floor->name,
                    'floor_number'   => $floor->floor_number,
                    'units_count'    => $floorUnits->count(),
                    'occupied_units' => $counts[Constants::UNIT_STATUS_OCCUPIED],
                    'reserved_units' => $counts[Constants::UNIT_STATUS_RESERVED],
                    'vacant_units'   => $counts[Constants::UNIT_STATUS_VACANT],
                    'inactive_units' => $counts[Constants::UNIT_STATUS_INACTIVE],
                    'units'          => $floorUnits->map(fn ($unit) => [
                        'id'           => $unit->id,
                        'name'         => $unit->name,
                        'status'       => $this->unitStatus($unit, $occupiedUnits, $reservedUnits),
                        'area'         => $unit->area,
                        'monthly_rent' => $unit->monthly_rent,
                    ])->values(),
                ];
            })
            ->values();
    }

    /**
     * العقود السارية التي تنتهي في كل شهر من الأشهر الاثني عشر القادمة، مع إيجارها الشهري المعرّض للانتهاء.
     */
    private function expiryTimeline(Collection $contracts, Carbon $today): array
    {
        $timeline = [];

        for ($i = 0; $i < self::TIMELINE_MONTHS; $i++) {
            $month = $today->copy()->startOfMonth()->addMonths($i)->format('Y-m');
            $timeline[$month] = ['month' => $month, 'expiring_contracts' => 0, 'monthly_rent' => 0.0];
        }

        foreach ($contracts as $contract) {
            $month = $contract->end_date->format('Y-m');

            if (isset($timeline[$month])) {
                $timeline[$month]['expiring_contracts']++;
                $timeline[$month]['monthly_rent'] += $this->monthlyRent($contract);
            }
        }

        return array_values(array_map(
            fn ($item) => ['monthly_rent' => round($item['monthly_rent'], 2)] + $item,
            $timeline
        ));
    }

    /**
     * قيمة الإيجار في العقد هي لكل دورة دفع، فنحوّلها إلى ما يعادل الشهر.
     */
    private function monthlyRent($contract): float
    {
        $months = Constants::CONTRACT_FREQUENCY_MONTHS[$contract->payment_frequency] ?? null;

        return $months ? (float) $contract->rent_amount / $months : 0.0;
    }
}
