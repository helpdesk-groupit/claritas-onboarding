<?php

namespace App\Mail;

use App\Models\Offboarding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OffboardingSendoffMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Offboarding $offboarding) {}

    public function envelope(): Envelope
    {
        $name = $this->offboarding->full_name ?? 'Employee';
        return new Envelope(
            subject: "Farewell & Best Wishes — {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.offboarding-sendoff');
    }
}