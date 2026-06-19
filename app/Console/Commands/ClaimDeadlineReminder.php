<?php

namespace App\Console\Commands;

use App\Mail\ClaimReminderMail;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimPolicy;
use App\Services\ClaimRulesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ClaimDeadlineReminder extends Command
{
    protected $signature = 'claims:remind {--force : Send now regardless of the day-before check}';

    protected $description = 'Day before the submission deadline: remind every employee to submit drafts, or nudge those with no claim this month.';

    public function handle(): int
    {
        $policy = ExpenseClaimPolicy::forCompany();
        $deadlineDay = $policy->submission_deadline_day ?? 20;

        $now = now();
        // Working-day-aware deadline (rolls back off weekends / public holidays).
        $deadlineDate = ClaimRulesService::submissionDeadline($deadlineDay, $now);

        $isDeadlineDay = $now->isSameDay($deadlineDate);
        $isDayBefore = $now->isSameDay($deadlineDate->copy()->subDay());

        if (! $this->option('force') && ! $isDeadlineDay && ! $isDayBefore) {
            $this->info('Not the day-before or deadline day ('.$deadlineDate->toDateString().'). Skipping.');

            return self::SUCCESS;
        }

        // Deadline day = urgent "last call" to draft-holders only. Day-before (or a
        // forced run on any other day) = the full reminder to everyone.
        $lastCall = $isDeadlineDay && ! $isDayBefore;

        $year = $now->year;
        $month = $now->month;
        $deadlineStr = $deadlineDate->format('d M Y');

        $employees = Employee::whereNull('active_until')->with('user')->get();
        $sentDraft = 0;
        $sentNone = 0;

        foreach ($employees as $employee) {
            $email = $employee->user->work_email ?? $employee->user->email ?? null;
            if (! $email) {
                continue;
            }

            // Open, editable drafts that have items (need submitting) — any period.
            $drafts = ExpenseClaim::where('employee_id', $employee->id)
                ->whereIn('status', ['draft', 'manager_rejected', 'hr_rejected'])
                ->where('item_count', '>', 0)
                ->orderByDesc('created_at')
                ->get();

            if ($drafts->isNotEmpty()) {
                $type = $lastCall ? 'lastcall' : 'draft';
                Mail::to($email)->queue(new ClaimReminderMail($employee, $year, $month, $deadlineStr, $type, $drafts));
                $sentDraft++;

                continue;
            }

            // The "no claim this month?" nudge is only for the day-before run — on the
            // deadline day itself, only people with drafts get the last call.
            if ($lastCall) {
                continue;
            }

            $hasThisMonth = ExpenseClaim::where('employee_id', $employee->id)
                ->where('year', $year)->where('month', $month)
                ->where('status', '!=', 'draft')
                ->exists();

            if (! $hasThisMonth) {
                Mail::to($email)->queue(new ClaimReminderMail($employee, $year, $month, $deadlineStr, 'none'));
                $sentNone++;
            }
        }

        $phase = $lastCall ? 'Deadline-day LAST CALL' : 'Day-before';
        $this->info("{$phase} reminders queued — drafts: {$sentDraft}, no-claim nudges: {$sentNone}.");

        return self::SUCCESS;
    }
}
