<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The AI document scanner is refusing every call, and only an admin can fix it.
 *
 * Raised by ClaimReceiptOcrService::recordFailure() the first time a REFUSAL is seen
 * (exhausted credit, rejected key, a model the key may not use) — never for a 5xx or a
 * timeout, which clear on their own.
 *
 * This exists because of a three-day silent outage: from 2026-09-09 the Anthropic
 * balance was exhausted and every claim scan returned 400. The provider's reason was
 * logged correctly on all 36 of them, and a log nobody reads is not a notification —
 * so the first anyone heard of it was an employee reporting that "the OCR can't read
 * clear receipts", which is what the screen had been telling them all along.
 *
 * Follows the project-wide bell payload contract (the bell JS reads only
 * icon/color/message/url). Database channel only, like every other bell notification.
 */
class AiScannerUnavailableNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{kind:string,status:?int,detail:?string,provider:?string,at?:string}  $outage
     */
    public function __construct(public array $outage) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event' => 'ai_scanner_unavailable',
            'subject' => 'Receipt / document scanner is down',
            'icon' => 'bi-robot',
            'color' => 'danger',
            // The provider's own words, verbatim and unabridged, because paraphrasing them
            // is how "credit balance is too low" becomes "an API error" and the admin goes
            // looking in the wrong place. This reaches admins only — the claimant's message
            // (ClaimReceiptOcrService::unavailableMessage()) deliberately names none of it.
            'message' => $this->line(),
            'provider' => $this->outage['provider'] ?? null,
            'status' => $this->outage['status'] ?? null,
            'url' => route('superadmin.claude-api.index'),
        ];
    }

    private function line(): string
    {
        $detail = trim((string) ($this->outage['detail'] ?? ''));
        $status = $this->outage['status'] ?? null;

        $head = 'AI scanning is failing — receipt scans, e-waste quotations and vendor document '
            .'reading are all falling back to manual entry.';

        if ($detail !== '') {
            return $head.' The provider said'.($status ? " (HTTP {$status})" : '').': “'.$detail.'”';
        }

        return $head.($status ? " The provider returned HTTP {$status}." : '');
    }
}
