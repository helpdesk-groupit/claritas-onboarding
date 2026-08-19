<?php

namespace Tests\Feature;

use App\Mail\ClaudeApiKeyExpiringMail;
use App\Models\ClaudeApiKeyHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `claude-api:remind-key-expiry` — the daily reminder that chases the Anthropic
 * API key's company-policy expiry (90 days by default, tracked off
 * ClaudeApiKeyHistory.started_at). See config('claude.key_expiry').
 */
class ClaudeApiKeyExpiryReminderTest extends TestCase
{
    use RefreshDatabase;

    private function makeKeyHistory(int $daysAgo, array $overrides = []): ClaudeApiKeyHistory
    {
        return ClaudeApiKeyHistory::create(array_merge([
            'label' => 'Test key',
            'masked_key' => 'sk-ant-…1234',
            'set_by' => null,
            'started_at' => now()->subDays($daysAgo),
            'ended_at' => null,
            'expiry_reminders_sent' => null,
        ], $overrides));
    }

    /** Group IT + the two named extras, so every test has real recipients to assert against. */
    private function seedRecipients(): array
    {
        $itManager = User::factory()->itManager()->create(['work_email' => 'it.manager@example.com']);
        $itExecutive = User::factory()->itExecutive()->create(['work_email' => 'it.exec@example.com']);
        config(['claude.key_expiry.extra_recipients' => ['extra.one@example.com', 'extra.two@example.com']]);

        return compact('itManager', 'itExecutive');
    }

    public function test_no_key_history_sends_nothing(): void
    {
        Mail::fake();
        $this->seedRecipients();

        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_seven_day_threshold_fires_and_is_recorded(): void
    {
        Mail::fake();
        $this->seedRecipients();
        $history = $this->makeKeyHistory(83); // 90 - 83 = 7 days left

        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(ClaudeApiKeyExpiringMail::class, fn ($mail) => $mail->daysLeft === 7);
        $this->assertSame([7], $history->fresh()->remindersSent());
    }

    public function test_rerunning_same_day_does_not_resend(): void
    {
        Mail::fake();
        $this->seedRecipients();
        $this->makeKeyHistory(83); // 7 days left

        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();
        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        Mail::assertSentCount(1);
    }

    public function test_three_day_threshold_fires(): void
    {
        Mail::fake();
        $this->seedRecipients();
        $history = $this->makeKeyHistory(87, ['expiry_reminders_sent' => [7]]); // 90-87=3 left, 7-day already sent

        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(ClaudeApiKeyExpiringMail::class, fn ($mail) => $mail->daysLeft === 3);
        $this->assertSame([7, 3], $history->fresh()->remindersSent());
    }

    public function test_zero_day_threshold_fires_on_and_after_expiry(): void
    {
        Mail::fake();
        $this->seedRecipients();
        // 95 days ago = 5 days PAST the 90-day expiry — proves the day-of reminder still
        // fires as a catch-up even if nothing ran on the exact expiry day.
        $history = $this->makeKeyHistory(95, ['expiry_reminders_sent' => [7, 3]]);

        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(ClaudeApiKeyExpiringMail::class, fn ($mail) => $mail->daysLeft === -5);
        $this->assertSame([7, 3, 0], $history->fresh()->remindersSent());
    }

    public function test_catchup_fires_multiple_overdue_thresholds_in_one_run(): void
    {
        Mail::fake();
        $this->seedRecipients();
        // 88 days ago, never reminded — 2 days left. Both the 7-day and 3-day
        // milestones are overdue at once; the 0-day one isn't due yet.
        $history = $this->makeKeyHistory(88);

        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        Mail::assertSentCount(2);
        $this->assertSame([7, 3], $history->fresh()->remindersSent());
    }

    public function test_rotating_the_key_resets_the_reminder_cycle(): void
    {
        // Old key: fully reminded and long past its own expiry.
        $old = $this->makeKeyHistory(200, ['expiry_reminders_sent' => [7, 3, 0]]);

        $new = ClaudeApiKeyHistory::rotate('sk-ant-brand-new-key', 'Rotated key', null);

        $this->assertNotNull($old->fresh()->ended_at);
        $this->assertSame($new->id, ClaudeApiKeyHistory::current()->id);
        $this->assertSame([], $new->remindersSent());
        $this->assertSame(90, $new->daysUntilExpiry());

        Mail::fake();
        $this->seedRecipients();
        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        // Fresh key, 90 days out — nothing due yet.
        Mail::assertNothingSent();
    }

    public function test_recipients_include_it_roles_and_extras_and_exclude_inactive(): void
    {
        Mail::fake();
        $recipients = $this->seedRecipients();
        // An inactive IT manager must never receive it.
        $inactiveItManager = User::factory()->itManager()->inactive()->create(['work_email' => 'inactive.it@example.com']);
        $this->makeKeyHistory(90); // 0 days left, fires immediately

        $this->artisan('claude-api:remind-key-expiry')->assertSuccessful();

        Mail::assertSent(ClaudeApiKeyExpiringMail::class, function ($mail) use ($recipients, $inactiveItManager) {
            return $mail->hasTo($recipients['itManager']->work_email)
                && $mail->hasTo($recipients['itExecutive']->work_email)
                && $mail->hasTo('extra.one@example.com')
                && $mail->hasTo('extra.two@example.com')
                && ! $mail->hasTo($inactiveItManager->work_email);
        });
    }
}
