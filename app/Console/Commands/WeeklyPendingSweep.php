<?php

namespace App\Console\Commands;

use App\Mail\WeeklyPendingSweepMail;
use App\Models\Aarf;
use App\Models\Employee;
use App\Models\EmployeeEditLog;
use App\Models\ExpenseClaim;
use App\Models\LeaveApplication;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WeeklyPendingSweep extends Command
{
    protected $signature = 'sweep:pending-weekly';

    protected $description = 'Weekly sweep of all pending acknowledgements and approvals — sends reminders to responsible parties';

    public function handle(): int
    {
        $this->info('Starting weekly pending sweep...');

        $sent = 0;
        $skipped = 0;

        // ── 1. Employee profile consent — remind the employee ────────────
        $pendingConsents = EmployeeEditLog::whereNull('acknowledged_at')
            ->where('consent_required', true)
            ->whereNotNull('consent_token')
            ->with('employee.user')
            ->get();

        foreach ($pendingConsents as $log) {
            $email = $log->consent_sent_to_email
                ?? $log->employee?->user?->work_email;

            if (! $email) {
                $this->warn("Skipping consent log #{$log->id} — no email.");
                $skipped++;

                continue;
            }

            $name = $log->employee?->preferred_name
                ?? $log->employee?->full_name
                ?? 'Employee';

            try {
                Mail::to($email)->queue(new WeeklyPendingSweepMail(
                    recipientName: $name,
                    type: 'consent',
                    items: collect([$log]),
                ));
                $sent++;
                $this->info("Consent reminder → {$name} ({$email})");
            } catch (\Exception $e) {
                Log::warning("Weekly sweep: consent reminder failed for log #{$log->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        // ── 2. AARF — remind the employee (unacknowledged) ───────────────
        // Only chase employees who (a) are still active (active_until IS NULL)
        // and (b) currently hold at least one asset (an asset_assignments row
        // with status 'assigned'). A resigned employee who has returned their
        // asset has no 'assigned' rows left, so the weekly nag stops on its
        // own — they have nothing left to acknowledge.
        $pendingAarfEmployee = Aarf::where('acknowledged', false)
            ->whereHas('employee', function ($e) {
                $e->whereNull('active_until')
                    ->whereHas('assetAssignments', function ($a) {
                        $a->where('status', 'assigned');
                    });
            })
            ->with('employee.user')
            ->get();

        foreach ($pendingAarfEmployee as $aarf) {
            $email = $aarf->employee?->user?->work_email;

            if (! $email) {
                $skipped++;

                continue;
            }

            $name = $aarf->employee?->preferred_name
                ?? $aarf->employee?->full_name
                ?? 'Employee';

            try {
                Mail::to($email)->queue(new WeeklyPendingSweepMail(
                    recipientName: $name,
                    type: 'aarf_employee',
                    items: collect([$aarf]),
                ));
                $sent++;
                $this->info("AARF employee reminder → {$name} ({$email}) — {$aarf->aarf_reference}");
            } catch (\Exception $e) {
                Log::warning("Weekly sweep: AARF employee reminder failed for #{$aarf->id}: {$e->getMessage()}");
                $skipped++;
            }
        }

        // ── 3. AARF — notify IT managers (pending employee acknowledgements) ─
        // Same exclusion as the employee reminder: drop AARFs whose employee
        // has offboarded or no longer holds an asset. Onboarding-stage AARFs
        // (employee_id still NULL — asset link runs through onboarding_id) are
        // kept ONLY when the onboarding actually has an assigned asset. An AARF
        // row is created for EVERY onboarding regardless of assets, so without
        // this guard an asset-less onboarding form (nothing to acknowledge)
        // would clutter the IT reminder — often with a blank employee name.
        $pendingAarfIt = Aarf::where('acknowledged', false)
            ->where(function ($q) {
                $q->where(function ($n) {
                    $n->whereNull('employee_id')
                        ->whereHas('onboarding.assetAssignments', function ($a) {
                            $a->where('status', 'assigned');
                        });
                })
                    ->orWhereHas('employee', function ($e) {
                        $e->whereNull('active_until')
                            ->whereHas('assetAssignments', function ($a) {
                                $a->where('status', 'assigned');
                            });
                    });
            })
            ->with('employee')
            ->get();

        if ($pendingAarfIt->isNotEmpty()) {
            $itManagers = User::whereIn('role', ['it_manager', 'superadmin'])
                ->whereNotNull('work_email')
                ->get();

            foreach ($itManagers as $itUser) {
                try {
                    Mail::to($itUser->work_email)->queue(new WeeklyPendingSweepMail(
                        recipientName: $itUser->name ?? 'IT Manager',
                        type: 'aarf_it',
                        items: $pendingAarfIt,
                    ));
                    $sent++;
                    $this->info("AARF IT reminder → {$itUser->name} ({$itUser->work_email}) — {$pendingAarfIt->count()} form(s)");
                } catch (\Exception $e) {
                    Log::warning("Weekly sweep: AARF IT reminder failed for user #{$itUser->id}: {$e->getMessage()}");
                    $skipped++;
                }
            }
        }

        // ── 4. Leave approvals — remind managers ─────────────────────────
        $pendingLeave = LeaveApplication::where('status', 'pending')
            ->with(['employee', 'leaveType'])
            ->get();

        $leaveByManager = $pendingLeave->groupBy(fn ($app) => $app->employee?->manager_id);

        foreach ($leaveByManager as $managerId => $apps) {
            if (! $managerId) {
                $skipped += $apps->count();

                continue;
            }

            $manager = Employee::with('user')->find($managerId);
            $email = $manager?->user?->work_email;

            if (! $email) {
                $skipped++;

                continue;
            }

            $name = $manager->preferred_name ?? $manager->full_name ?? 'Manager';

            try {
                Mail::to($email)->queue(new WeeklyPendingSweepMail(
                    recipientName: $name,
                    type: 'leave',
                    items: $apps,
                ));
                $sent++;
                $this->info("Leave reminder → {$name} ({$email}) — {$apps->count()} request(s)");
            } catch (\Exception $e) {
                Log::warning("Weekly sweep: leave reminder failed for manager #{$managerId}: {$e->getMessage()}");
                $skipped++;
            }
        }

        // ── 5. Expense claims — remind managers (submitted) ──────────────
        $pendingClaims = ExpenseClaim::where('status', 'submitted')
            ->with(['employee', 'manager.user'])
            ->get();

        $claimsByManager = $pendingClaims->groupBy('manager_id');

        foreach ($claimsByManager as $managerId => $claims) {
            if (! $managerId) {
                $skipped += $claims->count();

                continue;
            }

            $manager = $claims->first()->manager;
            $email = $manager?->user?->work_email;

            if (! $email) {
                $skipped++;

                continue;
            }

            $name = $manager->preferred_name ?? $manager->full_name ?? 'Manager';

            try {
                Mail::to($email)->queue(new WeeklyPendingSweepMail(
                    recipientName: $name,
                    type: 'claims_manager',
                    items: $claims,
                ));
                $sent++;
                $this->info("Claims (manager) reminder → {$name} ({$email}) — {$claims->count()} claim(s)");
            } catch (\Exception $e) {
                Log::warning("Weekly sweep: claims manager reminder failed for #{$managerId}: {$e->getMessage()}");
                $skipped++;
            }
        }

        // ── 6. Expense claims — remind HR (manager_approved) ─────────────
        $hrPendingClaims = ExpenseClaim::where('status', 'manager_approved')
            ->with('employee')
            ->get();

        if ($hrPendingClaims->isNotEmpty()) {
            // HR approvers (incl. HR Executives) + superadmin — see User::scopeClaimHrRole.
            $hrUsers = User::claimHrRole()
                ->whereNotNull('work_email')
                ->get();

            foreach ($hrUsers as $hrUser) {
                try {
                    Mail::to($hrUser->work_email)->queue(new WeeklyPendingSweepMail(
                        recipientName: $hrUser->name ?? 'HR Manager',
                        type: 'claims_hr',
                        items: $hrPendingClaims,
                    ));
                    $sent++;
                    $this->info("Claims (HR) reminder → {$hrUser->name} ({$hrUser->work_email}) — {$hrPendingClaims->count()} claim(s)");
                } catch (\Exception $e) {
                    Log::warning("Weekly sweep: claims HR reminder failed for user #{$hrUser->id}: {$e->getMessage()}");
                    $skipped++;
                }
            }
        }

        $this->info("Weekly sweep complete. Sent: {$sent}, Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
