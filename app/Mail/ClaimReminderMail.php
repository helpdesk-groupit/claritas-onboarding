<?php

namespace App\Mail;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ClaimReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $type: 'draft' (has unsubmitted draft claims) or 'none' (no claim this month).
     * $drafts: the employee's open draft claims (for the 'draft' variant).
     */
    public function __construct(
        public Employee $employee,
        public int $year,
        public int $month,
        public string $deadline,
        public string $type = 'draft',
        public ?Collection $drafts = null,
    ) {}

    public function envelope(): Envelope
    {
        $period = \Carbon\Carbon::create($this->year, $this->month)->format('F Y');
        $subject = $this->type === 'none'
            ? "No expense claim for {$period}? Submission closes {$this->deadline}"
            : "Reminder: submit your {$period} expense claim(s) by {$this->deadline}";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-reminder', with: [
            'employee' => $this->employee,
            'year' => $this->year,
            'month' => $this->month,
            'deadline' => $this->deadline,
            'type' => $this->type,
            'drafts' => $this->drafts ?? collect(),
        ]);
    }
}
