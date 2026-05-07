<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewTicketMessageNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public TicketMessage $message,
        public User $sender,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $preview = $this->message->message
            ? \Illuminate\Support\Str::limit($this->message->message, 80)
            : ($this->message->hasAttachment() ? '[Attachment]' : '');

        return [
            'event'         => 'ticket.message',
            'ticket_id'     => $this->ticket->id,
            'ticket_number' => $this->ticket->ticket_number,
            'message_id'    => $this->message->id,
            'sender_id'     => $this->sender->id,
            'sender_name'   => $this->sender->name,
            'preview'       => $preview,
            'icon'          => 'bi-chat-dots',
            'color'         => 'info',
            'message'       => "{$this->sender->name} replied to {$this->ticket->ticket_number}",
            'url'           => route('tickets.show', $this->ticket),
        ];
    }
}
