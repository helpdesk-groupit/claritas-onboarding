<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Generic database (bell) notification for the Asset Decommissioning module.
 *
 * Follows the project-wide payload contract — the bell JS reads only
 * icon/color/message/url — so a single parameterised class covers every event
 * (batch acknowledged, quotation pending/approved/rejected, receipt due, …).
 * Database channel only; emails go via separate Mail::to()->send() calls.
 */
class DecommissionNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $event,
        // Nullable since 2026-08-13: the inspection reminders fire while assets are still in
        // the queue, before any cycle exists, so there is genuinely no batch to name. Kept as
        // a required argument (no default) so every caller still states it one way or another.
        public ?string $batchNumber,
        public string $subject,
        public string $message,
        public string $url,
        public string $icon = 'bi-recycle',
        public string $color = 'primary',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'batch_number' => $this->batchNumber,
            'subject' => $this->subject,
            'icon' => $this->icon,
            'color' => $this->color,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
