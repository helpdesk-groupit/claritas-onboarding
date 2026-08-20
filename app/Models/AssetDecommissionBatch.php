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

    /**
     * The one status lifecycle.
     *
     * `pending_approval` / `approved` / `rejected` replaced `finance_approved` /
     * `finance_rejected` in Phase 5: the decision that moves a cycle is MANAGEMENT's, so a
     * status naming Finance as the decider would misstate who authorised the disposal. The old
     * two are kept in the list because cycles decided under the previous rule still carry them
     * and must keep rendering as decided — nothing writes them any more.
     */
    public const STATUSES = [
        'e_waste' => [
            'awaiting_quotation', 'quotation_uploaded', 'pending_approval', 'approved', 'rejected',
            'collected', 'completed', 'cancelled',
            'finance_approved', 'finance_rejected',   // legacy, read-only
        ],
    ];

    /** Statuses that mean "this cycle has been authorised and may proceed to collection". */
    public const APPROVED_STATUSES = ['approved', 'finance_approved'];

    protected $fillable = [
        'batch_number', 'type', 'company', 'vendor_id', 'status', 'report_pdf_path', 'created_by', 'finalized_at', 'notes',
        'rfq_sent_at', 'finance_report_sent_at',
        'quotation_path', 'quotation_amount', 'quotation_uploaded_at', 'quotation_uploaded_by',
        'finance_status', 'finance_reviewed_by', 'finance_reviewed_at', 'finance_remarks',
        'management_status', 'management_reviewed_by', 'management_reviewed_at', 'management_remarks',
        'recommended_quotation_id', 'recommendation_note', 'selected_quotation_id', 'submitted_for_approval_at',
        'receipt_path', 'receipt_amount', 'receipt_uploaded_at', 'receipt_uploaded_by',
        'ai_recommended_quotation_id', 'ai_recommendation_note', 'ai_recommended_at', 'ai_compare_status',
    ];

    protected $casts = [
        'finalized_at' => 'datetime',
        'rfq_sent_at' => 'datetime',
        'finance_report_sent_at' => 'datetime',
        'quotation_uploaded_at' => 'datetime',
        'finance_reviewed_at' => 'datetime',
        'management_reviewed_at' => 'datetime',
        'submitted_for_approval_at' => 'datetime',
        'receipt_uploaded_at' => 'datetime',
        'quotation_amount' => 'decimal:2',
        'receipt_amount' => 'decimal:2',
        'ai_recommended_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /**
     * The vendor management actually decided on, not the RFQ placeholder — display code should
     * read this rather than `vendor?->name` directly. `vendor_id` is kept in sync with it
     * (syncQuotationCache()) purely so the FK still resolves for old code paths; this reads the
     * source of truth directly.
     *
     * Falls back to the cached `vendor_id` only for a finalized/completed legacy cycle that
     * predates `selected_quotation_id` — there the cache IS the only record of who collected.
     */
    public function decidedVendorName(): ?string
    {
        if ($this->selectedQuotation) {
            return $this->selectedQuotation->vendorName();
        }

        if ($this->isFinalized() || $this->status === 'completed') {
            return $this->vendor?->name;
        }

        return null;
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

    /** Who in management decided. Null on a legacy cycle settled under the single-approval rule. */
    public function managementReviewer()
    {
        return $this->belongsTo(User::class, 'management_reviewed_by');
    }

    /** IT's proposal — normally the best offer, with recommendation_note saying why. */
    public function recommendedQuotation()
    {
        return $this->belongsTo(AssetDecommissionQuotation::class, 'recommended_quotation_id');
    }

    /** What management actually authorised. May be a different vendor's offer. */
    public function selectedQuotation()
    {
        return $this->belongsTo(AssetDecommissionQuotation::class, 'selected_quotation_id');
    }

    /**
     * What the AI comparison last suggested. A SUGGESTION only — it pre-fills IT's Recommend
     * form on the cycle page, but recommended_quotation_id (set by submitForApproval()) is
     * still the only thing the module treats as IT's actual recommendation.
     */
    public function aiRecommendedQuotation()
    {
        return $this->belongsTo(AssetDecommissionQuotation::class, 'ai_recommended_quotation_id');
    }

    /** The management approvers for this cycle's company. */
    public function managementApprovers()
    {
        return EwasteCompanyApprover::approversFor($this->company);
    }

    /**
     * The entity this cycle's paperwork is issued in the name of — the company that OWNS
     * the assets, confirmed at inspection against the registered companies list.
     *
     * Every vendor-facing surface (the RFQ email, the report PDF's letterhead) must read
     * this rather than config('decommission.org_name'): a cycle has been per-company since
     * Phase 4, so the fixed group name asked a vendor to quote on one entity's assets while
     * naming another as the party they would pay. Same reasoning that took the letterhead off
     * the rental AARF — the document concerns whoever owns the assets, not whoever hosts the
     * portal.
     *
     * The config value survives only as the fallback for a company-less legacy batch, and as
     * the sender identity on emails that belong to no single cycle.
     */
    public function issuingCompany(): string
    {
        return filled($this->company)
            ? $this->company
            : (string) config('decommission.org_name');
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

    /**
     * Finance left remarks with no verdict either way — a state legacy rows can still carry
     * from before the mandatory gate was reinstated. Nothing writes it any more: Finance's
     * decision is now a required approve/reject, not an optional comment.
     */
    public function financeCommented(): bool
    {
        return $this->finance_status === 'noted';
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
            'awaiting_quotation' => ['secondary', 'Awaiting vendor quotations'],
            'quotation_uploaded' => ['secondary', 'Quotations in — not yet submitted'],
            'pending_approval' => ['warning', 'Pending approval'],
            'approved', 'finance_approved' => ['primary', 'Awaiting collection & payment'],
            'rejected', 'finance_rejected' => ['danger', 'Rejected — awaiting revised quote'],
            'collected' => ['info', 'Payment received — finalising'],
            default => $this->statusBadge(),
        };
    }

    // ── Approval state (Phase 5) ──────────────────────────────────────────────

    public function isApproved(): bool
    {
        return in_array($this->status, self::APPROVED_STATUSES, true);
    }

    public function isAwaitingDecision(): bool
    {
        return $this->status === 'pending_approval';
    }

    /**
     * Management's decision is the one that moves the cycle. Finance's is recorded beside it
     * and shown on every screen, but a Finance approval alone releases nothing — which is what
     * keeps a later management rejection able to stop something that has not happened yet.
     */
    public function managementDecided(): bool
    {
        return in_array($this->management_status, ['approved', 'rejected'], true);
    }

    public function financeDecided(): bool
    {
        return in_array($this->finance_status, ['approved', 'rejected'], true);
    }

    /** Badge for Finance's position — "recorded", never "decided the cycle". */
    public function financeDecisionBadge(): array
    {
        return match ($this->finance_status) {
            'approved' => ['success', 'Finance approved'],
            'rejected' => ['danger', 'Finance objected'],
            'pending' => ['warning', 'Finance not yet reviewed'],
            default => ['secondary', 'Not submitted'],
        };
    }

    public function managementDecisionBadge(): array
    {
        return match ($this->management_status) {
            'approved' => ['success', 'Management approved'],
            'rejected' => ['danger', 'Management rejected'],
            'pending' => ['warning', 'Awaiting management'],
            default => ['secondary', 'Not submitted'],
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
            $vendorId = $attributes['vendor_id'] ?? null;

            // Revisions run per VENDOR since Phase 5: vendor A's revision 2 answers vendor A's
            // rejected revision 1 and has nothing to do with vendor B's first offer. Locked
            // like generateBatchNumber() — two concurrent uploads must not claim the same
            // number, which the unique index would reject outright.
            $next = (int) $this->quotations()
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()->max('revision') + 1;

            $revision = $this->quotations()->create([
                'vendor_id' => $vendorId,
                'revision' => $next,
                'path' => $attributes['path'],
                'amount' => $attributes['amount'] ?? null,
                'uploaded_at' => $attributes['uploaded_at'] ?? now(),
                'uploaded_by' => $attributes['uploaded_by'] ?? null,
                'finance_status' => null,   // not "pending" — nothing is under review until IT submits
            ]);

            // Uploading an offer no longer submits it: several vendors are being collected and
            // compared first. It only moves the cycle off "awaiting quotations".
            if ($this->status === 'awaiting_quotation') {
                $this->update(['status' => 'quotation_uploaded']);
            }

            $this->unsetRelation('quotations');

            return $revision;
        });
    }

    /**
     * The comparison set: each vendor's CURRENT offer, best price first.
     *
     * "Best" is the highest amount, because the vendor pays US for scrap — the sign of this
     * comparison is the opposite of a purchase, and getting it backwards would recommend the
     * vendor offering the least money.
     *
     * @return \Illuminate\Support\Collection<int, AssetDecommissionQuotation>
     */
    public function quotationsForComparison()
    {
        $all = $this->relationLoaded('quotations') ? $this->quotations : $this->quotations()->get();

        return $all->groupBy(fn ($q) => $q->vendor_id ?? 0)
            ->map(fn ($perVendor) => $perVendor->sortBy('revision')->last())
            ->sortByDesc(fn ($q) => $q->amount === null ? -1 : (float) $q->amount)
            ->values();
    }

    /** The offer that pays us most, or null when nothing has been quoted with an amount. */
    public function bestOffer(): ?AssetDecommissionQuotation
    {
        return $this->quotationsForComparison()->first(fn ($q) => $q->amount !== null);
    }

    /**
     * The comparison set ordered for DISPLAY: once IT has named a recommendation, it leads —
     * so Finance/management read the table as IT's complete case for the disposal, not a race
     * to spot the recommended row further down. Before a recommendation exists this is a no-op
     * and keeps quotationsForComparison()'s normal best-offer-first order.
     */
    public function quotationsForDisplay()
    {
        $comparison = $this->quotationsForComparison();
        $recommended = $this->recommendedQuotation;

        if (! $recommended) {
            return $comparison;
        }

        return collect([$recommended])
            ->merge($comparison->reject(fn ($q) => $q->id === $recommended->id))
            ->values();
    }

    /** Every vendor's current offer carries a figure — the precondition for comparing them. */
    public function everyQuotationHasAnAmount(): bool
    {
        $set = $this->quotationsForComparison();

        return $set->isNotEmpty() && $set->every(fn ($q) => $q->amount !== null);
    }

    /**
     * Stamp a Finance decision on the current revision AND the batch's cache columns, so the
     * per-revision history and the cache every other screen reads can never disagree.
     *
     * A legacy batch with no revision row still gets its cache updated — the decision is
     * never lost just because the history table postdates the cycle.
     *
     * Finance's decision is one of two mandatory, independent gates (see
     * recordManagementDecision() for management's) — reinstated as a real, binding gate rather
     * than an advisory position: either party rejecting sends the cycle to 'rejected' outright,
     * and full approval requires BOTH to have approved. See reconcileApprovalStatus().
     */
    public function recordFinanceDecision(string $status, ?int $reviewerId, ?string $remarks): void
    {
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Unsupported finance decision [{$status}].");
        }

        DB::transaction(function () use ($status, $reviewerId, $remarks) {
            $at = now();

            // Stamped on the quotation under review, so the decision travels with the document
            // it was made about rather than with whatever is current later.
            $this->quotationUnderReview()?->update([
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
            ]);

            $this->reconcileApprovalStatus();
            $this->unsetRelation('quotations');
        });

        $this->refresh();
    }

    /**
     * The quotation a decision applies to: what management selected, else what IT recommended.
     * Distinct from currentQuotation(), which answers "the newest document on file".
     */
    public function quotationUnderReview(): ?AssetDecommissionQuotation
    {
        return $this->selectedQuotation ?? $this->recommendedQuotation ?? $this->currentQuotation();
    }

    /**
     * IT put the comparison up for approval, naming the offer they recommend and why.
     *
     * This is the moment the cycle becomes reviewable — before it, quotations are still being
     * gathered, and a half-collected comparison shown to an approver invites a decision on a
     * field of one.
     */
    public function submitForApproval(AssetDecommissionQuotation $recommended, ?string $note, ?int $actorId): void
    {
        DB::transaction(function () use ($recommended, $note) {
            $this->update([
                'recommended_quotation_id' => $recommended->id,
                'recommendation_note' => $note,
                'selected_quotation_id' => null,
                'status' => 'pending_approval',
                'submitted_for_approval_at' => now(),
                // Both sides are asked at the same time; either may answer first.
                'finance_status' => 'pending',
                'finance_reviewed_by' => null,
                'finance_reviewed_at' => null,
                'finance_remarks' => null,
                'management_status' => 'pending',
                'management_reviewed_by' => null,
                'management_reviewed_at' => null,
                'management_remarks' => null,
            ]);

            $this->quotations()->where('id', $recommended->id)->update(['finance_status' => 'pending']);
            $this->syncQuotationCache($recommended);
            $this->unsetRelation('quotations');
        });

        $this->refresh();
    }

    /**
     * Management's decision — the other of the two mandatory, independent gates (see
     * recordFinanceDecision() for Finance's). Neither is sequenced ahead of the other; whoever
     * decides second is the one whose write can actually move the cycle to 'approved' or
     * 'rejected' — see reconcileApprovalStatus().
     *
     * $selected lets management authorise a DIFFERENT vendor's offer than the one IT put
     * forward — "or go with other company choices". Both are kept on the row, because what we
     * recommended and what was approved are different facts and the gap between them is
     * exactly what the final report has to show.
     */
    public function recordManagementDecision(
        string $status,
        ?int $reviewerId,
        ?string $remarks,
        ?AssetDecommissionQuotation $selected = null,
    ): void {
        if (! in_array($status, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Unsupported management decision [{$status}].");
        }

        DB::transaction(function () use ($status, $reviewerId, $remarks, $selected) {
            $at = now();
            $winner = $status === 'approved'
                ? ($selected ?? $this->recommendedQuotation ?? $this->currentQuotation())
                : null;

            $this->update([
                'management_status' => $status,
                'management_reviewed_by' => $reviewerId,
                'management_reviewed_at' => $at,
                'management_remarks' => $remarks,
                'selected_quotation_id' => $winner?->id ?? $this->selected_quotation_id,
            ]);

            if ($winner) {
                $this->syncQuotationCache($winner);
            }

            $this->reconcileApprovalStatus();
            $this->unsetRelation('quotations');
        });

        $this->refresh();
    }

    /**
     * Recompute the cycle's overall status from the two independent verdicts, after either one
     * is written. A REJECT from either side wins outright — the disposal becomes 'rejected'
     * whatever the other party said or has yet to say. Full approval requires BOTH to have
     * approved. Anything short of that (one approved and the other still pending, or neither
     * has decided) leaves the cycle at 'pending_approval', which is what keeps it open for
     * whichever party has not yet acted.
     *
     * Called from inside the same transaction as the write that changed a verdict, so the two
     * mandatory gates and the derived status can never disagree.
     */
    protected function reconcileApprovalStatus(): void
    {
        if ($this->finance_status === 'rejected' || $this->management_status === 'rejected') {
            $this->update(['status' => 'rejected']);
        } elseif ($this->finance_status === 'approved' && $this->management_status === 'approved') {
            $this->update(['status' => 'approved']);
        } else {
            $this->update(['status' => 'pending_approval']);
        }
    }

    /** True once BOTH mandatory gates have approved — the only state that authorises the disposal. */
    public function fullyApproved(): bool
    {
        return $this->finance_status === 'approved' && $this->management_status === 'approved';
    }

    /**
     * Point the batch's cache columns at one quotation.
     *
     * Those columns are read by the Finance listing, the report renderer, both mailables and
     * reportAmount(); with several vendors on a cycle they must follow the offer that is
     * actually in play (selected, else recommended) — never simply "the newest upload", which
     * after a re-quote by a losing vendor would be an offer nobody chose.
     */
    protected function syncQuotationCache(AssetDecommissionQuotation $quotation): void
    {
        $this->update([
            'quotation_path' => $quotation->path,
            'quotation_amount' => $quotation->amount,
            'quotation_uploaded_at' => $quotation->uploaded_at,
            'quotation_uploaded_by' => $quotation->uploaded_by,
            'vendor_id' => $quotation->vendor_id ?? $this->vendor_id,
        ]);
    }

    /**
     * Correct (or clear) the quotation amount on the current revision and the cache together.
     * A null means "see the attached document" — never 0.00, which would state that the
     * vendor pays us nothing.
     */
    public function setQuotationAmount(?float $amount, ?AssetDecommissionQuotation $quotation = null): void
    {
        DB::transaction(function () use ($amount, $quotation) {
            $target = $quotation ?? $this->quotationUnderReview();
            $target?->update(['amount' => $amount]);

            // The copy filed on the vendor's Contracts tab carries the same figure, so a
            // correction here has to reach it or the two records state different offers for
            // one document. Only the FIGURE travels — the stored PDF is what the vendor
            // actually sent and is never rewritten; the filed row's STATE is derived from this
            // cycle on every read, so there is nothing else to keep in step.
            $target?->filedContract?->update(['contract_value' => $amount]);

            // The cache only follows when the corrected offer is the one in play. Correcting a
            // losing vendor's figure must not rewrite the amount on the report, which states
            // what the SELECTED vendor is paying us.
            //
            // With NO revision row at all the cache IS the record — a cycle whose quotation
            // predates the revision table keeps its figure only there, and refusing to write
            // it would make those amounts uncorrectable.
            $inPlay = $this->selected_quotation_id ?? $this->recommended_quotation_id;
            if (! $target || $inPlay === null || $inPlay === $target->id) {
                $this->update(['quotation_amount' => $amount]);
            }

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
    public static function generateBatchNumber(string $type = self::TYPE_EWASTE, ?Carbon $date = null, ?string $company = null): string
    {
        $date = $date ?? now();
        $prefix = config('decommission.batch_prefixes.e_waste', 'EWA');

        return DB::transaction(function () use ($date, $prefix, $company) {
            $base = sprintf('%s-%d-Q%d', $prefix, $date->year, $date->quarter);

            // One cycle per company since Phase 4, so the reference says WHICH entity's assets
            // it covers — EWA-2026-Q3-CLA vs EWA-2026-Q3-ENL. A company-less batch (legacy, or
            // a fixture) keeps the original numbering rather than growing a meaningless token.
            if ($token = static::companyToken($company)) {
                $base .= '-'.$token;
            }

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

    /**
     * A short alphabetic token for the company, for the batch reference.
     *
     * Legal-form words are dropped so "Claritas Asia Sdn Bhd" reads CLA rather than the same
     * SDN as everyone else. Two companies CAN still collide (two "Claritas …" entities both
     * give CLA); that is fine and deliberate — the numeric suffix in generateBatchNumber()
     * keeps the reference unique, and `company` on the row is what actually identifies the
     * entity. The token is a convenience for humans reading a reference, never the source
     * of truth, so nothing may branch on it.
     */
    public static function companyToken(?string $company): ?string
    {
        $clean = preg_replace('/[^a-z0-9 ]/i', ' ', (string) $company);
        $legalForms = ['sdn', 'bhd', 'berhad', 'pte', 'ltd', 'limited', 'plc', 'inc',
            'corp', 'corporation', 'company', 'co', 'group', 'holdings', 'enterprise', 'enterprises'];

        $words = array_values(array_filter(
            preg_split('/\s+/', strtolower(trim($clean))) ?: [],
            fn ($w) => $w !== '' && ! in_array($w, $legalForms, true)
        ));

        if (! $words) {
            return null;
        }

        return strtoupper(substr($words[0], 0, 3));
    }
}
