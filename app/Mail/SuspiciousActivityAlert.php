<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuspiciousActivityAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array $alert,
    ) {}

    public function envelope(): Envelope
    {
        $severity = strtoupper($this->alert['severity'] ?? 'HIGH');
        return new Envelope(
            subject: "[{$severity}] Security Alert: {$this->alert['title']}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.suspicious-activity-alert',
        );
    }
}
