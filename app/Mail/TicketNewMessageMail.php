<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketNewMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketMessage $message,
        public User $sender,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->ticket->ticket_number}] New message from {$this->sender->name}"
        );
    }

    public function content(): Content
    {
        // NOTE: $message is reserved by Laravel for the Illuminate\Mail\Message
        // instance. Pass our TicketMessage under a different key to avoid the
        // shadowing that would otherwise throw "Cannot access protected property
        // Illuminate\Mail\Message::$message" at render time.
        return new Content(view: 'emails.ticket-new-message', with: [
            'ticket'        => $this->ticket,
            'ticketMessage' => $this->message,
            'sender'        => $this->sender,
            'recipient'     => $this->recipient,
        ]);
    }
}
