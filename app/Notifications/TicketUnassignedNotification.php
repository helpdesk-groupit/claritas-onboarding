<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketUnassignedNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket, public User $removedBy) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event'          => 'ticket.unassigned',
            'ticket_id'      => $this->ticket->id,
            'ticket_number'  => $this->ticket->ticket_number,
            'department'     => $this->ticket->department,
            'subject'        => $this->ticket->subject,
            'removed_by_id'  => $this->removedBy->id,
            'removed_by_name'=> $this->removedBy->name,
            'icon'           => 'bi-person-x',
            'color'          => 'warning',
            'message'        => "You've been removed from {$this->ticket->ticket_number} by {$this->removedBy->name}",
            'url'            => route('tickets.show', $this->ticket),
        ];
    }
}
