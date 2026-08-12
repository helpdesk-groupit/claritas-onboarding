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
 * Flow 2 — Request for Quotation sent to the primary e-waste vendor, with the
 * asset list attached as a PDF.
 */
class EwasteRfqMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AssetDecommissionBatch $batch) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('decommission.copy.rfq_subject', 'Request for Quotation — IT Asset E-Waste Disposal').' — '.$this->batch->batch_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ewaste-rfq', with: ['batch' => $this->batch]);
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
