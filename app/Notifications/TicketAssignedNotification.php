<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(public Ticket $ticket, public User $assignedBy) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event'           => 'ticket.assigned',
            'ticket_id'       => $this->ticket->id,
            'ticket_number'   => $this->ticket->ticket_number,
            'department'      => $this->ticket->department,
            'subject'         => $this->ticket->subject,
            'assigned_by_id'  => $this->assignedBy->id,
            'assigned_by_name'=> $this->assignedBy->name,
            'icon'            => 'bi-person-check',
            'color'           => 'success',
            'message'         => "You've been assigned to {$this->ticket->ticket_number} — {$this->ticket->subject}",
            'url'             => route('tickets.show', $this->ticket),
        ];
    }
}
