<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the approving manager when HR rejects a claim they approved — asking them to
 * review HR's reason and release it (optionally with a comment) to the employee.
 */
class ClaimReleaseRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ExpenseClaim $claim,
        public Employee $manager,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "HR rejected {$this->claim->claim_number} — please review & release to the employee"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-release-request', with: [
            'claim' => $this->claim,
            'manager' => $this->manager,
        ]);
    }
}
