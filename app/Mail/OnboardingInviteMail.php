<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $inviteUrl;
    public string $inviteEmail;
    public string $senderName;
    public string $companyName;

    public function __construct(string $inviteUrl, string $inviteEmail, string $senderName, string $companyName)
    {
        $this->inviteUrl    = $inviteUrl;
        $this->inviteEmail  = $inviteEmail;
        $this->senderName   = $senderName;
        $this->companyName  = $companyName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You have been invited to complete your onboarding — ' . $this->companyName);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.onboarding-invite');
    }
}