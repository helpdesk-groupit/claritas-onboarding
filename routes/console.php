<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('employees:activate')->everyMinute();
Schedule::command('offboarding:notify')->everyMinute();
// Apply future-dated company moves scheduled from "User – Company Setting" once their
// effective date arrives. Date-granular, so daily just after midnight is enough; the
// command is idempotent (only flips `pending` rows to `applied`).
Schedule::command('company:apply-scheduled')->dailyAt('00:10');
Schedule::command('security:audit-report')->hourly();
Schedule::command('leave:remind-managers')->dailyAt('09:00');
Schedule::command('claims:remind')->dailyAt('09:00');
Schedule::command('claims:remind-approvers')->dailyAt('09:00');
// Safety net: on the monthly submission cutoff (e.g. the 20th), auto-submit every complete
// draft still left unsubmitted. Self-gates to the cutoff day; runs late so same-day edits land.
Schedule::command('claims:auto-submit')->dailyAt('23:30');
// Birthdays: check every minute so candidates activated mid-day (e.g. a
// rehire, or an employee whose start_date lands on their birthday) get the
// e-card almost immediately. Idempotent via employees.birthday_email_sent_year
// — at most one email per employee per calendar year, regardless of run
// frequency. withoutOverlapping prevents two simultaneous runs from
// double-sending if a previous run is still in flight.
Schedule::command('birthdays:send-wishes')
    ->everyMinute()
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping();
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

// GeoIP: refresh the GeoLite2-Country DB monthly for trusted-device location checks.
// MaxMind publishes weekly and the licence requires staying within 30 days of a
// release; monthly keeps us compliant. Fails safe (keeps the old file on error).
Schedule::command('geoip:update')
    ->monthlyOn(1, '04:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/geoip.log'));

// Email Workflow: drive every active capture automation. Runs every minute and
// self-gates — each workflow carries its own capture_cron + timezone, which the
// static scheduler can't express, so RunEmailWorkflows evaluates them itself.
Schedule::command('email-workflows:run')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/email-workflows.log'));
