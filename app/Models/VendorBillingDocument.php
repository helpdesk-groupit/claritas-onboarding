<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentInsight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A quotation or invoice received from a vendor.
 *
 * This is a DOCUMENT REGISTER, not an AP ledger: nothing here posts a journal entry or
 * creates an acc_bills row, mirroring the "Finance stays lightweight" decision already
 * made for the e-waste cycle. The value is provenance — what they quoted, what they
 * billed, whether SST was on it, and the file itself.
 *
 * The `ai_*` columns (HasDocumentInsight) hold the ONLY machine reading of the file: the
 * summary shown on the row and the transcription the vendor Q&A assistant answers from.
 * The figures are typed by hand — the per-field OCR that pre-filled them (`ocr_*`) was
 * removed on 2026-08-11.
 */
class VendorBillingDocument extends Model
{
    use HasDocumentInsight;

    public const TYPES = [
        'quotation' => 'Quotation',
        'invoice' => 'Invoice',
    ];

    /** Mirrors the column default; used to coerce a cleared Currency input back to a value. */
    public const DEFAULT_CURRENCY = 'MYR';

    /**
     * Deliberately one lifecycle covering both document types — a quotation is
     * accepted/declined, an invoice is paid/disputed, and both start at 'received'.
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
    ];

    protected $casts = [
        'doc_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'sst_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'ai_key_points' => 'array',
        'ai_at' => 'datetime',
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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'accepted', 'paid' => 'success',
            'declined', 'disputed' => 'danger',
            'under_review' => 'warning',
            default => 'secondary',
        };
    }

    public function isOverdue(): bool
    {
        return $this->doc_type === 'invoice'
            && $this->due_date !== null
            && $this->due_date->isPast()
            && ! in_array($this->status, ['paid', 'declined'], true);
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
     * 'quotation' or 'invoice' — which prompt the summariser frames this document with.
     * Read off doc_type rather than hard-coded: the two are summarised differently (an
     * offer's validity period vs a bill's due date).
     */
    public function aiKind(): string
    {
        return $this->doc_type === 'quotation' ? 'quotation' : 'invoice';
    }

    /** How the assistant names this document when it cites it. */
    public function aiLabel(): string
    {
        return $this->typeLabel().' '.($this->doc_number ?: '(no number)')
            .($this->doc_date ? ' dated '.$this->doc_date->format('d-m-Y') : '');
    }
}
