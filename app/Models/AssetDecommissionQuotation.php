<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One quotation revision inside an e-waste cycle, with the Finance decision made on it.
 *
 * A cycle can go round this loop more than once — IT uploads the vendor's offer, Finance
 * rejects it with a reason, the vendor re-quotes, IT uploads the new document. Each pass is
 * its own row, so the rejected offer and the reason it was refused survive the re-quote
 * instead of being overwritten by it.
 *
 * Rows are created through AssetDecommissionBatch::addQuotationRevision(), which also keeps
 * the batch's cache columns pointing at the newest revision — never insert one directly.
 */
class AssetDecommissionQuotation extends Model
{
    protected $table = 'asset_decommission_quotations';

    protected $fillable = [
        'asset_decommission_batch_id', 'vendor_id', 'revision', 'path', 'amount', 'uploaded_at', 'uploaded_by',
        'finance_status', 'finance_reviewed_by', 'finance_reviewed_at', 'finance_remarks',
    ];

    protected $casts = [
        'revision' => 'integer',
        'amount' => 'decimal:2',
        'uploaded_at' => 'datetime',
        'finance_reviewed_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────
    public function batch()
    {
        return $this->belongsTo(AssetDecommissionBatch::class, 'asset_decommission_batch_id');
    }

    /**
     * The vendor whose offer this is.
     *
     * Null only on a legacy revision from a cycle that had no vendor on file — the RFQ was
     * skipped because no e-waste vendor could be reached. It is deliberately not guessed:
     * nothing in the data says who sent that document.
     */
    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function vendorName(): string
    {
        return $this->vendor?->name ?? 'Vendor not recorded';
    }

    /** Who uploaded this revision (null on the revision backfilled from a legacy batch). */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function financeReviewer()
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
    }

    /**
     * The copy filed onto the vendor's Contracts tab, if one was made.
     *
     * hasOne rather than hasMany: the column carries a UNIQUE index, so one revision can only
     * ever have produced one filed document.
     */
    public function filedContract()
    {
        return $this->hasOne(VendorContract::class, 'asset_decommission_quotation_id');
    }

    // ── State helpers ─────────────────────────────────────────────────────────
    public function isPending(): bool
    {
        return $this->finance_status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->finance_status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->finance_status === 'rejected';
    }

    /** Bootstrap badge [class, label] for this revision's Finance decision. */
    public function decisionBadge(): array
    {
        return match ($this->finance_status) {
            'approved' => ['success', 'Approved by Finance'],
            'rejected' => ['danger', 'Rejected by Finance'],
            'pending' => ['warning', 'Awaiting Finance review'],
            default => ['secondary', 'Not submitted for review'],
        };
    }

    /**
     * One line naming the decision, its author and when — for the report body and the
     * caption on the reproduced document page.
     *
     * Returns null when no decision was ever recorded, so callers state that rather than
     * printing a decision nobody made.
     */
    public function decisionLine(): ?string
    {
        if (! $this->finance_status || $this->finance_status === 'pending') {
            return null;
        }

        $who = AssetDecommissionBatch::actorIdentity($this->financeReviewer);
        $line = ($this->isApproved() ? 'Approved' : 'Rejected').' by Finance';

        if ($this->finance_reviewed_at) {
            $line .= ' on '.fmt_datetime($this->finance_reviewed_at);
        }

        if ($who) {
            $line .= ' by '.$who['name'].($who['details'] ? ' ('.$who['details'].')' : '');
        }

        return $line.($this->finance_remarks ? ' — '.$this->finance_remarks : '');
    }

    /**
     * Replaced by a later offer from the SAME vendor — i.e. this document is history because
     * they re-quoted, not because somebody else won.
     *
     * Deliberately distinct from "not selected": a competing vendor's offer that lost is still
     * the last thing that vendor said, and labelling it "Superseded" would suggest they sent a
     * revision they never sent.
     */
    public function isSupersededByOwnVendor(): bool
    {
        $siblings = $this->relationLoaded('batch') && $this->batch?->relationLoaded('quotations')
            ? $this->batch->quotations
            : static::where('asset_decommission_batch_id', $this->asset_decommission_batch_id)->get();

        return $siblings->contains(
            fn ($q) => $q->vendor_id === $this->vendor_id && (int) $q->revision > (int) $this->revision
        );
    }

    /**
     * Where this document stands in its cycle — the badge the vendor's Contracts tab shows.
     *
     * DERIVED on every read rather than stored on the filed contract. The filed row is a copy
     * of a document whose real state lives here, and a stored status would be one more thing
     * that can fall out of step with the cycle it describes; there is nothing to sync because
     * there is nothing duplicated.
     *
     * Returns the ASSOCIATIVE shape VendorContract::stateBadge() speaks, not decisionBadge()'s
     * positional pair — the two feed different templates and are not interchangeable.
     *
     * @return array{color:string, label:string}
     */
    public function lifecycleBadge(): array
    {
        $batch = $this->batch;

        if (! $batch) {
            return ['color' => 'secondary', 'label' => 'Cycle not found'];
        }

        // Checked before anything else: a cancelled cycle makes every question below moot, and
        // a document filed under one must never read as an offer still in play.
        if ($batch->status === 'cancelled') {
            return ['color' => 'secondary', 'label' => 'Cancelled cycle'];
        }

        if ($this->isSupersededByOwnVendor()) {
            return ['color' => 'secondary', 'label' => 'Superseded'];
        }

        if ($batch->management_status === 'rejected') {
            return ['color' => 'danger', 'label' => 'Rejected'];
        }

        if ($batch->isApproved() || in_array($batch->status, ['collected', 'completed'], true)) {
            return $batch->selected_quotation_id === $this->id
                ? ['color' => 'success', 'label' => 'Approved']
                : ['color' => 'secondary', 'label' => 'Not selected'];
        }

        if ($batch->status === 'pending_approval') {
            return ['color' => 'warning', 'label' => 'Under review'];
        }

        return ['color' => 'info', 'label' => 'Submitted'];
    }
}
