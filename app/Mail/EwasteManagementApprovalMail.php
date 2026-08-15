<?php

namespace App\Mail;

use App\Models\AssetDecommissionBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 5 — asks a company's management to authorise a disposal.
 *
 * Sent to the NAMED approvers for that company, not to a role: they span companies (CEO of one
 * entity, CTO of another), and a role-wide mail would put one entity's disposal in front of
 * everyone. All of them are asked; the first to answer settles it.
 *
 * Carries the whole comparison, because the decision is which offer to take — a mail naming
 * only IT's recommendation would make "or go with other company choices" impossible without
 * opening the system first.
 */
class EwasteManagementApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AssetDecommissionBatch $batch) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Approval Required — E-Waste Disposal '.$this->batch->batch_number
                .($this->batch->company ? ' ('.$this->batch->company.')' : '')
        );
    }

    public function content(): Content
    {
        $batch = $this->batch->loadMissing(['quotations.vendor', 'recommendedQuotation.vendor', 'items']);

        return new Content(view: 'emails.ewaste-management-approval', with: [
            'batch' => $batch,
            'comparison' => $batch->quotationsForComparison(),
            'recommended' => $batch->recommendedQuotation,
        ]);
    }
}
