<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisposedAsset extends Model
{
    protected $table = 'dispose_assets';

    /**
     * Completeness of a "Not Good" (e-waste) asset — drives the vendor's disposal price.
     *   complete   → all spare parts intact (battery, RAM, storage, …)
     *   incomplete → some parts removed
     */
    public const COMPLETENESS = [
        'complete' => 'Complete',
        'incomplete' => 'Incomplete',
    ];

    protected $fillable = [
        'asset_inventory_id', 'asset_tag', 'asset_type',
        'brand', 'model', 'serial_number',
        'asset_condition', 'reason', 'disposed_by', 'disposed_at', 'remarks',
        // Decommissioning routing (added 2026-07)
        'decommission_type', 'decommission_batch_id',
        // E-waste completeness flag + removed-parts list (added 2026-07-28). Set ONLY by an
        // inspection since 2026-08-13 — the asset form no longer offers or accepts them.
        'ewaste_completeness', 'ewaste_parts_removed',
        // Inspection (Phase 2) — the act that decides completeness and confirms the owner
        'inspected_at', 'inspected_by', 'company',
    ];

    protected $casts = [
        'disposed_at' => 'datetime',
        'inspected_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(AssetInventory::class, 'asset_inventory_id');
    }

    public function batch()
    {
        return $this->belongsTo(AssetDecommissionBatch::class, 'decommission_batch_id');
    }

    public function isVendorReturn(): bool
    {
        return $this->decommission_type === 'vendor_return';
    }

    public function isEwaste(): bool
    {
        return $this->decommission_type === 'e_waste';
    }

    /** Human label for the e-waste completeness flag, or null if not set / not e-waste. */
    public function completenessLabel(): ?string
    {
        return self::COMPLETENESS[$this->ewaste_completeness] ?? null;
    }

    public function isIncomplete(): bool
    {
        return $this->ewaste_completeness === 'incomplete';
    }

    // ── Inspection (Phase 2) ─────────────────────────────────────────────────

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /**
     * Has this asset been inspected? `inspected_at` is the ONLY thing that answers it —
     * never test `ewaste_completeness`, which is null on an uninspected row today but was
     * written as 'complete' by default on every row staged before 2026-08-13, so reading it
     * would report the entire legacy backlog as inspected and let the gate wave it through.
     */
    public function isInspected(): bool
    {
        return $this->inspected_at !== null;
    }

    /**
     * An e-waste row is ready for a cycle only once it has been inspected AND resolved to a
     * registered company — from Phase 4 the company decides which management approver may
     * authorise the disposal, so an unresolved one has nobody who can sign for it.
     */
    public function isReadyForCycle(): bool
    {
        return $this->isEwaste() && $this->isInspected() && filled($this->company);
    }

    /** E-waste rows still awaiting an inspection, and not yet gathered into a cycle. */
    public function scopeAwaitingInspection($query)
    {
        return $query->where('decommission_type', 'e_waste')
            ->whereNull('decommission_batch_id')
            ->where(function ($q) {
                $q->whereNull('inspected_at')->orWhereNull('company');
            });
    }

    /** Badge for the Decommissioning queue: inspected (with its verdict), or not yet. */
    public function inspectionBadge(): array
    {
        if (! $this->isInspected()) {
            return ['color' => 'secondary', 'label' => 'Not inspected'];
        }

        return $this->isIncomplete()
            ? ['color' => 'warning text-dark', 'label' => 'Inspected — Incomplete']
            : ['color' => 'success', 'label' => 'Inspected — Complete'];
    }
}
