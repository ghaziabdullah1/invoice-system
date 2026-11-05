<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('contracts.expiry_mail_title') }}</title>
</head>
<body style="font-family: Tahoma, Arial, sans-serif; background: #f1f5f9; margin: 0; padding: 24px;">
@php
    $unitName = trim(($contract->unit?->floor?->name ? $contract->unit->floor->name . ' — ' : '') . ($contract->unit?->name ?? ''));
    $propertyName = app()->getLocale() === 'ar'
        ? $contract->property?->name
        : ($contract->property?->name_en ?: $contract->property?->name);
    $rows = [
        __('contracts.expiry_mail_contract') => $contract->contract_number,
        __('contracts.expiry_mail_tenant') => $contract->tenant?->name,
        __('contracts.expiry_mail_unit') => $unitName,
        __('contracts.expiry_mail_property') => $propertyName,
        __('contracts.expiry_mail_end_date') => $contract->end_date->format('Y-m-d'),
        __('contracts.expiry_mail_rent') => number_format((float) $contract->rent_amount, 2) . ' ' . ($contract->currency ?: ''),
    ];
@endphp
<div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e2e8f0;">
    <div style="background: #1e3a8a; color: #ffffff; padding: 20px 24px;">
        <h2 style="margin: 0; font-size: 18px;">{{ __('contracts.expiry_mail_title') }}</h2>
    </div>
    <div style="padding: 24px; color: #334155; font-size: 14px; line-height: 1.7;">
        <p style="margin-top: 0;">{{ __('contracts.expiry_mail_intro', ['days' => $daysLeft]) }}</p>
        <table style="width: 100%; border-collapse: collapse;">
            @foreach ($rows as $label => $value)
                <tr>
                    <td style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; color: #64748b; width: 35%;">{{ $label }}</td>
                    <td style="padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-weight: bold; color: #0f172a;">{{ $value ?: '-' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
</body>
</html>
