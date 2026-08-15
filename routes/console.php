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

// E-waste: quarterly decommissioning sweep. Runs daily just after midnight and
// self-gates to the first day of each quarter (Jan/Apr/Jul/Oct) — the static
// scheduler can't express "first of quarter + config day", so the command does it.
Schedule::command('ewaste:sweep-quarterly')->dailyAt('00:20')->withoutOverlapping();

// E-waste: chase the inspections in the run-up to that sweep — 1 month / 15 / 5 / 3 days
// before it, and on the day. Also daily + self-gating, for the same reason: the marks move
// with `decommission.sweep_day`, and "a calendar month before" is not something the static
// scheduler can express. Silent unless something is actually outstanding.
//
// 08:00 rather than just after midnight because it is a message to people, not a job. On the
// collection day that puts it AFTER the 00:20 sweep has already decided — which is correct:
// by then the list is the reason the cycle was postponed, and the mail says so.
Schedule::command('ewaste:remind-inspection')
    ->dailyAt('08:00')
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping();

// Vendor Management: discard documents that were uploaded and scanned in the Add-Document
// modal but never filed. The file is stored before the record exists, so an abandoned
// upload leaves a file on the private disk that nothing points at.
Schedule::command('vendors:prune-document-scans')->dailyAt('00:40')->withoutOverlapping();

// Vendor Management: discard vendor lists uploaded for bulk import but never confirmed.
// Same reason as the sweep above — the spreadsheet is stored before any vendor exists — but
// with a cleaner guarantee: an import copies values OUT of the file, so nothing ever points
// at it and a surviving row is always an unclaimed upload.
Schedule::command('vendors:prune-import-batches')->dailyAt('00:45')->withoutOverlapping();

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
// Each due workflow is dispatched to the `database` queue (set in
// RunEmailWorkflowCapture's constructor) so this command returns in well under a
// second and fans out every workflow due this minute — under the old `sync`
// path each dispatch ran the full multi-minute sweep inline, so only the first
// workflow of the batch ever fired and the rest missed their cron minute.
Schedule::command('email-workflows:run')
    ->everyMinute()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/email-workflows.log'));

// Email Workflow queue worker (scheduler-supervised, not a bare daemon).
// --stop-when-empty: one invocation drains every queued sweep then exits, so
// there is no idle daemon to babysit; riding the already-working every-minute
// cron means it self-heals after a crash or reboot (a bare daemon would need a
// DSM boot task and would silently stop sweeping if it died). runInBackground
// is mandatory — a drain can take ~20 min and must NOT block schedule:run (that
// would stall birthdays, claim reminders, etc.). withoutOverlapping(30) keeps a
// single drain in flight and self-releases in 30 min if a drain is killed
// without clearing its mutex (ShouldBeUnique + the captures UNIQUE index make a
// rare overlap harmless). Only the `database` connection is drained; `sync`
// work (mails, notifications) never touches a queue table.
Schedule::command('queue:work database --stop-when-empty --tries=1 --timeout=3600 --sleep=3')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/queue-worker.log'));
