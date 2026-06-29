<?php

namespace App\Notifications;

use App\Models\ExpenseClaim;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Bell notice to the EMPLOYEE when the monthly cutoff sweep auto-submits their draft.
 * Follows the project-wide notification payload contract (icon/color/message/url).
 */
class ClaimAutoSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public ExpenseClaim $claim, public ?string $managerName = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'claim.auto_submitted',
            'claim_id' => $this->claim->id,
            'claim_number' => $this->claim->claim_number,
            'subject' => $this->claim->subjectLabel(),
            'icon' => 'bi-send-check',
            'color' => 'warning',
            'message' => "Your draft {$this->claim->claim_number} was auto-submitted at the cutoff"
                .($this->managerName ? " to {$this->managerName}" : '').' — it may be processed next month.',
            'url' => route('user.claims.show', $this->claim),
        ];
    }
}
