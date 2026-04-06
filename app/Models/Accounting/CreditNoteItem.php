<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class CreditNoteItem extends Model
{
    protected $table = 'acc_credit_note_items';

    protected $fillable = [
        'credit_note_id', 'account_id', 'description', 'quantity', 'unit_price',
        'tax_code_id', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'unit_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function creditNote() { return $this->belongsTo(CreditNote::class, 'credit_note_id'); }
    public function account()    { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function taxCode()    { return $this->belongsTo(TaxCode::class, 'tax_code_id'); }
}
