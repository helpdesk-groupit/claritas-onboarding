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
        'asset_decommission_batch_id', 'revision', 'path', 'amount', 'uploaded_at', 'uploaded_by',
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

    /** Who uploaded this revision (null on the revision backfilled from a legacy batch). */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function financeReviewer()
    {
        return $this->belongsTo(User::class, 'finance_reviewed_by');
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
}
