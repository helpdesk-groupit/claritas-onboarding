<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentInsight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof that an invoice on the vendor billing register was paid — a bank transfer slip,
 * remittance advice or payment receipt, filed against the invoice it settles.
 *
 * NOT App\Models\Payslip. That one is an employee's monthly salary slip in the payroll
 * module and shares nothing with this; the two are kept apart by class name alone, so watch
 * which is being imported. Everything user-facing here says "payment slip" for the same
 * reason.
 *
 * ONE PER INVOICE, enforced by a unique index rather than by convention (see the migration).
 * That is load-bearing rather than tidy: the Billing tab's Status column is DERIVED from
 * whether this row exists, so two rows for one invoice would make "is this bill settled?"
 * answerable two ways. Uploading again replaces the row and its file.
 *
 * Read like every other vendor document — same scan-before-save path, same `ai_*` columns
 * behind HasDocumentInsight, same editable summary with its provenance stamp. The figures
 * it carries are read off the slip and never typed.
 */
class VendorPaymentSlip extends Model
{
    use HasDocumentInsight;

    /**
     * How far two amounts may differ before they are called a mismatch.
     *
     * A cent, not a percentage: both figures are read off printed documents in the same
     * currency, so anything beyond rounding is a different number — and a tolerance wide
     * enough to swallow a bank charge would also swallow a slip that paid the wrong bill.
     */
    private const AMOUNT_EPSILON = 0.01;

    protected $fillable = [
        'vendor_billing_document_id',
        'file_path', 'original_filename',
        'paid_amount', 'paid_on', 'payment_reference', 'payment_method',
        'invoice_reference', 'currency',
        'ai_status', 'ai_summary', 'ai_key_points', 'ai_text', 'ai_at',
        'companies_involved', 'uploaded_by',
    ];

    protected $casts = [
        'paid_on' => 'date',
        'paid_amount' => 'decimal:2',
        'ai_key_points' => 'array',
        'ai_at' => 'datetime',
        'companies_involved' => 'array',
        'ai_summary_edited_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(VendorBillingDocument::class, 'vendor_billing_document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** 'payment' — which prompt the reader frames this document with. */
    public function aiKind(): string
    {
        return 'payment';
    }

    /** How the assistant would name this document if it cites it. */
    public function aiLabel(): string
    {
        return 'Payment slip'
            .($this->payment_reference ? ' '.$this->payment_reference : '')
            .($this->paid_on ? ' dated '.$this->paid_on->format('d-m-Y') : '');
    }

    /** The amount as printed, or null when the slip could not be read for one. */
    public function amountLabel(): ?string
    {
        return $this->paid_amount === null
            ? null
            : $this->currency.' '.number_format((float) $this->paid_amount, 2);
    }

    /**
     * Where this slip disagrees with the invoice it was filed against.
     *
     * FLAGS, never blocks — the same rule the SST discrepancy already follows. Both sides
     * are machine readings of printed documents, so a mismatch is at least as likely to be
     * a misread as a mis-payment; refusing the upload would leave the operator with no
     * record of the payment at all, which is strictly worse than a warned one. It is also
     * how a slip filed against the wrong invoice is caught, and that matters more here than
     * anywhere else on the page: filing it is what marks the invoice Paid.
     *
     * Silent when there is nothing to compare. An unread slip states that on its own row;
     * inventing "amount could not be checked" as a warning would bury the real ones.
     *
     * @return list<string>
     */
    public function mismatches(): array
    {
        $document = $this->document;

        if (! $document) {
            return [];
        }

        $out = [];

        if ($this->paid_amount !== null && $document->total !== null
            && abs((float) $this->paid_amount - (float) $document->total) > self::AMOUNT_EPSILON) {
            $out[] = 'The slip pays '.$this->amountLabel()
                .', but this invoice is for '.$document->currency.' '.number_format((float) $document->total, 2).'.';
        }

        // The number printed ON the slip against the number of the invoice it was filed
        // under. Compared through the project's one invoice-reference normaliser, so
        // "inv-25268" and "INV-25268" are the same reference while INV-2025-1 and
        // INV-20251 stay two different ones — punctuation is meaning here.
        if (filled($this->invoice_reference) && filled($document->doc_number)
            && AssetInventory::normaliseInvoiceReference($this->invoice_reference)
                !== AssetInventory::normaliseInvoiceReference($document->doc_number)) {
            $out[] = 'The slip refers to invoice "'.$this->invoice_reference
                .'", but it is filed against "'.$document->doc_number.'".';
        }

        return $out;
    }

    /** The mismatches as one sentence for a badge title, or null when they agree. */
    public function mismatchFlag(): ?string
    {
        $mismatches = $this->mismatches();

        return $mismatches ? implode(' ', $mismatches) : null;
    }

    /**
     * A one-line "what this slip says" for the listing cell, built from the fields rather
     * than the summary.
     *
     * The summary is prose and the cell is narrow; what a reader checks at a glance is the
     * amount, the date and the reference. The full summary is one click away in the
     * expandable panel, exactly like the invoice's own.
     */
    public function detailLine(): string
    {
        $parts = array_filter([
            $this->amountLabel(),
            $this->paid_on ? fmt_date($this->paid_on) : null,
            $this->payment_reference ? 'Ref '.$this->payment_reference : null,
            $this->payment_method,
        ]);

        return implode(' · ', $parts);
    }
}
