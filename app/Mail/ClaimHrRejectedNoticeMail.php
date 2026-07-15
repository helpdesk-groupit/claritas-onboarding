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
 * Sent to the approving manager/PIC when HR rejects a claim they approved — purely
 * informational. The employee corrects + resubmits themselves; no action is needed
 * from the manager (the old "release back to employee" step has been abolished).
 */
class ClaimHrRejectedNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ExpenseClaim $claim,
        public Employee $manager,
        public string $action = 'rejected', // 'rejected' | 'reversed'
    ) {}

    public function envelope(): Envelope
    {
        $verb = $this->action === 'reversed' ? 'reversed' : 'rejected';

        return new Envelope(
            subject: "HR {$verb} {$this->claim->claim_number} ({$this->claim->subjectLabel()})"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-hr-rejected-notice', with: [
            'claim' => $this->claim,
            'manager' => $this->manager,
            'action' => $this->action,
        ]);
    }
}
