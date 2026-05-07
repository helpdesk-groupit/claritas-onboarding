<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public User $assignee,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->ticket->ticket_number}] You have been assigned a {$this->ticket->department} ticket"
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ticket-assigned', with: [
            'ticket'   => $this->ticket,
            'assignee' => $this->assignee,
        ]);
    }
}
