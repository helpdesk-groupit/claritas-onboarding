<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\EmployeeEditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployeeConsentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public EmployeeEditLog $editLog,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->employee->company ?? 'the company';
        return new Envelope(
            subject: "Action Required: Re-acknowledge Declaration & Consent — {$company}"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.employee-consent-request', with: [
            'employee' => $this->employee,
            'editLog'  => $this->editLog,
        ]);
    }
}
