<?php

namespace App\Console\Commands;

use App\Mail\ClaimReminderMail;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimPolicy;
use App\Services\ClaimRulesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ClaimDeadlineReminder extends Command
{
    protected $signature = 'claims:remind';

    protected $description = 'Send claim submission deadline reminders to employees with draft claims';

    public function handle(): int
    {
        $policy = ExpenseClaimPolicy::forCompany();
        $deadlineDay = $policy->submission_deadline_day ?? 20;
        $reminderDays = $policy->reminder_days_before ?? 3;

        $now = now();
        $currentMonth = $now->month;
        $currentYear = $now->year;
        // Working-day-aware deadline (rolls back off weekends/public holidays).
        $deadlineDate = ClaimRulesService::submissionDeadline($deadlineDay, $now);
        $reminderDate = $deadlineDate->copy()->subDays($reminderDays);

        // Only send reminders between the reminder date and the deadline
        if ($now->lt($reminderDate) || $now->gt($deadlineDate)) {
            $this->info('Not within reminder window. Skipping.');

            return self::SUCCESS;
        }

        // Find employees with draft claims this month
        $draftClaims = ExpenseClaim::where('year', $currentYear)
            ->where('month', $currentMonth)
            ->where('status', 'draft')
            ->where('item_count', '>', 0)
            ->with('employee.user')
            ->get();

        $sent = 0;
        foreach ($draftClaims as $claim) {
            $employee = $claim->employee;
            $email = $employee->user->work_email ?? $employee->user->email ?? null;

            if (! $email) {
                continue;
            }

            Mail::to($email)->queue(new ClaimReminderMail(
                $employee,
                $currentYear,
                $currentMonth,
                $deadlineDate->format('d M Y')
            ));
            $sent++;
        }

        $this->info("Sent {$sent} claim deadline reminder(s).");

        return self::SUCCESS;
    }
}
