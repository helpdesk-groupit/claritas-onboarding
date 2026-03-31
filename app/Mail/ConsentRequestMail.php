<?php

namespace App\Mail;

use App\Models\Onboarding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Onboarding $onboarding) {}

    public function envelope(): Envelope
    {
        $name    = $this->onboarding->personalDetail?->full_name ?? 'New Hire';
        $company = $this->onboarding->workDetail?->company ?? 'the company';
        return new Envelope(subject: "Action Required: Please Give Your Declaration & Consent — {$company}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.consent-request');
    }
}