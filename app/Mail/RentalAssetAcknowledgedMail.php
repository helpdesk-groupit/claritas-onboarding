<?php

namespace App\Mail;

use App\Models\RentalAssetAcknowledgement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * The completed AARF, sent once the receipt is acknowledged.
 *
 * One mailable for all four audiences — IT, Finance, the company's management and the
 * vendor's PIC — because they are all being told the same fact and handed the same signed
 * document. `$audience` only changes the opening line; it must never change the FIGURES or
 * the attachment, or the four parties would end up holding different versions of one signed
 * record.
 */
class RentalAssetAcknowledgedMail extends Mailable
{
    use Queueable, SerializesModels;

    public const AUDIENCE_VENDOR = 'vendor';

    public const AUDIENCE_IT = 'it';

    public const AUDIENCE_FINANCE = 'finance';

    /**
     * The CEO/CTO named for the company on the form — see EwasteCompanyApprover.
     *
     * Addressed per COMPANY rather than as a fixed group management line, because the
     * management of one group entity is not the management of another: a handover of
     * Enlinea's kit is not Claritas management's document.
     */
    public const AUDIENCE_MANAGEMENT = 'management';

    public function __construct(
        public RentalAssetAcknowledgement $aarf,
        public string $audience = self::AUDIENCE_IT,
    ) {}

    public function envelope(): Envelope
    {
        // The direction is in the subject because the two documents mean opposite things to
        // a vendor PIC scanning an inbox: one says kit arrived with us, the other says it
        // came back to them.
        return new Envelope(
            subject: 'Asset Acceptance & Return Form — '
                .($this->aarf->isReturn() ? 'Return' : 'Receipt')
                .' Acknowledged — '.$this->aarf->reference,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental-asset-acknowledged',
            with: ['aarf' => $this->aarf, 'audience' => $this->audience],
        );
    }

    /**
     * The stored snapshot if it exists, else rendered on the spot.
     *
     * The fallback matters: `acknowledge()` swallows a PDF-write failure so bookkeeping
     * can never block the acknowledgement itself, which means `pdf_path` can legitimately
     * be null on a form that IS signed. Attaching nothing in that case would send three
     * parties an email announcing a document it forgot to include.
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBytes(), $this->aarf->reference.'.pdf')
                ->withMime('application/pdf'),
        ];
    }

    /**
     * The signed form as PDF bytes.
     *
     * Public and separate from attachments() so it can be exercised directly —
     * Attachment's byte resolver is protected, so a test cannot reach it through the
     * Attachment object, and the fallback below is exactly the branch worth testing.
     */
    public function pdfBytes(): string
    {
        if ($this->aarf->pdf_path && Storage::disk('local')->exists($this->aarf->pdf_path)) {
            return Storage::disk('local')->get($this->aarf->pdf_path);
        }

        // Same call shape as RentalAssetAcknowledgementController::renderPdf().
        return Pdf::loadView('vendors.aarf.pdf', ['aarf' => $this->aarf])->setPaper('a4')->output();
    }
}
