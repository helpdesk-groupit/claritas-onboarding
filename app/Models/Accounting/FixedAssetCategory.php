<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class FixedAssetCategory extends Model
{
    protected $table = 'acc_fixed_asset_categories';

    protected $fillable = [
        'company', 'name', 'depreciation_method', 'useful_life_years',
        'asset_account_id', 'depreciation_expense_account_id', 'accumulated_depreciation_account_id',
    ];

    public function assets()                    { return $this->hasMany(FixedAsset::class, 'category_id'); }
    public function assetAccount()              { return $this->belongsTo(ChartOfAccount::class, 'asset_account_id'); }
    public function depreciationExpenseAccount() { return $this->belongsTo(ChartOfAccount::class, 'depreciation_expense_account_id'); }
    public function accumulatedDepreciationAccount() { return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id'); }
}
