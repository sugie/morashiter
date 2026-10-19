<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly matches the feeds' own update period (e.g. Security NEXT declares sy:updatePeriod hourly).
Schedule::command('msnc:discover --all')
    ->hourly()
    ->withoutOverlapping();
