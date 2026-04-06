<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VendorPayment extends Model
{
    protected $table = 'acc_vendor_payments';

    protected $fillable = [
        'company', 'vendor_id', 'payment_number', 'date', 'amount',
        'payment_method', 'reference', 'bank_account_id', 'notes',
        'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    public function vendor()       { return $this->belongsTo(Vendor::class, 'vendor_id'); }
    public function bankAccount()  { return $this->belongsTo(BankAccount::class, 'bank_account_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function createdByUser(){ return $this->belongsTo(User::class, 'created_by'); }

    public function allocations()
    {
        return $this->hasMany(VendorPaymentAllocation::class, 'vendor_payment_id');
    }

    public function bills()
    {
        return $this->belongsToMany(Bill::class, 'acc_vendor_payment_allocations', 'vendor_payment_id', 'bill_id')->withPivot('amount');
    }
}
