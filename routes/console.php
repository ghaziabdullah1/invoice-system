<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('installments:mark-overdue')->dailyAt('00:05');
Schedule::command('invoices:check-due-dates')->dailyAt('08:00');
Schedule::command('invoices:generate-recurring')->dailyAt('06:00');
Schedule::command('contracts:update-statuses')->dailyAt('00:10');
Schedule::command('contracts:generate-invoices')->dailyAt('06:30');
Schedule::command('contracts:notify-expiring')->dailyAt('07:00');
Schedule::call(fn () => app(App\Services\ExportService::class)->cleanupOldFiles(7))
    ->name('exports-cleanup')
    ->dailyAt('03:00');
