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
     * $type: 'midmonth' (early 15th nudge), 'draft' (has unsubmitted draft claims, day-before),
     *        'lastcall' (deadline day, has drafts), or 'none' (no claim this month).
     * $drafts: the employee's open draft claims (listed for the 'midmonth'/'draft'/'lastcall' variants).
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
        $subject = match ($this->type) {
            'none' => "No expense claim for {$period}? Submission closes {$this->deadline}",
            'lastcall' => "Last call: submit your {$period} expense claim(s) TODAY ({$this->deadline})",
            'midmonth' => "Reminder: prepare your {$period} expense claim(s) — deadline {$this->deadline}",
            default => "Reminder: submit your {$period} expense claim(s) by {$this->deadline}",
        };

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
