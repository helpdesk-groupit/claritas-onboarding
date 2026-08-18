<?php

namespace App\Console\Commands;

use App\Mail\ClaimApprovalReminderMail;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimPolicy;
use App\Services\ClaimRulesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
        //
        // A claim whose pending items carry NO approver_id, or whose approver has no reachable
        // account, cannot be reminded to anybody — and used to be dropped here in silence by a
        // bare ->filter(). That is how EC-2026-03-0001 sat "submitted" from April to August
        // with nothing chasing it: submit() now guarantees an eligible approver, so these are
        // legacy or edge-case rows, but an unroutable claim is exactly the kind of stuck work
        // that must be said out loud rather than skipped.
        $byApprover = [];
        $unroutable = [];
        foreach ($pending as $claim) {
            $pendingItems = $claim->items->where('manager_status', 'pending');
            $approverIds = $pendingItems->pluck('approver_id')->filter()->unique();

            if ($approverIds->isEmpty()) {
                $unroutable[$claim->id] = $claim->claim_number.' ('.($claim->employee->full_name ?? 'unknown employee').') — no approving manager on the claim';

                continue;
            }
            foreach ($approverIds as $aid) {
                $byApprover[$aid][$claim->id] = $claim;
            }
        }

        $sent = 0;
        foreach ($byApprover as $aid => $claims) {
            $manager = Employee::with('user')->find($aid);
            $email = $manager?->user?->work_email ?? $manager?->user?->email ?? null;
            if (! $email) {
                foreach ($claims as $claim) {
                    $unroutable[$claim->id] = $claim->claim_number.' ('.($claim->employee->full_name ?? 'unknown employee').')'
                        .' — approver '.($manager->full_name ?? '#'.$aid).' has no reachable email account';
                }

                continue;
            }
            Mail::to($email)->queue(new ClaimApprovalReminderMail($manager, collect($claims)->values(), $cutoffStr, $lastCall));
            $sent++;
        }

        $phase = $lastCall ? 'CUTOFF-DAY last call' : 'Heads-up';
        $this->info("{$phase} manager approval reminders queued: {$sent}.");

        // A claim nobody can be reminded about will otherwise sit forever, so make some noise:
        // the console line is for a human running this by hand, the warning log is for the one
        // who is not watching. Deliberately NOT an exception — the reminders that CAN be sent
        // have been sent, and failing the command would only hide that.
        if ($unroutable !== []) {
            $this->warn('Unroutable claims (nobody can be reminded) — '.count($unroutable).':');
            foreach ($unroutable as $line) {
                $this->warn('  - '.$line);
            }
            Log::warning('Claim approval reminder: claims with no reachable approver', [
                'count' => count($unroutable),
                'claims' => array_values($unroutable),
            ]);
        }

        return self::SUCCESS;
    }
}
