<?php

namespace App\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Phase 4 — the quarterly cycle did not run.
 *
 * Goes to IT (who can clear the blockage) and to Finance (who were told a month ago that a
 * quotation was coming and would otherwise never hear it did not arrive). One mailable, two
 * audiences, `$audience` changing only the framing sentence — never the list or the dates.
 */
class EwasteCyclePostponedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Collection $blocking,
        public int $total,
        public CarbonInterface $nextSweepDate,
        public string $audience = 'it',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'E-Waste Collection Postponed — '.$this->blocking->count().' asset(s) not inspected'
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ewaste-cycle-postponed', with: [
            'blocking' => $this->blocking,
            'total' => $this->total,
            'nextSweepDate' => $this->nextSweepDate,
            'audience' => $this->audience,
        ]);
    }
}
