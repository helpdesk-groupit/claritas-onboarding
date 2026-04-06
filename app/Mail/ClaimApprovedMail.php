<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClaimApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ExpenseClaim $claim,
        public Employee $employee,
        public string $approverType,
    ) {}

    public function envelope(): Envelope
    {
        $period = \Carbon\Carbon::create($this->claim->year, $this->claim->month)->format('F Y');
        $by = $this->approverType === 'manager' ? 'Manager' : 'HR';
        return new Envelope(subject: "Expense Claim Approved by {$by}: {$period}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-approved', with: [
            'claim' => $this->claim,
            'employee' => $this->employee,
            'approverType' => $this->approverType,
        ]);
    }
}
