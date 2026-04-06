<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class PendingLeaveReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $manager,
        public Collection $pendingApplications,
    ) {}

    public function envelope(): Envelope
    {
        $count = $this->pendingApplications->count();
        return new Envelope(
            subject: "Reminder: {$count} Pending Leave Request(s) Awaiting Your Approval"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.pending-leave-reminder');
    }
}
