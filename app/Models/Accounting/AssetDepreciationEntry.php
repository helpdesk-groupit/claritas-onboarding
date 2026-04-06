<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class AssetDepreciationEntry extends Model
{
    protected $table = 'acc_asset_depreciation_entries';

    protected $fillable = [
        'fixed_asset_id', 'period_date', 'depreciation_amount',
        'accumulated_depreciation', 'net_book_value', 'journal_entry_id',
    ];

    protected $casts = [
        'period_date'              => 'date',
        'depreciation_amount'      => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'net_book_value'           => 'decimal:2',
    ];

    public function fixedAsset()   { return $this->belongsTo(FixedAsset::class, 'fixed_asset_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
}
