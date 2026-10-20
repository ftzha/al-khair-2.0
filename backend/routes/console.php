<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Commands are retry-safe; the scheduler runner must be started separately.
Schedule::command('memberships:generate-renewals')->hourly()->timezone('Asia/Kuala_Lumpur')
    ->when(fn () => now('Asia/Kuala_Lumpur')->month === 1 && now('Asia/Kuala_Lumpur')->day <= 30)
    ->withoutOverlapping();
Schedule::command('memberships:sync-statuses')->hourly()->timezone('Asia/Kuala_Lumpur')->withoutOverlapping();
