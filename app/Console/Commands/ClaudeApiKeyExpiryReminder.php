<?php

namespace App\Console\Commands;

use App\Mail\ClaudeApiKeyExpiringMail;
use App\Models\ClaudeApiKeyHistory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Chases the Anthropic API key's company-policy expiry (see
 * ClaudeApiKeyHistory::expiresAt() / config('claude.key_expiry')). Daily,
 * self-gating: a threshold fires once daysLeft drops to or past it and hasn't
 * already been sent for the CURRENT key — so a scheduler outage on the exact
 * day still catches up on the next run instead of going silent, and re-running
 * the same day is a no-op. Rotating the key opens a fresh ClaudeApiKeyHistory
 * row (ClaudeApiKeyHistory::rotate()), so a new key automatically starts its
 * own reminder cycle with nothing extra to configure.
 */
class ClaudeApiKeyExpiryReminder extends Command
{
    protected $signature = 'claude-api:remind-key-expiry {--force : Resend any due milestone even if already recorded}';

    protected $description = 'Email Group IT (+ named recipients) as the Claude API key approaches its company-policy expiry.';

    public function handle(): int
    {
        $current = ClaudeApiKeyHistory::current();

        if (! $current) {
            $this->info('No Claude API key has ever been set — nothing to remind about.');

            return self::SUCCESS;
        }

        $daysLeft = $current->daysUntilExpiry();
        $thresholds = config('claude.key_expiry.remind_before_days', [7, 3, 0]);
        $force = (bool) $this->option('force');
        $sent = $current->remindersSent();

        $recipients = $this->recipients();
        if (empty($recipients)) {
            Log::warning('Claude API key expiry reminder: no recipients resolved (no IT role, no superadmin, no extra recipients configured).');
            $this->warn('No recipients resolved — nothing sent.');

            return self::SUCCESS;
        }

        $fired = [];

        foreach ($thresholds as $threshold) {
            $already = in_array($threshold, $sent, true);
            $due = $daysLeft <= $threshold;

            if (! $due || ($already && ! $force)) {
                continue;
            }

            try {
                Mail::to($recipients)->send(new ClaudeApiKeyExpiringMail($current, $daysLeft));
                $current->markReminderSent($threshold);
                $fired[] = $threshold;
            } catch (\Throwable $e) {
                Log::error('Claude API key expiry reminder failed to send: '.$e->getMessage());
            }
        }

        if (empty($fired)) {
            $this->info("No milestone due today (days left: {$daysLeft}).");
        } else {
            $this->info('Sent milestone(s) '.implode(', ', $fired).' to '.count($recipients).' recipient(s) (days left: '.$daysLeft.').');
        }

        return self::SUCCESS;
    }

    /**
     * Group IT (IT Manager + IT Executive, via the existing itEmailRecipients()
     * role resolution — active users, work email only, superadmin fallback if no
     * IT manager exists) plus named individuals from config who aren't reached by
     * an IT role. Flattened into one list: this is a small operational alert, not
     * a ranked report, so no to/cc distinction is needed.
     */
    private function recipients(): array
    {
        $it = User::itEmailRecipients();
        $extra = config('claude.key_expiry.extra_recipients', []);

        return collect(array_merge($it['to'], $it['cc'], $extra))
            ->filter()
            ->map(fn ($e) => trim($e))
            ->unique(fn ($e) => strtolower($e))
            ->values()
            ->all();
    }
}
