<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\CompanyAttributionService;
use Illuminate\Console\Command;

/**
 * Reconciles every employee's claims/leave/tickets against their company
 * timeline (Employee::companyAsOf). Run once after deploying the company-timeline
 * re-attribution feature to fix historical records, or any time you suspect the
 * stored company columns have drifted from the timeline.
 *
 *   php artisan company:reattribute            # apply to all active employees
 *   php artisan company:reattribute --dry-run  # report what WOULD change
 *   php artisan company:reattribute --employee=42
 *   php artisan company:reattribute --all      # include inactive/offboarded too
 */
class ReattributeCompany extends Command
{
    protected $signature = 'company:reattribute
        {--dry-run : Report what would change without writing}
        {--employee= : Restrict to a single employee id}
        {--all : Include inactive/offboarded employees (default: active only)}';

    protected $description = 'Re-attribute employees\' claims/leave/tickets to the company timeline';

    public function handle(CompanyAttributionService $service): int
    {
        $apply = ! $this->option('dry-run');

        $query = Employee::query();
        if ($id = $this->option('employee')) {
            $query->whereKey($id);
        } elseif (! $this->option('all')) {
            $query->whereNull('active_until');
        }

        $totals = ['claims' => 0, 'leave' => 0, 'tickets' => 0];
        $touched = 0;

        $query->orderBy('id')->chunkById(200, function ($employees) use ($service, $apply, &$totals, &$touched) {
            foreach ($employees as $employee) {
                $counts = $service->reattributeEmployee($employee, $apply);
                $sum = array_sum($counts);
                if ($sum > 0) {
                    $touched++;
                    foreach ($totals as $k => $_) {
                        $totals[$k] += $counts[$k];
                    }
                    $this->line(sprintf(
                        '  %s#%d %s — claims:%d leave:%d tickets:%d',
                        $apply ? '' : '[dry] ',
                        $employee->id,
                        $employee->full_name ?? '',
                        $counts['claims'],
                        $counts['leave'],
                        $counts['tickets'],
                    ));
                }
            }
        });

        $verb = $apply ? 'Re-attributed' : 'Would re-attribute';
        $this->info(sprintf(
            '%s %d record(s) across %d employee(s): claims %d, leave %d, tickets %d.',
            $verb, array_sum($totals), $touched, $totals['claims'], $totals['leave'], $totals['tickets']
        ));

        return self::SUCCESS;
    }
}
