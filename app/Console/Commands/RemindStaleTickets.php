<?php

namespace App\Console\Commands;

use App\Mail\TicketReminderMail;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Hourly cron — sends a reminder for any non-archived ticket that has had
 * no activity (status change, PIC change, or chat message) in 24+ hours.
 *
 * Recipients:
 *   - PIC if assigned
 *   - All active department managers if not assigned
 *
 * Each ticket is throttled to one reminder per 24h via tickets.last_reminder_sent_at.
 */
class RemindStaleTickets extends Command
{
    protected $signature   = 'tickets:remind-stale';
    protected $description = 'Email + bell-notify the PIC (or department managers) for tickets idle 24+ hours';

    public function handle(): void
    {
        $this->info('Scanning for stale tickets...');

        $threshold = now()->subHours(24);

        // Latest message timestamp per ticket — used to compute "last activity time"
        $latestMessageTimes = TicketMessage::selectRaw('ticket_id, MAX(created_at) as last_msg_at')
            ->groupBy('ticket_id')
            ->pluck('last_msg_at', 'ticket_id');

        $candidates = Ticket::with(['creator', 'assignee'])
            ->whereIn('status', Ticket::ACTIVE_STATUSES)
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_reminder_sent_at')
                  ->orWhere('last_reminder_sent_at', '<', $threshold);
            })
            ->get();

        $sent = 0;
        $skipped = 0;
        $autoPended = 0;

        foreach ($candidates as $ticket) {
            $lastMsgAt = $latestMessageTimes[$ticket->id] ?? null;
            $lastActivity = $this->latestOf([$ticket->updated_at, $lastMsgAt ? Carbon::parse($lastMsgAt) : null]);

            if (!$lastActivity || $lastActivity->gt($threshold)) {
                // Activity within 24h — not stale
                continue;
            }

            // Auto-transition Open → Pending for un-PIC'd tickets idle 24h+.
            // Done before the reminder send so the email/bell mentions the new status.
            if ($ticket->status === 'Open' && empty($ticket->assigned_to)) {
                $ticket->update(['status' => 'Pending']);
                $ticket->refresh();
                $autoPended++;
                $this->info("  {$ticket->ticket_number}: status Open → Pending (24h+ no PIC)");
            }

            $recipients = $this->resolveRecipients($ticket);
            $isUnassigned = is_null($ticket->assigned_to);

            // Unregistered managers (Employee rows whose User row doesn't exist
            // or is inactive) — email-only fallback. Only meaningful when no PIC
            // is assigned, because a PIC necessarily has a User account.
            $unregisteredManagers = $isUnassigned
                ? $ticket->unregisteredManagersForNotification()->get()
                : collect();

            if ($recipients->isEmpty() && $unregisteredManagers->isEmpty()) {
                $this->warn("  {$ticket->ticket_number}: no recipients (no PIC and no managers).");
                $skipped++;
                continue;
            }

            try {
                foreach ($recipients as $r) {
                    Mail::to($r->work_email)->queue(new TicketReminderMail($ticket, $r, $lastActivity, $isUnassigned));
                }
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new TicketReminderNotification($ticket, $lastActivity, $isUnassigned));
                }

                foreach ($unregisteredManagers as $emp) {
                    Mail::to($emp->company_email)->queue(new TicketReminderMail($ticket, $emp, $lastActivity, $isUnassigned));
                }

                $ticket->update(['last_reminder_sent_at' => now()]);
                $sent++;
                $totalRecipients = $recipients->count() + $unregisteredManagers->count();
                $this->info("  Reminded {$totalRecipients} recipient(s) for {$ticket->ticket_number} (idle " . (int) $lastActivity->diffInHours(now()) . 'h)');
            } catch (\Exception $e) {
                Log::warning("Ticket reminder failed for {$ticket->ticket_number}: " . $e->getMessage());
                $this->error("  Failed for {$ticket->ticket_number}: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info("Done. Sent: {$sent}, Skipped: {$skipped}, Auto-Pended: {$autoPended}");
    }

    /**
     * Resolve who should receive the reminder for this ticket.
     * - PIC assigned → just the PIC
     * - No PIC → department managers only (interns aren't expected to assign;
     *           they only get pinged when they're directly assigned)
     */
    private function resolveRecipients(Ticket $ticket)
    {
        if ($ticket->assigned_to) {
            $pic = $ticket->assignee;
            return $pic && $pic->is_active && $pic->work_email ? collect([$pic]) : collect();
        }

        return $ticket->managersForNotification()
            ->whereNotNull('work_email')
            ->get();
    }

    private function latestOf(array $times): ?Carbon
    {
        $valid = collect($times)->filter()->map(fn($t) => $t instanceof Carbon ? $t : Carbon::parse($t));
        return $valid->isEmpty() ? null : $valid->max();
    }
}
