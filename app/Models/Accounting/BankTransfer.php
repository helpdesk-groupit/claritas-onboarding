<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BankTransfer extends Model
{
    protected $table = 'acc_bank_transfers';

    protected $fillable = [
        'company', 'from_bank_account_id', 'to_bank_account_id',
        'amount', 'date', 'reference', 'description', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    public function fromAccount()    { return $this->belongsTo(BankAccount::class, 'from_bank_account_id'); }
    public function toAccount()      { return $this->belongsTo(BankAccount::class, 'to_bank_account_id'); }
    public function journalEntry()   { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function createdByUser()  { return $this->belongsTo(User::class, 'created_by'); }
}
