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
        // E-waste completeness flag + removed-parts list (added 2026-07-28)
        'ewaste_completeness', 'ewaste_parts_removed',
    ];

    protected $casts = [
        'disposed_at' => 'datetime',
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
}
