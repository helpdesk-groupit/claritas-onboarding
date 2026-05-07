<?php

namespace App\Mail;

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
        public User $recipient,
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
            'ticket'    => $this->ticket,
            'recipient' => $this->recipient,
        ]);
    }
}
