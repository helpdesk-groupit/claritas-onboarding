<?php

namespace App\Mail;

use App\Models\Onboarding;
use App\Models\OnboardingEditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingEditNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Onboarding $onboarding,
        public OnboardingEditLog $editLog,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->onboarding->workDetail?->company ?? 'the company';
        return new Envelope(
            subject: "Your Onboarding Information Has Been Updated — {$company}"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.onboarding-edit-notification', with: [
            'onboarding' => $this->onboarding,
            'editLog'    => $this->editLog,
        ]);
    }
}
