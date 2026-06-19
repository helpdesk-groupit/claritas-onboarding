<?php

namespace App\Console\Commands;

use App\Mail\ClaimApprovalReminderMail;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimPolicy;
use App\Services\ClaimRulesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ClaimApprovalReminder extends Command
{
    protected $signature = 'claims:remind-approvers {--force : Send now regardless of the date window}';

    protected $description = 'Remind approving managers to approve pending claims before the HR cutoff (so they reach HR in time).';

    public function handle(): int
    {
        $policy = ExpenseClaimPolicy::forCompany();
        $cutoffDay = $policy->submission_deadline_day ?? 20;

        $now = now();
        $cutoff = ClaimRulesService::submissionDeadline($cutoffDay, $now);          // HR cutoff
        $headsUp = ClaimRulesService::employeeSubmissionDeadline($cutoffDay, $now, 2); // 2 working days before

        $isCutoff = $now->isSameDay($cutoff);
        $isHeadsUp = $now->isSameDay($headsUp);

        if (! $this->option('force') && ! $isCutoff && ! $isHeadsUp) {
            $this->info('Not a manager-reminder day (heads-up '.$headsUp->toDateString().' / cutoff '.$cutoff->toDateString().'). Skipping.');

            return self::SUCCESS;
        }

        $lastCall = $isCutoff && ! $isHeadsUp;
        $cutoffStr = $cutoff->format('d M Y');

        // Submitted claims with items still pending a manager decision.
        $pending = ExpenseClaim::where('status', 'submitted')
            ->whereHas('items', fn ($q) => $q->where('manager_status', 'pending'))
            ->with(['employee', 'items'])
            ->get();

        // Group the claims by each approving manager.
        $byApprover = [];
        foreach ($pending as $claim) {
            foreach ($claim->items->where('manager_status', 'pending')->pluck('approver_id')->filter()->unique() as $aid) {
                $byApprover[$aid][$claim->id] = $claim;
            }
        }

        $sent = 0;
        foreach ($byApprover as $aid => $claims) {
            $manager = Employee::with('user')->find($aid);
            $email = $manager?->user?->work_email ?? $manager?->user?->email ?? null;
            if (! $email) {
                continue;
            }
            Mail::to($email)->queue(new ClaimApprovalReminderMail($manager, collect($claims)->values(), $cutoffStr, $lastCall));
            $sent++;
        }

        $phase = $lastCall ? 'CUTOFF-DAY last call' : 'Heads-up';
        $this->info("{$phase} manager approval reminders queued: {$sent}.");

        return self::SUCCESS;
    }
}
