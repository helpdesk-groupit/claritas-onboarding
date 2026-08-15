<?php

namespace App\Console\Commands;

use App\Services\EwasteSweepService;
use Illuminate\Console\Command;

/**
 * Quarterly e-waste sweep. Runs daily and self-gates to the first day of each
 * quarter (Jan/Apr/Jul/Oct), unless --force is passed. Gathers all assets
 * awaiting e-waste decommissioning into a new cycle, RFQs every active e-waste
 * vendor with a PIC email, and reports to Finance. Mirrors the eClaim reminder
 * self-gating idiom.
 */
class EwasteQuarterlySweep extends Command
{
    protected $signature = 'ewaste:sweep-quarterly {--force : Run now regardless of the quarterly date gate}';

    protected $description = 'Quarterly e-waste sweep: open a cycle for Not-Good assets, RFQ every e-waste vendor, report to Finance.';

    public function handle(): int
    {
        $now = now();
        $sweepDay = (int) config('decommission.sweep_day', 1);

        // Quarter-start months only; the sweep_day-th day of that month is the gate.
        $isQuarterStartMonth = in_array($now->month, [1, 4, 7, 10], true);
        $isSweepDay = $isQuarterStartMonth && $now->day === $sweepDay;

        if (! $this->option('force') && ! $isSweepDay) {
            $this->info("Not the quarterly sweep day (day {$sweepDay} of a quarter-start month). Skipping.");

            return self::SUCCESS;
        }

        $result = EwasteSweepService::sweep();

        // A postponement is a correct outcome, not a failure — the command still exits 0 so
        // the scheduler does not report it as a broken job, but it reads as a warning.
        if ($result['blocked']) {
            $this->warn($result['message']);

            return self::SUCCESS;
        }

        $this->info($result['message']);

        return self::SUCCESS;
    }
}
