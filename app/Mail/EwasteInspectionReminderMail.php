<?php

namespace App\Mail;

use App\Services\EwasteInspectionReminderService;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Phase 3 — the run-up reminder for the quarterly e-waste collection.
 *
 * One mailable, two audiences. `$audience` changes only the framing sentence — never the
 * asset list or the date — so IT and Finance are never looking at different versions of the
 * same queue. Same rule as RentalAssetAcknowledgedMail.
 */
class EwasteInspectionReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $mark,
        public CarbonInterface $sweepDate,
        public Collection $rows,
        public string $audience = 'it',
    ) {}

    public function envelope(): Envelope
    {
        $label = EwasteInspectionReminderService::MARKS[$this->mark]['label'] ?? '';
        $count = $this->rows->count();

        // The day-of subject names the consequence, because by then it IS the consequence —
        // anything still on the list postpones the whole cycle by a quarter.
        $subject = $this->mark === 'day'
            ? "E-Waste Collection Is Today — {$count} asset(s) still uninspected"
            : "E-Waste Inspection Due — {$label} to the quarterly collection ({$count} outstanding)";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ewaste-inspection-reminder', with: [
            'mark' => $this->mark,
            'markLabel' => EwasteInspectionReminderService::MARKS[$this->mark]['label'] ?? '',
            'sweepDate' => $this->sweepDate,
            'rows' => $this->rows,
            'audience' => $this->audience,
        ]);
    }
}
