<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketRaisedNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event'         => 'ticket.raised',
            'ticket_id'     => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'department'    => $this->ticket->department,
            'priority'      => $this->ticket->priority,
            'subject'       => $this->ticket->subject,
            'creator_name'  => $this->ticket->creator?->name,
            'icon'          => 'bi-ticket-detailed',
            'color'         => 'primary',
            'message'       => "New {$this->ticket->department} ticket from " . ($this->ticket->creator?->name ?? 'an employee') . ' — ' . $this->ticket->subject,
            'url'           => route('tickets.show', $this->ticket),
        ];
    }
}
