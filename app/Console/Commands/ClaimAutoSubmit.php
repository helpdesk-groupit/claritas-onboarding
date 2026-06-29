<?php

namespace App\Console\Commands;

use App\Mail\ClaimAutoSubmittedMail;
use App\Mail\ClaimSubmittedMail;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimLog;
use App\Models\ExpenseClaimPolicy;
use App\Notifications\ClaimAutoSubmittedNotification;
use App\Services\ClaimRulesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ClaimAutoSubmit extends Command
{
    protected $signature = 'claims:auto-submit {--force : Run regardless of the cutoff-day check}';

    protected $description = 'On the monthly submission cutoff (e.g. the 20th), auto-submit every outstanding, COMPLETE draft claim so nothing is missed. Users may still submit any time before/after; incomplete drafts are left untouched.';

    public function handle(): int
    {
        $policy = ExpenseClaimPolicy::forCompany();
        $deadlineDay = $policy->submission_deadline_day ?? 20;

        $now = now();
        $cutoffDay = min(max(1, $deadlineDay), $now->daysInMonth);

        if (! $this->option('force') && $now->day !== $cutoffDay) {
            $this->info("Not the submission cutoff day (the {$cutoffDay}). Skipping.");

            return self::SUCCESS;
        }

        // Every outstanding draft that has at least one item — any reporting period. A draft
        // created AFTER this month's cutoff simply isn't here yet on a cutoff-day run; it rolls
        // to next month's sweep (or the user submits it manually with the late-cycle banner).
        $drafts = ExpenseClaim::where('status', 'draft')
            ->where('item_count', '>', 0)
            ->with(['items.category', 'employee'])
            ->get();

        $signable = ClaimRulesService::signableApprovers()->pluck('id');
        $submitted = 0;
        $skipped = 0;

        foreach ($drafts as $claim) {
            $employee = $claim->employee;
            if (! $employee) {
                $skipped++;

                continue;
            }

            // A valid approving PIC/manager is required — fall back to the employee's default.
            $approverId = $claim->manager_id;
            if (! $approverId || ! $signable->contains((int) $approverId)) {
                $approverId = ClaimRulesService::defaultApproverId($employee);
            }
            if (! $approverId || ! $signable->contains((int) $approverId)) {
                $this->warn("Skipped {$claim->claim_number}: no valid approver.");
                $skipped++;

                continue;
            }

            // Project/client required (except Sales).
            $isSales = str_contains(strtolower((string) $employee->department), 'sales');
            if (empty($claim->project_client) && ! $isSales) {
                $this->warn("Skipped {$claim->claim_number}: missing project/client.");
                $skipped++;

                continue;
            }

            // No receipt, no claim (mileage exempt) — only fully-evidenced drafts auto-submit.
            $missing = $claim->items->filter(fn ($it) => $it->needsReceipt());
            if ($missing->isNotEmpty()) {
                $this->warn("Skipped {$claim->claim_number}: {$missing->count()} item(s) missing a receipt.");
                $skipped++;

                continue;
            }

            $claim->recalculateTotals();
            DB::transaction(function () use ($claim, $approverId) {
                $claim->items()->update([
                    'approver_id' => $approverId,
                    'manager_status' => 'pending',
                    'manager_remarks' => null,
                    'review_status' => 'approved',
                    'is_locked' => true,
                ]);
                $claim->update(['status' => 'submitted', 'submitted_at' => now(), 'manager_id' => $approverId]);
            });

            ExpenseClaimLog::create([
                'expense_claim_id' => $claim->id,
                'action' => 'submitted',
                'actor_id' => null,
                'actor_name' => 'System (auto-submit)',
                'detail' => "Auto-submitted on the monthly cutoff (the {$cutoffDay}).",
            ]);

            $manager = Employee::find($approverId);
            if ($manager && $manager->user) {
                Mail::to($manager->user->work_email)->queue(new ClaimSubmittedMail($claim, $employee, 'manager'));
            }

            // Tell the EMPLOYEE their draft was auto-submitted (email + in-app bell), so it
            // doesn't look like it vanished and they know it may roll to next month.
            $empEmail = $employee->user->work_email ?? $employee->user->email ?? null;
            if ($empEmail) {
                Mail::to($empEmail)->queue(new ClaimAutoSubmittedMail($claim, $employee, $manager?->full_name, $cutoffDay));
            }
            if ($employee->user) {
                $employee->user->notify(new ClaimAutoSubmittedNotification($claim, $manager?->full_name));
            }

            $submitted++;
        }

        $this->info("Auto-submit complete — submitted: {$submitted}, skipped (incomplete): {$skipped}.");

        return self::SUCCESS;
    }
}
