<?php

namespace App\Mail;

use App\Models\Offboarding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OffboardingReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offboarding $offboarding) {}

    public function envelope(): Envelope
    {
        $name = $this->offboarding->full_name ?? 'Employee';
        $date = $this->offboarding->exit_date?->format('d M Y') ?? '';
        return new Envelope(
            subject: "Reminder: Offboarding in 3 Days — {$name} — {$date}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.offboarding-reminder');
    }
}