<?php

namespace App\Console\Commands;

use App\Models\Offboarding;
use App\Models\User;
use App\Mail\OffboardingNoticeMail;
use App\Mail\OffboardingReminderMail;
use App\Mail\OffboardingWeekReminderMail;
use App\Mail\OffboardingSendoffMail;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class OffboardingNotifications extends Command
{
    protected $signature   = 'offboarding:notify';
    protected $description = 'Send scheduled offboarding notification emails (1-month notice, 1-week reminder, 3-day reminder, sendoff)';

    public function handle(): void
    {
        $today    = Carbon::today();
        $oneMonth = $today->copy()->addMonth();
        $oneWeek  = $today->copy()->addDays(7);
        $threeDays = $today->copy()->addDays(3);

        $this->info("Running offboarding notifications for: {$today->toDateString()}");

        // Gather all active HR and IT user emails for CC/team notifications
        $hrEmails = User::whereIn('role', ['hr_manager','hr_executive','hr_intern'])
            ->where('is_active', true)->pluck('work_email')->filter()->unique()->values()->toArray();
        $itEmails = User::whereIn('role', ['it_manager','it_executive','it_intern'])
            ->where('is_active', true)->pluck('work_email')->filter()->unique()->values()->toArray();
        $teamEmails = array_values(array_unique(array_merge($hrEmails, $itEmails)));

        // Helper: build CC list including reporting manager for a given offboarding record
        $buildCc = function (Offboarding $ob) use ($teamEmails): array {
            $cc = $teamEmails;
            if ($ob->reporting_manager_email) {
                $cc = array_values(array_unique(array_merge($cc, [$ob->reporting_manager_email])));
            }
            return $cc;
        };

        // ── 1. ONE-MONTH NOTICE ─────────────────────────────────────────────
        $noticeRecords = Offboarding::with('employee')
            ->where('notice_email_status', 'pending')
            ->whereNotNull('exit_date')
            ->where(function ($q) use ($today, $oneMonth) {
                $q->whereDate('exit_date', $oneMonth)
                  ->orWhere(function ($q2) use ($today, $oneMonth) {
                      $q2->whereDate('exit_date', '>', $today)
                         ->whereDate('exit_date', '<', $oneMonth);
                  });
            })
            ->get();

        $this->info("1-month notices to send: {$noticeRecords->count()}");

        foreach ($noticeRecords as $ob) {
            try {
                $cc   = $buildCc($ob);
                $sent = false;

                if ($ob->company_email) {
                    Mail::to($ob->company_email)->cc($cc)
                        ->send(new OffboardingNoticeMail($ob, 'employee'));
                    $sent = true;
                }

                if (!empty($cc)) {
                    $icsContent = $this->buildIcs($ob);
                    Mail::to($cc[0])->cc(array_slice($cc, 1))
                        ->send(
                            (new OffboardingNoticeMail($ob, 'team'))
                                ->attachData($icsContent, 'offboarding-reminder.ics', ['mime' => 'text/calendar'])
                        );
                    $sent = true;
                }

                if ($sent) {
                    $ob->update(['notice_email_status' => 'sent', 'calendar_reminder_status' => 'sent']);
                    $this->info("  Notice sent for: {$ob->full_name}");
                }
            } catch (\Throwable $e) {
                $ob->update(['notice_email_status' => 'failed']);
                Log::error("Offboarding notice failed for #{$ob->id}: " . $e->getMessage());
                $this->error("  FAILED for: {$ob->full_name} — " . $e->getMessage());
            }
        }

        // ── 2. ONE-WEEK REMINDER ────────────────────────────────────────────
        $weekRecords = Offboarding::with('employee')
            ->whereDate('exit_date', $oneWeek)
            ->where('week_reminder_email_status', 'pending')
            ->get();

        $this->info("1-week reminders to send: {$weekRecords->count()}");

        foreach ($weekRecords as $ob) {
            try {
                if (!$ob->company_email) {
                    $ob->update(['week_reminder_email_status' => 'failed']);
                    continue;
                }
                $cc = $buildCc($ob);
                Mail::to($ob->company_email)->cc($cc)
                    ->send(new OffboardingWeekReminderMail($ob));
                $ob->update(['week_reminder_email_status' => 'sent']);
                $this->info("  1-week reminder sent for: {$ob->full_name}");
            } catch (\Throwable $e) {
                $ob->update(['week_reminder_email_status' => 'failed']);
                Log::error("Offboarding week reminder failed for #{$ob->id}: " . $e->getMessage());
                $this->error("  FAILED for: {$ob->full_name} — " . $e->getMessage());
            }
        }

        // ── 3. THREE-DAY REMINDER ───────────────────────────────────────────
        $reminderRecords = Offboarding::with('employee')
            ->whereDate('exit_date', $threeDays)
            ->where('reminder_email_status', 'pending')
            ->get();

        $this->info("3-day reminders to send: {$reminderRecords->count()}");

        foreach ($reminderRecords as $ob) {
            try {
                if (!$ob->company_email) {
                    $ob->update(['reminder_email_status' => 'failed']);
                    continue;
                }
                $cc = $buildCc($ob);
                Mail::to($ob->company_email)->cc($cc)
                    ->send(new OffboardingReminderMail($ob));
                $ob->update(['reminder_email_status' => 'sent', 'exiting_email_status' => 'sent']);
                $this->info("  3-day reminder sent for: {$ob->full_name}");
            } catch (\Throwable $e) {
                $ob->update(['reminder_email_status' => 'failed']);
                Log::error("Offboarding reminder failed for #{$ob->id}: " . $e->getMessage());
                $this->error("  FAILED for: {$ob->full_name} — " . $e->getMessage());
            }
        }

        // ── 4. SENDOFF EMAIL ────────────────────────────────────────────────
        // New logic: send to employee (work + personal email) only after all
        // assigned assets have been released.
        //
        // - If exit_date = today and no assets assigned → send today (immediate).
        // - If exit_date = today but assets still assigned → hold (stay pending).
        // - If exit_date has passed and assets are now released → send now.
        //
        // Only the recipients change (employee emails only, no CC to team).
        // All other email blocks are untouched.

        $sendoffRecords = Offboarding::with('employee')
            ->whereDate('exit_date', '<=', $today)
            ->where('sendoff_email_status', 'pending')
            ->get();

        $this->info("Sendoff candidates (exit date reached, pending): {$sendoffRecords->count()}");

        foreach ($sendoffRecords as $ob) {
            try {
                // Check whether this employee still has assets assigned.
                $hasAssets = false;
                if ($ob->employee_id) {
                    $hasAssets = \App\Models\AssetInventory::where('assigned_employee_id', $ob->employee_id)
                        ->whereIn('status', ['assigned', 'unavailable'])
                        ->exists();
                }

                if ($hasAssets) {
                    // Assets not yet returned — hold sendoff until they are released.
                    $this->line("  Holding sendoff for {$ob->full_name} — assets still assigned.");
                    continue;
                }

                // No assets remaining — safe to send.
                $recipients = array_values(array_filter(array_unique([
                    $ob->company_email,
                    $ob->personal_email,
                ])));

                if (empty($recipients)) {
                    $ob->update(['sendoff_email_status' => 'failed']);
                    $this->error("  No recipient email for: {$ob->full_name} — marked failed.");
                    continue;
                }

                $mailer = Mail::to($recipients[0]);
                $extraTo = array_slice($recipients, 1);
                if (!empty($extraTo)) {
                    $mailer = $mailer->cc($extraTo);
                }

                $mailer->send(new OffboardingSendoffMail($ob));
                $ob->update(['sendoff_email_status' => 'sent', 'aarf_status' => 'done']);
                $this->info("  Sendoff sent for: {$ob->full_name}");
            } catch (\Throwable $e) {
                $ob->update(['sendoff_email_status' => 'failed']);
                Log::error("Offboarding sendoff failed for #{$ob->id}: " . $e->getMessage());
                $this->error("  FAILED for: {$ob->full_name} — " . $e->getMessage());
            }
        }

        $this->info("Done.");
    }

    /**
     * Build a calendar ICS reminder for HR/IT teams about the exit date.
     * Public so it can be called from EmployeeController for immediate short-notice sends.
     */
    public function buildIcsPublic(Offboarding $ob): string
    {
        return $this->buildIcs($ob);
    }

    private function buildIcs(Offboarding $ob): string
    {
        $exitDate  = $ob->exit_date?->format('Ymd') ?? date('Ymd');
        $dtStamp   = gmdate('Ymd\THis\Z');
        $uid       = 'claritas-offboarding-' . $ob->id . '@claritas.asia';
        $name      = $ob->full_name ?? 'Employee';
        $dept      = $ob->department ?? '—';
        $desig     = $ob->designation ?? '—';
        $company   = $ob->company ?? 'Claritas Asia Sdn. Bhd.';
        $email     = $ob->company_email ?? '—';

        $desc = implode('\n', [
            "OFFBOARDING DETAILS",
            "===================",
            "Employee: {$name}",
            "Designation: {$desig}",
            "Department: {$dept}",
            "Company: {$company}",
            "Company Email: {$email}",
            "Exit Date: " . ($ob->exit_date?->format('d M Y') ?? '—'),
            " ",
            "ACTION REQUIRED: Complete offboarding checklist before exit date.",
            "- Deactivate accounts and system access",
            "- Retrieve all company assets",
            "- Revoke building/VPN access",
            "- Prepare final AARF",
        ]);

        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Claritas Asia//Employee Portal//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTAMP:{$dtStamp}",
            "DTSTART;VALUE=DATE:{$exitDate}",
            "DTEND;VALUE=DATE:{$exitDate}",
            "SUMMARY:Employee Exit — {$name}",
            "DESCRIPTION:{$desc}",
            'STATUS:CONFIRMED',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }
}