<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('employees:activate')->everyMinute();
Schedule::command('offboarding:notify')->everyMinute();
Schedule::command('security:audit-report')->hourly();
Schedule::command('leave:remind-managers')->dailyAt('09:00');
Schedule::command('claims:remind')->dailyAt('09:00');
Schedule::command('sweep:pending-weekly')->weeklyOn(3, '00:00'); // Wednesday midnight

// Backup: daily encrypted full backup at 2 AM, retain 30 days
Schedule::command('backup:run --type=full --encrypt --keep=30')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));

// Backup: database-only snapshot every 6 hours for RPO minimization
Schedule::command('backup:run --type=database --encrypt --keep=7')
    ->everySixHours()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/backup.log'));

// Log integrity: verify the audit log chain daily at 3 AM
Schedule::command('log:verify-integrity')
    ->dailyAt('03:00')
    ->appendOutputTo(storage_path('logs/integrity-check.log'));

// System metadata: auto-refresh cached metadata for System Overview & Knowledge Base
Schedule::command('system:refresh-metadata')->hourly();

// Update checker: daily package update scan + security score refresh
Schedule::command('system:check-updates')->dailyAt('06:00');

// Tickets: hourly scan for tickets idle 24h+; throttled to 1 reminder per ticket per 24h
Schedule::command('tickets:remind-stale')->hourly();