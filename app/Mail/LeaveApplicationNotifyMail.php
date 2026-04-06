<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\LeaveApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveApplicationNotifyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveApplication $application,
        public Employee $employee,
        public string $recipientType, // 'manager' or 'hr'
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->employee->preferred_name ?? $this->employee->full_name;
        $type = $this->application->leaveType?->name ?? 'Leave';
        return new Envelope(
            subject: "Leave Application: {$name} — {$type} ({$this->application->start_date->format('d M Y')})"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.leave-application-notify', with: [
            'application' => $this->application,
            'employee'    => $this->employee,
            'recipientType' => $this->recipientType,
        ]);
    }
}
