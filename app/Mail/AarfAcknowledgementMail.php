<?php

namespace App\Mail;

use App\Models\Aarf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AarfAcknowledgementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Aarf $aarf,
        public string $employeeName,
        public string $actionLabel,   // 'assigned' | 'updated' | 'returned'
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->actionLabel === 'returned'
            ? "Asset Record Update — {$this->aarf->aarf_reference}"
            : "Action Required: Acknowledge Your Asset Form — {$this->aarf->aarf_reference}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.aarf-acknowledgement',
        );
    }
}