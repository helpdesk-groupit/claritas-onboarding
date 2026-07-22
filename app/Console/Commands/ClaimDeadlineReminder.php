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
    /** Day of the month for the early mid-month nudge ("time to prepare your claims"). */
    private const MIDMONTH_NUDGE_DAY = 15;

    protected $signature = 'claims:remind {--force : Send now regardless of the day checks}';

    protected $description = 'Claim reminders: a mid-month nudge (15th), a day-before-deadline reminder, and a deadline-day last call.';

    public function handle(): int
    {
        $policy = ExpenseClaimPolicy::forCompany();
        $deadlineDay = $policy->submission_deadline_day ?? 20;

        $now = now();
        // EMPLOYEE deadline = HR cutoff minus the manager-approval buffer (working days),
        // so managers have time to approve before the HR cutoff.
        $deadlineDate = ClaimRulesService::employeeSubmissionDeadline($deadlineDay, $now);

        $isDeadlineDay = $now->isSameDay($deadlineDate);
        $isDayBefore = $now->isSameDay($deadlineDate->copy()->subDay());
        $isMidMonth = $now->day === self::MIDMONTH_NUDGE_DAY;

        if (! $this->option('force') && ! $isDeadlineDay && ! $isDayBefore && ! $isMidMonth) {
            $this->info('Not the mid-month ('.self::MIDMONTH_NUDGE_DAY.'th), day-before or deadline day ('.$deadlineDate->toDateString().'). Skipping.');

            return self::SUCCESS;
        }

        // Deadline day = urgent "last call" to draft-holders only. The mid-month nudge is the
        // early "prepare your claims" reminder. Day-before (or a forced run) = full reminder.
        // The three days never overlap on the calendar; the deadline window wins if --force
        // trips more than one.
        $lastCall = $isDeadlineDay && ! $isDayBefore;
        $midMonth = $isMidMonth && ! $isDeadlineDay && ! $isDayBefore;

        $year = $now->year;
        $month = $now->month;
        $deadlineStr = $deadlineDate->format('d M Y');

        $employees = Employee::whereNull('active_until')->with('user')->get();
        $sentDraft = 0;
        $sentNone = 0;
        $sentMid = 0;

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

            // Mid-month nudge goes to EVERY active employee regardless of status — a blanket
            // "prepare your claims" reminder. Draft-holders get their drafts listed; everyone
            // else gets the general prompt.
            if ($midMonth) {
                Mail::to($email)->queue(new ClaimReminderMail($employee, $year, $month, $deadlineStr, 'midmonth', $drafts));
                $sentMid++;

                continue;
            }

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

        $phase = $midMonth ? 'Mid-month nudge' : ($lastCall ? 'Deadline-day LAST CALL' : 'Day-before');
        $this->info("{$phase} reminders queued — drafts: {$sentDraft}, no-claim nudges: {$sentNone}, mid-month: {$sentMid}.");

        return self::SUCCESS;
    }
}
