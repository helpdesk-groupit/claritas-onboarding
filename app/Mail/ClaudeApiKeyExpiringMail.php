<?php

namespace App\Mail;

use App\Models\ClaudeApiKeyHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One of the 7-day / 3-day / day-of reminders that the Anthropic API key on the
 * "Claude API" settings page is due for rotation under company policy. Sent by
 * `claude-api:remind-key-expiry`; see ClaudeApiKeyHistory::expiresAt().
 */
class ClaudeApiKeyExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ClaudeApiKeyHistory $keyHistory,
        public int $daysLeft,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->daysLeft <= 0
            ? 'Claude API key expires TODAY — rotation needed'
            : "Claude API key expires in {$this->daysLeft} day".($this->daysLeft === 1 ? '' : 's');

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claude-api-key-expiring', with: [
            'keyHistory' => $this->keyHistory,
            'daysLeft' => $this->daysLeft,
        ]);
    }
}
