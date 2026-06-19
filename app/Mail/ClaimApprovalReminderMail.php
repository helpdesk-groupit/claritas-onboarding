<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Reminds an approving manager that they have claims pending approval before the HR
 * cutoff (the date a claim must be manager-approved to be processed this cycle).
 */
class ClaimApprovalReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $manager,
        public Collection $claims,
        public string $cutoff,
        public bool $lastCall = false,
    ) {}

    public function envelope(): Envelope
    {
        $n = $this->claims->count();
        $subject = $this->lastCall
            ? "HR cutoff TODAY ({$this->cutoff}): {$n} claim(s) still need your approval"
            : "Reminder: {$n} claim(s) await your approval before the {$this->cutoff} HR cutoff";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-approval-reminder', with: [
            'manager' => $this->manager,
            'claims' => $this->claims,
            'cutoff' => $this->cutoff,
            'lastCall' => $this->lastCall,
        ]);
    }
}
