<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Document expiry + monthly-upload alerts (DECISIONS.md schedules).
// Server cron runs schedule:run every minute (CLAUDE.md deployment notes).
Schedule::command('verto:scan-documents')->dailyAt('07:00')->timezone('Europe/Madrid');

// Nightly auto-absent sweep: book absences for active employees with no record
// on a weekday. Weekends and approved leave are skipped inside the command.
Schedule::command('attendance:auto-absent')->dailyAt('23:59')->timezone('Europe/Madrid');

// Time-based alerts: overdue invoices, unreturned vehicles, call follow-ups.
// Each fires once (dedup flags), so a daily run is safe.
Schedule::command('notifications:scan')->dailyAt('08:00')->timezone('Europe/Madrid');

// Database-driver queue: processed via the scheduler on cPanel (no daemon)
Schedule::command('queue:work --stop-when-empty --tries=3')->everyMinute()->withoutOverlapping();
