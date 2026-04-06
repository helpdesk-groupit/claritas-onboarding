<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class JournalEntryLine extends Model
{
    protected $table = 'acc_journal_entry_lines';

    protected $fillable = [
        'journal_entry_id', 'account_id', 'description', 'debit', 'credit',
        'tax_code_id', 'tax_amount', 'currency', 'exchange_rate',
    ];

    protected $casts = [
        'debit'         => 'decimal:2',
        'credit'        => 'decimal:2',
        'tax_amount'    => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id');
    }
}
