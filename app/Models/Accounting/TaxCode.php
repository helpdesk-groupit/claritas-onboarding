<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    protected $table = 'acc_tax_codes';

    protected $fillable = [
        'company', 'code', 'name', 'rate', 'type', 'is_default', 'is_active',
        'purchase_account_id', 'sales_account_id',
    ];

    protected $casts = [
        'rate'       => 'decimal:3',
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];

    public function purchaseAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'purchase_account_id');
    }

    public function salesAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'sales_account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
