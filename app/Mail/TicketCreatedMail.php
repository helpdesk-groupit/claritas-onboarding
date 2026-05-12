<?php

namespace App\Mail;

use App\Models\Employee;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public User|Employee $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->ticket->ticket_number}] New {$this->ticket->department} Ticket — {$this->ticket->subject}"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-created', with: [
            'ticket'        => $this->ticket,
            'recipient'     => $this->recipient,
            'recipientName' => $this->resolveRecipientName(),
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
