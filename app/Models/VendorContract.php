<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentInsight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'other' => 'Other',
    ];

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
        'vendor_id', 'title', 'contract_reference', 'contract_type', 'status',
        'start_date', 'end_date', 'auto_renew', 'notice_period_days',
        'contract_value', 'currency', 'billing_cycle', 'payment_terms',
        'scope_summary', 'notes',
        'file_path', 'original_filename', 'created_by',
        'ai_status', 'ai_summary', 'ai_key_points', 'ai_text', 'ai_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'auto_renew' => 'boolean',
        'contract_value' => 'decimal:2',
        'notice_period_days' => 'integer',
        'ai_key_points' => 'array',
        'ai_at' => 'datetime',
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
}
