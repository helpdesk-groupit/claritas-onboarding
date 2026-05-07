<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketResolvedNotification extends Notification
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
            'event'           => 'ticket.resolved',
            'ticket_id'       => $this->ticket->id,
            'ticket_number'   => $this->ticket->ticket_number,
            'department'      => $this->ticket->department,
            'subject'         => $this->ticket->subject,
            'resolved_by_id'  => $this->ticket->assignee?->id,
            'resolved_by_name'=> $this->ticket->assignee?->name,
            'icon'            => 'bi-check2-circle',
            'color'           => 'success',
            'message'         => "Your ticket {$this->ticket->ticket_number} has been resolved",
            'url'             => route('tickets.show', $this->ticket),
        ];
    }
}
