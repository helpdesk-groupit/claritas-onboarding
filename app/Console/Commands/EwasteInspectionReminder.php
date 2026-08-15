<?php

namespace App\Console\Commands;

use App\Services\EwasteInspectionReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Phase 3 — chase the inspections in the run-up to the quarterly e-waste collection.
 *
 * Runs daily and self-gates to the five marks (1 month / 15 / 5 / 3 days before, and the day
 * itself), mirroring the eClaim reminder idiom rather than five scheduler entries — the marks
 * move with `decommission.sweep_day` and a calendar-month offset is not something the static
 * scheduler can express.
 *
 * Sends nothing when every queued asset is already inspected.
 */
class EwasteInspectionReminder extends Command
{
    protected $signature = 'ewaste:remind-inspection
        {--force : Send now regardless of the date gate (uses the day-of wording)}
        {--date= : Pretend today is this date (YYYY-MM-DD), for checking the schedule}';

    protected $description = 'Remind IT to inspect queued e-waste assets before the quarterly collection.';

    public function handle(): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date')) : now();

        $result = EwasteInspectionReminderService::run($today, (bool) $this->option('force'));

        $this->info($result['message']);

        if ($result['mark']) {
            $sweep = EwasteInspectionReminderService::nextSweepDate($today);
            $this->line("  Mark: {$result['mark']} · collection date: ".$sweep->toDateString());
        }

        return self::SUCCESS;
    }
}
