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
 * Sent to the EMPLOYEE when the monthly cutoff sweep (claims:auto-submit) submits a draft
 * they left unsubmitted — so they know it has gone to their approver, not vanished.
 */
class ClaimAutoSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ExpenseClaim $claim,
        public Employee $employee,
        public ?string $managerName = null,
        public int $cutoffDay = 20,
    ) {}

    public function envelope(): Envelope
    {
        $label = $this->claim->subjectLabel();

        return new Envelope(subject: "Your expense claim was auto-submitted: {$label}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-auto-submitted', with: [
            'claim' => $this->claim,
            'employee' => $this->employee,
            'managerName' => $this->managerName,
            'cutoffDay' => $this->cutoffDay,
        ]);
    }
}
