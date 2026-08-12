<?php

namespace App\Mail;

use App\Models\AssetDecommissionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Flow 2 — tells Finance a quotation has been uploaded and links to the in-app
 * Pending Quotations approval screen.
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
            'approveUrl' => route('accounting.fixed-assets.index', ['status' => 'disposed']),
        ]);
    }
}
