<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class FixedAsset extends Model
{
    protected $table = 'acc_fixed_assets';

    protected $fillable = [
        'company', 'category_id', 'asset_code', 'name', 'description',
        'purchase_date', 'purchase_cost', 'residual_value', 'useful_life_months',
        'depreciation_method', 'status', 'disposal_date', 'disposal_amount',
        'accumulated_depreciation', 'net_book_value', 'vendor_id',
        'serial_number', 'location', 'notes',
    ];

    protected $casts = [
        'purchase_date'            => 'date',
        'disposal_date'            => 'date',
        'purchase_cost'            => 'decimal:2',
        'residual_value'           => 'decimal:2',
        'disposal_amount'          => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'net_book_value'           => 'decimal:2',
    ];

    public function category()           { return $this->belongsTo(FixedAssetCategory::class, 'category_id'); }
    public function vendor()             { return $this->belongsTo(Vendor::class, 'vendor_id'); }
    public function depreciationEntries(){ return $this->hasMany(AssetDepreciationEntry::class, 'fixed_asset_id')->orderBy('period_date'); }

    public function getMonthlyDepreciationAttribute(): float
    {
        if ($this->depreciation_method === 'straight_line') {
            return ($this->purchase_cost - $this->residual_value) / max($this->useful_life_months, 1);
        }
        return 0;
    }
}
