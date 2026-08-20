<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentInsight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One contract we hold with a vendor.
 *
 * The uploaded document is the record of truth; every stored field is a human's own
 * transcription of it, typed by hand.
 *
 * The `ai_*` columns (HasDocumentInsight) are the ONLY machine reading of the file: the
 * summary shown on the row and the transcription the vendor Q&A assistant answers from.
 * They never touch `scope_summary`, which is the operator's own. A per-field OCR that
 * pre-filled the form used to sit alongside them (`ocr_*`) and was removed on 2026-08-11.
 */
class VendorContract extends Model
{
    use HasDocumentInsight;

    public const STATUSES = [
        'draft' => 'Draft',
        'active' => 'Active',
        'expired' => 'Expired',
        'terminated' => 'Terminated',
    ];

    public const TYPES = [
        'rental' => 'Rental / Lease',
        'service' => 'Service Agreement',
        'maintenance' => 'Maintenance / Support',
        'supply' => 'Supply / Purchase',
        'subscription' => 'Subscription',
        'nda' => 'NDA',
        'sla' => 'SLA',
        'ewaste_quotation' => 'E-waste Quotation',
        'other' => 'Other',
    ];

    /**
     * Filed automatically from an e-waste cycle, not entered here.
     *
     * A row of this type is a RECORD of a document that lives on a disposal cycle — it has no
     * term, its figure is the cycle's, and its state is the cycle's decision. The Contracts tab
     * renders it read-only for that reason: editing it would alter what a vendor is recorded as
     * having offered on a disposal that may already have been decided on the strength of it.
     */
    public const TYPE_EWASTE_QUOTATION = 'ewaste_quotation';

    public const BILLING_CYCLES = [
        'one_off' => 'One-off',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'half_yearly' => 'Half-yearly',
        'annual' => 'Annual',
    ];

    /** A contract inside this window is flagged as expiring on the vendor page. */
    public const EXPIRY_WARNING_DAYS = 60;

    /** Mirrors the column default; used to coerce a cleared Currency input back to a value. */
    public const DEFAULT_CURRENCY = 'MYR';

    protected $fillable = [
        'vendor_id', 'asset_decommission_quotation_id', 'title', 'contract_reference', 'contract_type', 'status',
        'start_date', 'end_date', 'auto_renew', 'notice_period_days',
        'contract_value', 'currency', 'billing_cycle', 'payment_terms',
        'scope_summary', 'notes',
        'file_path', 'original_filename', 'file_hash', 'created_by',
        'ai_status', 'ai_summary', 'ai_key_points', 'ai_text', 'ai_at',
        'companies_involved',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_renew' => 'boolean',
        'contract_value' => 'decimal:2',
        'notice_period_days' => 'integer',
        'ai_key_points' => 'array',
        'ai_at' => 'datetime',
        'companies_involved' => 'array',
        'ai_summary_edited_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function billingDocuments()
    {
        return $this->hasMany(VendorBillingDocument::class);
    }

    /**
     * The e-waste quotation revision this row was filed from. Null for every ordinary contract.
     */
    public function assetDecommissionQuotation(): BelongsTo
    {
        return $this->belongsTo(AssetDecommissionQuotation::class, 'asset_decommission_quotation_id');
    }

    /** Filed from a disposal cycle rather than entered on this tab. */
    public function isEwasteQuotation(): bool
    {
        return $this->contract_type === self::TYPE_EWASTE_QUOTATION;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->contract_type] ?? ($this->contract_type ?: '—');
    }

    public function billingCycleLabel(): string
    {
        return self::BILLING_CYCLES[$this->billing_cycle] ?? ($this->billing_cycle ?: '—');
    }

    /**
     * Past its end date. Read off the DATE, never off `status` — a contract that lapsed
     * while nobody updated the dropdown is expired whatever the dropdown says.
     */
    public function isExpired(): bool
    {
        return $this->end_date !== null && $this->end_date->isPast();
    }

    /** Ending within the warning window (and not already ended). */
    public function isExpiringSoon(): bool
    {
        return $this->end_date !== null
            && ! $this->isExpired()
            && $this->end_date->lte(now()->addDays(self::EXPIRY_WARNING_DAYS));
    }

    public function daysToExpiry(): ?int
    {
        return $this->end_date === null ? null : (int) now()->startOfDay()->diffInDays($this->end_date, false);
    }

    /**
     * Bootstrap colour for the live state of the contract — derived, so an untouched
     * `status` can't make an ended contract look current.
     */
    public function stateBadge(): array
    {
        // A filed e-waste quotation has no term, so every date-based test below is inapplicable
        // and the method would fall all the way through to a green "Active" — asserting that a
        // one-off scrap offer is a live agreement, forever. Its real state is the cycle's
        // decision, so that is what it reports.
        if ($this->isEwasteQuotation()) {
            return $this->assetDecommissionQuotation
                ? $this->assetDecommissionQuotation->lifecycleBadge()
                : ['color' => 'secondary', 'label' => 'Filed from a disposal cycle'];
        }

        if ($this->status === 'terminated') {
            return ['color' => 'secondary', 'label' => 'Terminated'];
        }
        if ($this->status === 'draft') {
            return ['color' => 'secondary', 'label' => 'Draft'];
        }
        if ($this->isExpired()) {
            return ['color' => 'danger', 'label' => $this->auto_renew ? 'Ended — auto-renews' : 'Expired'];
        }
        if ($this->isExpiringSoon()) {
            return ['color' => 'warning', 'label' => 'Expiring in '.max(0, (int) $this->daysToExpiry()).'d'];
        }

        return ['color' => 'success', 'label' => 'Active'];
    }

    /** 'contract' — which prompt the summariser frames this document with. */
    public function aiKind(): string
    {
        return 'contract';
    }

    /** How the assistant names this document when it cites it. */
    public function aiLabel(): string
    {
        return 'Contract — '.$this->title
            .($this->contract_reference ? ' (ref. '.$this->contract_reference.')' : '');
    }

    /**
     * SHA-256 of a stored file on the private disk, or null when it is not there to hash.
     * The one place `file_hash` is computed, so every caller that sets `file_path` derives
     * it the same way.
     */
    public static function hashStoredFile(?string $path): ?string
    {
        if (blank($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return hash_file('sha256', Storage::disk('local')->path($path));
    }

    /**
     * Rental assets whose OWN uploaded contract document is byte-identical to this one.
     *
     * There is no `contract_id` on assets and none is being added — a rental contract only
     * ever describes assets in prose (quantities, models, dates), so a manual picker would
     * just be a second place to make the same mistake. This compares file hashes instead:
     * certain, not a guess, because two different files cannot share a SHA-256 digest. An
     * asset whose copy is a rescan of the same physical contract (different bytes) will
     * not match here — nothing on this path claims to read prose.
     *
     * @param  \Illuminate\Support\Collection<int,AssetInventory>  $assets  this vendor's assets (from `$vendor->assets`)
     * @return \Illuminate\Support\Collection<int,AssetInventory>
     */
    public function matchedAssets($assets): \Illuminate\Support\Collection
    {
        if (! $this->file_hash) {
            return collect();
        }

        return $assets
            ->where('ownership_type', 'rental')
            ->filter(fn ($asset) => in_array($this->file_hash, array_values($asset->rental_contract_document_hashes ?? []), true))
            ->values();
    }
}
