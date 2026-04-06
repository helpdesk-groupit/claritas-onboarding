<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClaimSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ExpenseClaim $claim,
        public Employee $employee,
        public string $recipientType,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->employee->preferred_name ?? $this->employee->full_name;
        $period = \Carbon\Carbon::create($this->claim->year, $this->claim->month)->format('F Y');
        $subject = $this->recipientType === 'manager'
            ? "Expense Claim Pending Approval: {$name} — {$period}"
            : "Expense Claim Submitted for HR Review: {$name} — {$period}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-submitted', with: [
            'claim' => $this->claim,
            'employee' => $this->employee,
            'recipientType' => $this->recipientType,
        ]);
    }
}
