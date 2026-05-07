<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketResolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->ticket->ticket_number}] Your ticket has been resolved"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-resolved', with: [
            'ticket' => $this->ticket,
        ]);
    }
}
