<?php

namespace App\Mail;

use App\Models\Offboarding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OffboardingNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Offboarding $offboarding,
        public string $type // 'employee' | 'team'
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->offboarding->full_name ?? 'Employee';
        $date = $this->offboarding->exit_date?->format('d M Y') ?? '';
        return new Envelope(
            subject: "Offboarding Notice — {$name} — Exit Date: {$date}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.offboarding-notice');
    }
}