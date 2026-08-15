<?php

namespace App\Mail;

use App\Models\AssetDecommissionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5C — the disposal has been authorised: tell the winning vendor and the IT team.
 *
 * One mailable, two audiences. `$audience` changes the framing only — the vendor is told they
 * have been selected and what to collect; IT are told the same decision plus the comparison it
 * came from, so the internal copy carries the analysis and the vendor's does not.
 *
 * Losing vendors are deliberately not written to: they made an offer we did not take up, and
 * there is nothing they are required to do.
 */
class EwasteAwardMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AssetDecommissionBatch $batch,
        public string $audience = 'vendor',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->audience === 'vendor'
                ? 'E-Waste Collection Awarded — '.$this->batch->batch_number
                : 'E-Waste Disposal Approved — '.$this->batch->batch_number
        );
    }

    public function content(): Content
    {
        $batch = $this->batch->loadMissing([
            'items.asset', 'quotations.vendor', 'selectedQuotation.vendor',
            'recommendedQuotation.vendor', 'managementReviewer', 'financeReviewer',
        ]);

        return new Content(view: 'emails.ewaste-award', with: [
            'batch' => $batch,
            'winner' => $batch->selectedQuotation,
            'comparison' => $batch->quotationsForComparison(),
            'audience' => $this->audience,
        ]);
    }
}
