<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\LeaveApplication;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveApprovalNotifyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeaveApplication $application,
        public Employee $employee,
        public string $action, // 'approved' or 'rejected'
        public string $actorName,
        public string $actorRole, // 'manager' or 'hr'
    ) {}

    public function envelope(): Envelope
    {
        $type = $this->application->leaveType?->name ?? 'Leave';
        $status = ucfirst($this->action);
        return new Envelope(
            subject: "Leave {$status}: Your {$type} Request ({$this->application->start_date->format('d M Y')})"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.leave-approval-notify', with: [
            'application' => $this->application,
            'employee'    => $this->employee,
            'action'      => $this->action,
            'actorName'   => $this->actorName,
            'actorRole'   => $this->actorRole,
        ]);
    }
}
