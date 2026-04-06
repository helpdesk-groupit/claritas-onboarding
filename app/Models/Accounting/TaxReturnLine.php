<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class TaxReturnLine extends Model
{
    protected $table = 'acc_tax_return_lines';

    protected $fillable = ['tax_return_id', 'tax_code_id', 'description', 'taxable_amount', 'tax_amount'];

    protected $casts = [
        'taxable_amount' => 'decimal:2',
        'tax_amount'     => 'decimal:2',
    ];

    public function taxReturn() { return $this->belongsTo(TaxReturn::class, 'tax_return_id'); }
    public function taxCode()   { return $this->belongsTo(TaxCode::class, 'tax_code_id'); }
}
