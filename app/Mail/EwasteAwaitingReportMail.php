<?php

namespace App\Mail;

use App\Models\AssetDecommissionBatch;
use App\Services\DecommissionReportRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5A — the "assets awaiting decommissioning" report emailed when a quarterly cycle
 * opens, telling Finance AND the company's management that the quotation process has
 * started. One mailable, two audiences (`$audience` changes only the greeting and closing
 * line — never the figures or the attachment), same pattern as EwasteAwardMail. Asset list
 * attached as a PDF.
 */
class EwasteAwaitingReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AssetDecommissionBatch $batch,
        public string $audience = 'finance',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('decommission.copy.awaiting_subject', 'E-Waste Cycle — Assets Awaiting Decommissioning').' — '.$this->batch->batch_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ewaste-awaiting', with: ['batch' => $this->batch, 'audience' => $this->audience]);
    }

    public function attachments(): array
    {
        // Ensure the PDF has the full per-asset details (specs + photos), not just the summary.
        $batch = $this->batch->loadMissing(['vendor', 'items.asset']);

        return [
            Attachment::fromData(
                fn () => DecommissionReportRenderer::render($batch),
                $batch->batch_number.'.pdf'
            )->withMime('application/pdf'),
        ];
    }
}
