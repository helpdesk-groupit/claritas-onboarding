<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('employees:activate')->everyMinute();
Schedule::command('offboarding:notify')->everyMinute();
Schedule::command('security:audit-report')->hourly();
Schedule::command('leave:remind-managers')->dailyAt('09:00');
Schedule::command('claims:remind')->dailyAt('09:00');

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