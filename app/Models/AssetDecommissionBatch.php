<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The collection record AND the C-Suite report for an E-WASTE disposal cycle:
 *
 *   awaiting_quotation → quotation_uploaded
 *   → finance_approved | finance_rejected → collected → completed → cancelled
 *
 * Line items live in dispose_assets WHERE decommission_batch_id = <id>.
 *
 * It was `type`-discriminated with a second `vendor_return` lifecycle until 2026-08-10. A
 * rental return is not a disposal — it archives no asset for us, earns nothing and needs no
 * Finance approval — and the business wanted it acknowledged on an Asset Acceptance &
 * Return Form, in person, rather than through an emailed token. It is now a
 * RentalAssetAcknowledgement of type `return`, so nothing creates a vendor_return batch and
 * the constant is gone rather than left behind as a control that looks live and is not.
 *
 * The table's `type` column and the vendor-return columns (`collector_email`,
 * `acknowledgement_token`, `acknowledged_*`) survive in the schema unused — dropping them
 * would need an enum-altering migration for no behavioural gain, and this module's data is
 * not yet deployed anywhere.
 */
class AssetDecommissionBatch extends Model
{
    protected $table = 'asset_decommission_batches';

    public const TYPE_EWASTE = 'e_waste';

    /** The one status lifecycle. */
    public const STATUSES = [
        'e_waste' => ['awaiting_quotation', 'quotation_uploaded', 'finance_approved', 'finance_rejected', 'collected', 'completed', 'cancelled'],
    ];

    protected $fillable = [
        'batch_number', 'type', 'vendor_id', 'status', 'report_pdf_path', 'created_by', 'finalized_at', 'notes',
        'rfq_sent_at', 'finance_report_sent_at',
        'quotation_path', 'quotation_amount', 'quotation_uploaded_at', 'quotation_uploaded_by',
        'finance_status', 'finance_reviewed_by', 'finance_reviewed_at', 'finance_remarks',
        'receipt_path', 'receipt_amount', 'receipt_uploaded_at', 'receipt_uploaded_by',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
        'rfq_sent_at' => 'datetime',
        'finance_report_sent_at' => 'datetime',
        'quotation_uploaded_at' => 'datetime',
        'finance_reviewed_at' => 'datetime',
        'receipt_uploaded_at' => 'datetime',
        'quotation_amount' => 'decimal:2',
        'receipt_amount' => 'decimal:2',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** Snapshot line items (tag/serial/brand/model), one per collected asset. */
    public function items()
    {
        return $this->hasMany(DisposedAsset::class, 'decommission_batch_id');
    }

    /** The live inventory rows collected by this batch (may be soft-archived). */
    public function assets()
    {
        return $this->hasMany(AssetInventory::class, 'decommission_batch_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function financeReviewer()
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
    }

    /** Who uploaded the vendor's quotation (null on rows created before the actor was captured). */
    public function quotationUploader()
    {
        return $this->belongsTo(User::class, 'quotation_uploaded_by');
    }

    /**
     * Every quotation revision in this cycle, oldest first — the audit trail of the
     * re-quote loop (offer → Finance rejects → vendor re-quotes → offer → approved).
     *
     * The batch's own quotation_* / finance_* columns are a cache of the LAST row here;
     * see addQuotationRevision().
     */
    public function quotations()
    {
        return $this->hasMany(AssetDecommissionQuotation::class, 'asset_decommission_batch_id')
            ->orderBy('revision');
    }

    /** Who uploaded the payment receipt (null on rows created before the actor was captured). */
    public function receiptUploader()
    {
        return $this->belongsTo(User::class, 'receipt_uploaded_by');
    }

    /**
     * "Name · Designation · Department · Company" for a recorded actor, mirroring the
     * eClaim digital sign-off convention (partials/claim-signoffs). Returns null when
     * no actor was recorded, so callers can fall back to a timestamp-only line rather
     * than printing an empty name.
     *
     * @return array{name:string, details:string}|null
     */
    public static function actorIdentity(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $employee = $user->employee;

        return [
            'name' => $employee?->full_name ?: $user->name,
            'details' => collect([$employee?->designation, $employee?->department, $employee?->company])
                ->filter()
                ->implode(' · '),
        ];
    }

    // ── Type / state helpers ────────────────────────────────────────────────
    public function isEwaste(): bool
    {
        return $this->type === self::TYPE_EWASTE;
    }

    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function financePending(): bool
    {
        return $this->finance_status === 'pending';
    }

    public function financeApproved(): bool
    {
        return $this->finance_status === 'approved';
    }

    public function financeRejected(): bool
    {
        return $this->finance_status === 'rejected';
    }

    public function typeLabel(): string
    {
        return 'E-waste';
    }

    /** Bootstrap badge [class, label] for the current status. */
    public function statusBadge(): array
    {
        return match ($this->status) {
            'awaiting_quotation' => ['warning', 'Awaiting Quotation'],
            'quotation_uploaded' => ['info', 'Quotation Uploaded'],
            'finance_approved' => ['primary', 'Finance Approved'],
            'finance_rejected' => ['danger', 'Finance Rejected'],
            'collected' => ['primary', 'Collected — receipt uploaded'],
            'completed' => ['success', 'Completed'],
            'cancelled' => ['dark', 'Cancelled'],
            default => ['secondary', ucfirst(str_replace('_', ' ', (string) $this->status))],
        };
    }

    /**
     * Finance-facing stage of an e-waste cycle: [badge class, label].
     *
     * Deliberately separate from statusBadge(), which names the raw lifecycle state for the
     * IT screens. Finance needs to know what it is WAITING ON, and the two readings differ
     * most at exactly the point that matters: `finance_approved` is "Finance Approved" to IT
     * (their action is done) but to Finance the cycle is still open — the vendor has yet to
     * collect the assets and pay. Keep new wording here, not in statusBadge().
     */
    public function ewasteStageBadge(): array
    {
        if ($this->isFinalized() || $this->status === 'completed') {
            return ['success', 'Completed'];
        }

        return match ($this->status) {
            'awaiting_quotation' => ['secondary', 'Awaiting vendor quotation'],
            'quotation_uploaded' => ['warning', 'Pending Finance approval'],
            'finance_approved' => ['primary', 'Awaiting collection & payment'],
            'finance_rejected' => ['danger', 'Rejected — awaiting revised quote'],
            'collected' => ['info', 'Payment received — finalising'],
            default => $this->statusBadge(),
        };
    }

    /**
     * The RM amount shown on the report — the vendor pays US for e-waste, so this is
     * income. Null means "see the attached document"; it is never 0.00, which would state
     * as fact that the vendor paid nothing.
     */
    public function reportAmount(): ?float
    {
        return $this->receipt_amount !== null ? (float) $this->receipt_amount : ($this->quotation_amount !== null ? (float) $this->quotation_amount : null);
    }

    // ── Quotation revisions (the re-quote loop) ──────────────────────────────
    /**
     * The revision the cycle currently stands on — the newest one, whether Finance has
     * decided on it or not. Uses the loaded relation when there is one so a listing that
     * eager-loads `quotations` does not fire a query per row.
     */
    public function currentQuotation(): ?AssetDecommissionQuotation
    {
        // reorder() first: quotations() carries orderBy('revision') ASC, and merely APPENDING
        // a desc clause leaves `ORDER BY revision ASC, revision DESC` — MySQL honours the
        // first, so this silently returned revision 1. That made every Finance decision land
        // on the oldest revision, overwriting the rejection it was meant to sit after.
        return $this->relationLoaded('quotations')
            ? $this->quotations->last()
            : $this->quotations()->reorder('revision', 'desc')->first();
    }

    /** The revisions the vendor replaced — every one but the current, oldest first. */
    public function supersededQuotations()
    {
        $all = $this->relationLoaded('quotations') ? $this->quotations : $this->quotations()->get();

        return $all->slice(0, max(0, $all->count() - 1))->values();
    }

    /**
     * True when this cycle went round the re-quote loop at least once, i.e. there is
     * history to show beyond the quotation that is on the table now.
     */
    public function hasQuotationHistory(): bool
    {
        return $this->quotationRevisionCount() > 1;
    }

    public function quotationRevisionCount(): int
    {
        return $this->relationLoaded('quotations')
            ? $this->quotations->count()
            : $this->quotations()->count();
    }

    /** The most recent rejected revision, for "why am I looking at a second quote?" context. */
    public function lastRejectedQuotation(): ?AssetDecommissionQuotation
    {
        $all = $this->relationLoaded('quotations') ? $this->quotations : $this->quotations()->get();

        return $all->filter(fn ($q) => $q->isRejected())->last();
    }

    /**
     * Record a new quotation revision and re-point the batch's cache columns at it.
     *
     * The cache reset here is the whole reason this method exists: clearing
     * finance_status / finance_reviewed_* / finance_remarks on the batch is correct (the new
     * offer has not been reviewed), but doing it when those columns were the ONLY record of
     * the decision is what erased the rejection. The prior decision now lives on its own
     * revision row and is untouched by this write.
     *
     * @param  array{path:string, amount:?float, uploaded_at:?\Carbon\Carbon, uploaded_by:?int}  $attributes
     */
    public function addQuotationRevision(array $attributes): AssetDecommissionQuotation
    {
        return DB::transaction(function () use ($attributes) {
            // Locked like generateBatchNumber(): two concurrent uploads must not both claim
            // the same revision number (the unique index would reject the loser outright).
            $next = (int) $this->quotations()->lockForUpdate()->max('revision') + 1;

            $revision = $this->quotations()->create([
                'revision' => $next,
                'path' => $attributes['path'],
                'amount' => $attributes['amount'] ?? null,
                'uploaded_at' => $attributes['uploaded_at'] ?? now(),
                'uploaded_by' => $attributes['uploaded_by'] ?? null,
                'finance_status' => 'pending',
            ]);

            $this->update([
                'quotation_path' => $revision->path,
                'quotation_amount' => $revision->amount,
                'quotation_uploaded_at' => $revision->uploaded_at,
                'quotation_uploaded_by' => $revision->uploaded_by,
                'status' => 'quotation_uploaded',
                'finance_status' => 'pending',
                'finance_reviewed_by' => null,
                'finance_reviewed_at' => null,
                'finance_remarks' => null,
            ]);

            $this->unsetRelation('quotations');

            return $revision;
        });
    }

    /**
     * Stamp a Finance decision on the current revision AND the batch's cache columns, so the
     * per-revision history and the cache every other screen reads can never disagree.
     *
     * A legacy batch with no revision row still gets its cache updated — the decision is
     * never lost just because the history table postdates the cycle.
     */
    public function recordFinanceDecision(string $status, ?int $reviewerId, ?string $remarks): void
    {
        $batchStatus = match ($status) {
            'approved' => 'finance_approved',
            'rejected' => 'finance_rejected',
            default => throw new \InvalidArgumentException("Unsupported finance decision [{$status}]."),
        };

        DB::transaction(function () use ($status, $batchStatus, $reviewerId, $remarks) {
            $at = now();

            $this->currentQuotation()?->update([
                'finance_status' => $status,
                'finance_reviewed_by' => $reviewerId,
                'finance_reviewed_at' => $at,
                'finance_remarks' => $remarks,
            ]);

            $this->update([
                'finance_status' => $status,
                'finance_reviewed_by' => $reviewerId,
                'finance_reviewed_at' => $at,
                'finance_remarks' => $remarks,
                'status' => $batchStatus,
            ]);

            $this->unsetRelation('quotations');
        });
    }

    /**
     * Correct (or clear) the quotation amount on the current revision and the cache together.
     * A null means "see the attached document" — never 0.00, which would state that the
     * vendor pays us nothing.
     */
    public function setQuotationAmount(?float $amount): void
    {
        DB::transaction(function () use ($amount) {
            $this->currentQuotation()?->update(['amount' => $amount]);
            $this->update(['quotation_amount' => $amount]);
            $this->unsetRelation('quotations');
        });
    }

    // ── Batch-number generation ──────────────────────────────────────────────
    /**
     * EWA-YYYY-QN — year + quarter, with a -2/-3 suffix on the rare second cycle in one
     * quarter. Wrapped in a locked transaction so two concurrent creates never collide.
     *
     * `$type` is kept in the signature although only e-waste remains, because every caller
     * passes it and the parameter is what makes the batch-number scheme legible at the call
     * site. Rental returns are numbered separately by
     * RentalAssetAcknowledgement::generateReference() (RTA-YYYY-NNNN).
     */
    public static function generateBatchNumber(string $type = self::TYPE_EWASTE, ?Carbon $date = null): string
    {
        $date = $date ?? now();
        $prefix = config('decommission.batch_prefixes.e_waste', 'EWA');

        return DB::transaction(function () use ($date, $prefix) {
            $base = sprintf('%s-%d-Q%d', $prefix, $date->year, $date->quarter);
            if (! static::where('batch_number', $base)->lockForUpdate()->exists()) {
                return $base;
            }

            $n = 2;
            while (static::where('batch_number', $base.'-'.$n)->lockForUpdate()->exists()) {
                $n++;
            }

            return $base.'-'.$n;
        });
    }
}
