<?php

namespace App\Mail;

use App\Models\AssetDecommissionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Flow 2 — tells Finance a quotation comparison is ready for their (optional) review and
 * links to Management → Decommissioning, the one surface where Finance and management both
 * review a disposal. Finance's review is remarks only — they do not approve or reject.
 */
class EwasteQuotationApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AssetDecommissionBatch $batch) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('decommission.copy.approval_subject', 'Action Required — E-Waste Quotation Awaiting Finance Approval').' — '.$this->batch->batch_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.ewaste-quotation-approval', with: [
            'batch' => $this->batch,
            'approveUrl' => route('reports.decommission'),
        ]);
    }
}
