<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Illuminate\Support\Facades\Schedule::command('installments:mark-overdue')->dailyAt('00:05');
Illuminate\Support\Facades\Schedule::command('invoices:check-due-dates')->dailyAt('08:00');
Illuminate\Support\Facades\Schedule::command('invoices:generate-recurring')->dailyAt('06:00');
Illuminate\Support\Facades\Schedule::call(fn () => app(App\Services\ExportService::class)->cleanupOldFiles(7))
    ->name('exports-cleanup')
    ->dailyAt('03:00');
