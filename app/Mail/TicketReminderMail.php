<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public User|Employee $recipient,
        public CarbonInterface $lastActivityAt,
        public bool $isUnassigned,
    ) {}

    public function envelope(): Envelope
    {
        $hours = (int) $this->lastActivityAt->diffInHours(now());
        return new Envelope(
            subject: "[{$this->ticket->ticket_number}] Reminder: no activity for {$hours}h — {$this->ticket->subject}"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-reminder', with: [
            'ticket'         => $this->ticket,
            'recipient'      => $this->recipient,
            'recipientName'  => $this->resolveRecipientName(),
            'lastActivityAt' => $this->lastActivityAt,
            'isUnassigned'   => $this->isUnassigned,
        ]);
    }

    private function resolveRecipientName(): string
    {
        if ($this->recipient instanceof User) {
            return $this->recipient->name ?: 'there';
        }
        return $this->recipient->preferred_name
            ?: $this->recipient->full_name
            ?: 'there';
    }
}
