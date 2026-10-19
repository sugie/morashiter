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

// msic: fetch companies' own announcements for candidates an admin accepted (after discovery at :00).
Schedule::command('msic:collect')
    ->hourlyAt(20)
    ->withoutOverlapping();

// Analyse official documents still without a review (or only a mock draft), within the daily budget.
Schedule::command('msic:analyze-pending')
    ->hourlyAt(40)
    ->withoutOverlapping();
