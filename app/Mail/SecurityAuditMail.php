<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SecurityAuditMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly \Illuminate\Support\Collection $events,
        public readonly string $periodLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Security Alert] ' . $this->events->count() . ' security event(s) detected — ' . $this->periodLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security-audit',
        );
    }
}
