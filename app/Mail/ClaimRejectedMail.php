<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClaimRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ExpenseClaim $claim,
        public Employee $employee,
        public string $rejectorType,
    ) {}

    public function envelope(): Envelope
    {
        $period = \Carbon\Carbon::create($this->claim->year, $this->claim->month)->format('F Y');
        $by = $this->rejectorType === 'manager' ? 'Manager' : 'HR';
        return new Envelope(subject: "Expense Claim Rejected by {$by}: {$period}");
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-rejected', with: [
            'claim' => $this->claim,
            'employee' => $this->employee,
            'rejectorType' => $this->rejectorType,
        ]);
    }
}
