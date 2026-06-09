<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Onboarding;
use App\Models\Employee;
use App\Models\Offboarding;
use App\Http\Controllers\OnboardingController;
use Carbon\Carbon;

class ActivateEmployees extends Command
{
    protected $signature   = 'employees:activate';
    protected $description = 'Activate employees on start_date and offboard on exit_date';

    public function handle(): void
    {
        $today = Carbon::today();
        $this->info("Running employee lifecycle check for: {$today->toDateString()}");

        $controller = app(OnboardingController::class);

        // ── 1. WELCOME EMAIL: send to any onboarding whose start_date == today
        //       and welcome_email_sent is still false (catches new + existing records)
        $toWelcome = Onboarding::with(['personalDetail', 'workDetail', 'employee'])
            ->where('welcome_email_sent', false)
            ->whereHas('workDetail', fn($q) => $q->whereDate('start_date', $today))
            ->get();

        $this->info("Found {$toWelcome->count()} onboarding(s) needing welcome email today.");

        $activated = 0;

        foreach ($toWelcome as $ob) {
            $startDate = $ob->workDetail?->start_date?->toDateString();
            if (!$startDate) continue;

            // Create or update the employee record
            $employee = Employee::firstOrCreate(
                ['onboarding_id' => $ob->id],
                ['active_from'   => $startDate]
            );

            if ($employee->wasRecentlyCreated || empty($employee->full_name)) {
                $employee->populateFromOnboarding();
                $activated++;
            }

            // ── Link the active Employee row to its login account ──────────────
            // Without this, $user->employee resolves to null and the portal
            // breaks (can't raise tickets, can't save profile). All matching is
            // resilient to a missing company_email — common for a rehire before
            // their new email is assigned — by falling back to identity keys
            // (NRIC, then personal email) against a prior linked tenure.
            if (empty($employee->user_id)) {
                $linkUserId = null;

                // 1. New hire who registered: their User exists, keyed by company email.
                if ($employee->company_email) {
                    $linkUserId = \App\Models\User::where('work_email', $employee->company_email)->value('id');
                }
                // 2. Rehire: borrow the user_id from a prior tenure of the same person.
                if (!$linkUserId && $employee->official_document_id) {
                    $linkUserId = Employee::where('id', '!=', $employee->id)
                        ->whereNotNull('user_id')
                        ->where('official_document_id', $employee->official_document_id)
                        ->value('user_id');
                }
                if (!$linkUserId && $employee->personal_email) {
                    $linkUserId = Employee::where('id', '!=', $employee->id)
                        ->whereNotNull('user_id')
                        ->where('personal_email', $employee->personal_email)
                        ->value('user_id');
                }

                if ($linkUserId) {
                    $employee->update(['user_id' => $linkUserId]);
                    $this->info("  Linked Employee #{$employee->id} → User #{$linkUserId} ({$employee->full_name})");
                }
            }

            // ── Reactivate a returning employee's dormant login account ─────────
            // When an offboarded employee rejoins, the user row from their prior
            // tenure is still is_active=false (set by the offboard branch below).
            // Flip it back on so they can log in from day one. Keyed off the
            // resolved user_id (not company_email), so it works even when the new
            // company email hasn't been assigned yet. Guarded by the presence of a
            // prior OFFBOARDED tenure so we never auto-unlock a deliberately locked
            // brand-new account. Past offboarding records are left untouched.
            if ($employee->user_id) {
                $account = \App\Models\User::find($employee->user_id);
                if ($account && !$account->is_active) {
                    $hasPriorOffboarded = Employee::where('id', '!=', $employee->id)
                        ->where('user_id', $account->id)
                        ->whereNotNull('active_until')
                        ->exists();
                    if ($hasPriorOffboarded) {
                        $account->update([
                            'is_active'           => true,
                            'login_attempts'      => 0,
                            'deactivation_reason' => null,
                            'deactivated_at'      => null,
                        ]);
                        $this->info("  Reactivated user account for rehire: {$employee->full_name}");
                    }
                }
            }

            // Send welcome email — this is the primary purpose of the daily run
            $sent = $controller->sendWelcomeEmail($ob);
            $this->info('  Welcome email ' . ($sent ? 'SENT ✓' : 'FAILED ✗') . ' → ' . ($ob->personalDetail?->full_name ?? 'Unknown') . ' (' . ($ob->workDetail?->company_email ?? $ob->personalDetail?->personal_email ?? 'no email') . ')');

            // If exit_date is also set, create offboarding record
            if ($ob->workDetail?->exit_date) {
                Offboarding::createFromEmployee($employee);
            }

            if ($activated > 0) {
                $this->info("  Employee record created/populated: {$ob->personalDetail?->full_name} (Onboarding #{$ob->id})");
            }
        }

        // ── 2. OFFBOARD: employees whose exit_date == today, at 23:59 only ──
        // We only run this block when the clock is at or past 23:59 so that
        // employees remain visible in the system until the very end of their exit day.
        $now         = Carbon::now();
        $offboarded  = 0;

        if ($now->format('H:i') < '23:59') {
            $this->info('Offboard check skipped — will run at 23:59.');
        } else {
            $exiting = Employee::whereNotNull('exit_date')
                ->whereDate('exit_date', $today)
                ->whereNull('active_until')
                ->get();

            foreach ($exiting as $emp) {
                // Ensure offboarding record exists
                Offboarding::createFromEmployee($emp);

                // Mark employee as offboarded — removes them from employee listing (active_until set)
                $emp->update([
                    'active_until'      => $today,
                    'employment_status' => in_array($emp->employment_status, ['resigned', 'terminated', 'contract_ended'])
                        ? $emp->employment_status
                        : 'resigned',
                ]);

                // Deactivate the linked user account so they cannot login from today
                if ($emp->user_id) {
                    \App\Models\User::where('id', $emp->user_id)->update(['is_active' => false]);
                    $this->info("  Deactivated user account for: {$emp->full_name}");
                }

                $this->info("  Offboarded: {$emp->full_name} (exit: {$today->toDateString()})");
                $offboarded++;
            }
        } // end 23:59 offboard block

        $this->info("Done. Welcome emails attempted: {$toWelcome->count()}, New employee records: {$activated}, Offboarded: {$offboarded}.");
    }
}