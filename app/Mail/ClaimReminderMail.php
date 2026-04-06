<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClaimReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Employee $employee,
        public int $year,
        public int $month,
        public string $deadline,
    ) {}

    public function envelope(): Envelope
    {
        $period = \Carbon\Carbon::create($this->year, $this->month)->format('F Y');
        return new Envelope(
            subject: "Reminder: Submit Your Expense Claims for {$period} by {$this->deadline}"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.claim-reminder', with: [
            'employee' => $this->employee,
            'year' => $this->year,
            'month' => $this->month,
            'deadline' => $this->deadline,
        ]);
    }
}
