<?php

namespace App\Mail;

use App\Models\AssetDecommissionBatch;
use App\Models\Vendor;
use App\Services\DecommissionReportRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Flow 2 — Request for Quotation, with the asset list attached as a PDF.
 *
 * Since Phase 5 this goes to EVERY active e-waste vendor rather than only the primary one, so
 * the offers can be compared on price. `$recipient` is the vendor being asked — distinct from
 * `$batch->vendor`, which is only a default for the cycle page until management select a
 * winner. Addressing the mail from the batch's vendor would greet every recipient by the
 * primary vendor's name.
 */
class EwasteRfqMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AssetDecommissionBatch $batch,
        public ?Vendor $recipient = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('decommission.copy.rfq_subject', 'Request for Quotation — IT Asset E-Waste Disposal').' — '.$this->batch->batch_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ewaste-rfq', with: [
            'batch' => $this->batch,
            'recipient' => $this->recipient ?? $this->batch->vendor,
        ]);
    }

    public function attachments(): array
    {
        // Ensure the PDF has the full per-asset details (specs + photos), not just the summary.
        $batch = $this->batch->loadMissing(['vendor', 'items.asset']);

        return [
            Attachment::fromData(
                fn () => DecommissionReportRenderer::render($batch),
                'RFQ-'.$batch->batch_number.'.pdf'
            )->withMime('application/pdf'),
        ];
    }
}
