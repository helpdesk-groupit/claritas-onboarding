<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\ScheduledCompanyChange;
use App\Services\CompanyAttributionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies future-dated company moves whose effective date has arrived. Scheduled from the
 * "User – Company Setting" page and stored in scheduled_company_changes; this command flips
 * each due row into a real timeline change on the day it takes effect — the same path an
 * immediate move would take (Employee::changeCompanyEffective + re-attribution + onboarding
 * sync). Idempotent: only reads `pending` rows and marks them `applied`, so reruns are no-ops.
 */
class ApplyScheduledCompanyChanges extends Command
{
    protected $signature = 'company:apply-scheduled {--dry-run : List what would be applied without changing anything}';

    protected $description = 'Apply scheduled (future-dated) company changes whose effective date has arrived';

    public function handle(CompanyAttributionService $attribution): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $due = ScheduledCompanyChange::due()->orderBy('effective_date')->orderBy('id')->get();

        $this->info(($dryRun ? '[dry-run] ' : '')."Found {$due->count()} scheduled company change(s) due.");

        $applied = 0;
        $superseded = 0;

        foreach ($due as $change) {
            $employee = Employee::find($change->employee_id);

            // Employee gone or offboarded before the change landed — nothing to move.
            if (! $employee || $employee->active_until !== null) {
                $reason = ! $employee ? 'employee record no longer exists' : 'employee was offboarded before the effective date';
                $this->warn("  Skipped #{$change->id}: {$reason}.");
                if (! $dryRun) {
                    $change->update([
                        'status' => 'superseded',
                        'note' => trim(($change->note ? $change->note.' ' : '').'Not applied — '.$reason.'.'),
                    ]);
                }
                $superseded++;

                continue;
            }

            if ($dryRun) {
                $this->line("  Would move {$employee->full_name} → {$change->company} effective {$change->effective_date->format('d M Y')}.");
                $applied++;

                continue;
            }

            DB::transaction(function () use ($change, $employee, $attribution, &$applied, &$superseded) {
                $result = $employee->changeCompanyEffective(
                    $change->company,
                    $change->office_location,
                    $change->effective_date->copy()->startOfDay(),
                    $change->scheduled_by,
                );

                if ($result['status'] === 'changed') {
                    // Ripple the move through already-created claims/leave/tickets, exactly
                    // like UserCompanySettingController::bulkAssign does for an immediate move.
                    $attribution->reattributeEmployee($employee);

                    if ($employee->onboarding_id) {
                        Onboarding::with('workDetail')->find($employee->onboarding_id)?->workDetail
                            ?->update(['company' => $employee->company, 'office_location' => $employee->office_location]);
                    }

                    $change->update(['status' => 'applied', 'applied_at' => now()]);
                    $this->info("  Applied: {$employee->full_name} → {$change->company} (effective {$change->effective_date->format('d M Y')}).");
                    $applied++;
                } else {
                    // 'skipped_same' (already there) or 'skipped_date' (an interim move made the
                    // date invalid). Either way the scheduled intent no longer applies cleanly.
                    $change->update([
                        'status' => 'superseded',
                        'note' => trim(($change->note ? $change->note.' ' : '').'Not applied — '.$result['message'].'.'),
                    ]);
                    $this->warn("  Superseded #{$change->id} ({$employee->full_name}): {$result['message']}.");
                    $superseded++;
                }
            });
        }

        if (! $dryRun && ($applied > 0 || $superseded > 0)) {
            Log::info('Scheduled company changes applied', ['applied' => $applied, 'superseded' => $superseded]);
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Done. Applied: {$applied}, superseded: {$superseded}.");

        return self::SUCCESS;
    }
}
