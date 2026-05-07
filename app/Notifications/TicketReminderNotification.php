<?php

namespace App\Notifications;

use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public CarbonInterface $lastActivityAt,
        public bool $isUnassigned,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $hours = (int) $this->lastActivityAt->diffInHours(now());

        $message = $this->isUnassigned
            ? "{$this->ticket->ticket_number} has had no activity for {$hours}h and still has no PIC"
            : "Reminder: {$this->ticket->ticket_number} has had no activity for {$hours}h";

        return [
            'event'           => 'ticket.reminder',
            'ticket_id'       => $this->ticket->id,
            'ticket_number'   => $this->ticket->ticket_number,
            'department'      => $this->ticket->department,
            'subject'         => $this->ticket->subject,
            'hours_idle'      => $hours,
            'is_unassigned'   => $this->isUnassigned,
            'icon'            => 'bi-alarm',
            'color'           => 'warning',
            'message'         => $message,
            'url'             => route('tickets.show', $this->ticket),
        ];
    }
}
