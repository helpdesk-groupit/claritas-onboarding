<?php

namespace App\Console\Commands;

use App\Mail\SecurityAuditMail;
use App\Models\SecurityAuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SecurityAuditReport extends Command
{
    protected $signature   = 'security:audit-report';
    protected $description = 'Check for security events in the last hour and email IT team if any found.';

    public function handle(): void
    {
        $since = now()->subHour();

        $events = SecurityAuditLog::where('emailed', false)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        if ($events->isEmpty()) {
            return;
        }

        // Recipients: IT Manager, IT Executive, IT Intern
        $recipients = User::whereIn('role', ['it_manager', 'it_executive', 'it_intern'])
            ->where('is_active', true)
            ->pluck('work_email')
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            Log::warning('SecurityAuditReport: no active IT staff found to receive alert.');
            return;
        }

        $periodLabel = $since->setTimezone('Asia/Kuala_Lumpur')->format('d M Y H:i')
            . ' – ' . now()->setTimezone('Asia/Kuala_Lumpur')->format('H:i') . ' MYT';

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new SecurityAuditMail($events, $periodLabel));
            } catch (\Throwable $e) {
                Log::warning("SecurityAuditReport: failed to send to {$email}: " . $e->getMessage());
            }
        }

        // Mark all sent events so they are not re-sent on the next run
        SecurityAuditLog::whereIn('id', $events->pluck('id'))->update(['emailed' => true]);

        $this->info("Security audit report sent to {$recipients->count()} recipient(s) — {$events->count()} event(s) reported.");
    }
}
