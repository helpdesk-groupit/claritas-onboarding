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
    ];

    protected $casts = [
        'disposed_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(AssetInventory::class, 'asset_inventory_id');
    }
}