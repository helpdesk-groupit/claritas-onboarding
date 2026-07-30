<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DisposedAsset extends Model
{
    protected $table = 'dispose_assets';

    protected $fillable = [
        'asset_inventory_id', 'asset_tag', 'asset_type',
        'brand', 'model', 'serial_number',
        'asset_condition', 'reason', 'disposed_by', 'disposed_at', 'remarks',
        // Decommissioning routing — how this staged asset leaves the inventory
        'decommission_type',
    ];

    protected $casts = [
        'disposed_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(AssetInventory::class, 'asset_inventory_id');
    }

    /** Handed back to the rental vendor it belongs to (condition = Returned). */
    public function isVendorReturn(): bool
    {
        return $this->decommission_type === 'vendor_return';
    }

    /** Scrapped / disposed of (condition = Not Good). */
    public function isEwaste(): bool
    {
        return ! $this->isVendorReturn();
    }

    /** Human label for the routing type, for listings and detail pages. */
    public function decommissionTypeLabel(): string
    {
        return AssetInventory::DECOMMISSION_TYPES[$this->decommission_type] ?? 'E-waste';
    }
}
