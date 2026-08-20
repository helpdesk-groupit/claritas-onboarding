<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentInsight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A quotation or invoice received from a vendor.
 *
 * This is a DOCUMENT REGISTER, not an AP ledger: nothing here posts a journal entry or
 * creates an acc_bills row, mirroring the "Finance stays lightweight" decision already
 * made for the e-waste cycle. The value is provenance — what they quoted, what they
 * billed, whether SST was on it, and the file itself.
 *
 * The `ai_*` columns (HasDocumentInsight) hold the machine reading of the file: the summary
 * shown on the row and the transcription the vendor Q&A assistant answers from. The figures
 * beside them are read off the same document by a second, separate call and are never typed
 * — see VendorDocumentScanController for why the two readings must stay two calls.
 *
 * Whether an invoice has been PAID is not a field on this record and not a dropdown: it is
 * the presence of a VendorPaymentSlip filed against it. See paymentState().
 */
class VendorBillingDocument extends Model
{
    use HasDocumentInsight;

    public const TYPES = [
        'quotation' => 'Quotation',
        'invoice' => 'Invoice',
        // A credit note/credit memo REDUCES or REFUNDS an earlier bill rather than billing
        // for something new. Added 2026-08-20 — before this the classifier only had
        // quotation/invoice to choose from, so a credit note was forced into whichever of
        // the two the model guessed (usually the wrong one, since it is neither an offer
        // nor a fresh bill).
        'credit_note' => 'Credit Note',
    ];

    /** Mirrors the column default; used to coerce a cleared Currency input back to a value. */
    public const DEFAULT_CURRENCY = 'MYR';

    /**
     * The hand-set lifecycle this register used until 2026-08-13. RETIRED: nothing reads it
     * for display and no form writes it any more.
     *
     * Status is now DERIVED from evidence — an invoice reads Paid when a payment slip is
     * filed against it and Pending when none is, and no one can mark a bill settled without
     * attaching the proof. `status` was the opposite: a dropdown anybody could set to Paid,
     * on a register whose whole value is provenance.
     *
     * The column and this map both stay. Nothing is gained by dropping them — it needs an
     * enum-altering migration — and rows filed before the change still carry a value here
     * that would otherwise become an unlabelled slug if the state is ever wanted back. New
     * rows are seeded 'received' by the controller purely because the column is NOT NULL.
     */
    public const STATUSES = [
        'received' => 'Received',
        'under_review' => 'Under review',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'paid' => 'Paid',
        'disputed' => 'Disputed',
    ];

    protected $fillable = [
        'vendor_id', 'vendor_contract_id', 'doc_type', 'doc_number', 'status',
        'doc_date', 'due_date',
        'subtotal', 'sst_amount', 'total', 'currency',
        'description', 'notes',
        'file_path', 'original_filename', 'created_by',
        'ai_status', 'ai_summary', 'ai_key_points', 'ai_text', 'ai_at',
        'companies_involved',
    ];

    protected $casts = [
        'doc_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'sst_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'ai_key_points' => 'array',
        'ai_at' => 'datetime',
        'companies_involved' => 'array',
        'ai_summary_edited_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class, 'vendor_contract_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The assets that ARRIVED on this document — the other end of
     * AssetInventory::originInvoice().
     *
     * Named for the origin, not just `assets()`, so that a later "every asset this document
     * billed us for" (the recurring monthly rentals, through a pivot) can sit beside it
     * without either relation becoming ambiguous about which question it answers.
     */
    public function originAssets()
    {
        return $this->hasMany(AssetInventory::class, 'origin_billing_document_id');
    }

    /**
     * Documents that can be named as the invoice an asset arrived on, newest first.
     *
     * ONE source for every picker — the asset edit form, the Add-Asset modal, and whatever
     * screen needs it next — so the two forms that assign kit to an invoice cannot come to
     * disagree about which documents exist. Pass a vendor id to narrow it; the asset forms
     * take the whole list and filter client-side as the vendor picker changes, because the
     * vendor auto-fill on those forms is already data-* driven with no AJAX endpoint.
     *
     * Invoices only: the column happily points at any billing document, but an asset
     * grouped under a quotation would be filed under an offer nobody acted on. Widening
     * this is a one-line change here, not a migration — which is the point of the FK
     * pointing at a document rather than at "an invoice".
     *
     * @return \Illuminate\Database\Eloquent\Collection<int,self>
     */
    public static function invoiceOptions(?int $vendorId = null)
    {
        return static::query()
            ->where('doc_type', 'invoice')
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->orderByDesc('doc_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * How this document reads in a picker. On the model so the two asset forms cannot drift
     * apart on it — the same reason asset_onboarding_option_label() exists.
     */
    public function optionLabel(): string
    {
        $parts = [$this->doc_number ?: 'No number'];

        if ($this->doc_date) {
            $parts[] = $this->doc_date->format('d-m-Y');
        }
        if ($this->total !== null) {
            $parts[] = $this->currency.' '.number_format((float) $this->total, 2);
        }

        return implode(' — ', $parts);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->doc_type] ?? ucfirst((string) $this->doc_type);
    }

    /**
     * Which `.vnd-type-*` colour this document's chip takes. On the model, not the row
     * partial, for the same reason typeLabel() is — a colour keyed by doc_type must not be
     * decided twice and drift.
     */
    public function typeBadgeClass(): string
    {
        return match ($this->doc_type) {
            'invoice' => 'vnd-type-purchase',
            'credit_note' => 'vnd-type-credit',
            default => 'vnd-type-rental',
        };
    }

    /**
     * The proof that this invoice was paid — at most one, guaranteed by a unique index on
     * the slip's side rather than by this relation.
     */
    public function paymentSlip(): HasOne
    {
        return $this->hasOne(VendorPaymentSlip::class, 'vendor_billing_document_id');
    }

    /**
     * Only an invoice has a payment status.
     *
     * A quotation is an offer: it is accepted or it is not, and it is never "pending
     * payment". Reading Pending against one for the rest of its life would state that we
     * owe money on a document nobody has acted on — which is why the payment-slip picker
     * offers invoices only, and why the Status cell says nothing at all for a quotation.
     */
    public function carriesPaymentStatus(): bool
    {
        return $this->doc_type === 'invoice';
    }

    /**
     * Settled, on the evidence — a payment slip is filed against it.
     *
     * Deliberately the ONLY definition of paid. The old hand-set `status` column could say
     * 'paid' with nothing attached, which on a register whose entire value is provenance is
     * an assertion with no document behind it.
     */
    public function isPaid(): bool
    {
        return $this->carriesPaymentStatus() && $this->paymentSlip !== null;
    }

    /**
     * How the Status cell reads: Paid, Pending, or nothing at all for a quotation.
     *
     * One place, because the cell, the flash messages and the assistant's recorded-fields
     * block all have to describe one document the same way.
     *
     * @return array{label:string,color:?string,note:string}
     */
    public function paymentState(): array
    {
        if (! $this->carriesPaymentStatus()) {
            return [
                'label' => '—',
                'color' => null,
                'note' => 'A quotation is an offer, not a bill — only invoices carry a payment status.',
            ];
        }

        if ($slip = $this->paymentSlip) {
            $when = $slip->paid_on ? ' on '.fmt_date($slip->paid_on) : '';

            return [
                'label' => 'Paid',
                'color' => 'success',
                'note' => 'A payment slip is filed against this invoice'.$when.'.',
            ];
        }

        return [
            'label' => 'Pending',
            'color' => 'secondary',
            'note' => 'No payment slip has been filed against this invoice yet.',
        ];
    }

    /**
     * Past its due date with nothing paid against it.
     *
     * Reads the payment slip rather than the retired `status` column, so an invoice stops
     * being overdue at the moment its proof of payment is filed — the one event that can
     * settle it.
     */
    public function isOverdue(): bool
    {
        return $this->carriesPaymentStatus()
            && $this->due_date !== null
            && $this->due_date->isPast()
            && ! $this->isPaid();
    }

    /**
     * The document carries an SST line that the vendor's registration says they may not
     * charge us. Returns null when there is nothing to say — no SST on the document, or
     * the exemption can't be evaluated because one side's category is unrecorded.
     *
     * A flag, never a block: the vendor's SST category is master data that can be stale
     * or mis-keyed, and refusing to file a real invoice over it would be worse than
     * showing the discrepancy to the person who can check it.
     */
    public function sstFlag(): ?string
    {
        if ($this->sst_amount === null || (float) $this->sst_amount <= 0) {
            return null;
        }

        $vendor = $this->vendor;
        if (! $vendor) {
            return null;
        }

        $verdict = $vendor->sstVerdict();
        if (! in_array($verdict['state'], ['exempt', 'not_registered'], true)) {
            return null;
        }

        return 'This document charges SST, but '.$verdict['reason'];
    }

    /**
     * 'quotation', 'credit_note' or 'invoice' — which prompt the summariser frames this
     * document with. Read off doc_type rather than hard-coded: the three are summarised
     * differently (an offer's validity period vs a bill's due date vs what a credit relates
     * to and why).
     */
    public function aiKind(): string
    {
        return match ($this->doc_type) {
            'quotation' => 'quotation',
            'credit_note' => 'credit_note',
            default => 'invoice',
        };
    }

    /** How the assistant names this document when it cites it. */
    public function aiLabel(): string
    {
        return $this->typeLabel().' '.($this->doc_number ?: '(no number)')
            .($this->doc_date ? ' dated '.$this->doc_date->format('d-m-Y') : '');
    }
}
