<?php

namespace App\Console\Commands;

use App\Mail\PendingLeaveReminderMail;
use App\Models\Employee;
use App\Models\LeaveApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeaveReminder extends Command
{
    protected $signature   = 'leave:remind-managers';
    protected $description = 'Send daily reminders to reporting managers who have pending leave requests awaiting approval';

    public function handle(): void
    {
        $this->info('Checking for pending leave requests...');

        // Get all managers who have direct reports with pending leave
        $managersWithPending = Employee::whereNull('active_until')
            ->whereHas('directReports', function ($q) {
                $q->whereHas('leaveApplications', fn($lq) => $lq->where('status', 'pending'));
            })
            ->with('user')
            ->get();

        if ($managersWithPending->isEmpty()) {
            $this->info('No pending leave requests found. No reminders sent.');
            return;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($managersWithPending as $manager) {
            $managerEmail = $manager->user?->work_email;

            if (!$managerEmail) {
                $this->warn("Skipping manager #{$manager->id} ({$manager->full_name}) — no work email.");
                $skipped++;
                continue;
            }

            // Get all pending leave applications from this manager's direct reports
            $directReportIds = Employee::where('manager_id', $manager->id)->pluck('id');
            $pendingApplications = LeaveApplication::with(['employee', 'leaveType'])
                ->whereIn('employee_id', $directReportIds)
                ->where('status', 'pending')
                ->orderBy('created_at')
                ->get();

            if ($pendingApplications->isEmpty()) {
                continue;
            }

            try {
                Mail::to($managerEmail)->send(new PendingLeaveReminderMail($manager, $pendingApplications));
                $sent++;
                $this->info("Sent reminder to {$manager->full_name} ({$managerEmail}) — {$pendingApplications->count()} pending request(s).");
            } catch (\Exception $e) {
                Log::warning("Failed to send leave reminder to manager #{$manager->id}: " . $e->getMessage());
                $this->error("Failed: {$manager->full_name} — {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info("Done. Sent: {$sent}, Skipped: {$skipped}");
    }
}
