<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CustomerPayment extends Model
{
    protected $table = 'acc_customer_payments';

    protected $fillable = [
        'company', 'customer_id', 'payment_number', 'date', 'amount',
        'payment_method', 'reference', 'bank_account_id', 'notes',
        'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    public function customer()     { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function bankAccount()  { return $this->belongsTo(BankAccount::class, 'bank_account_id'); }
    public function journalEntry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function createdByUser(){ return $this->belongsTo(User::class, 'created_by'); }

    public function allocations()
    {
        return $this->hasMany(CustomerPaymentAllocation::class, 'customer_payment_id');
    }

    public function invoices()
    {
        return $this->belongsToMany(SalesInvoice::class, 'acc_customer_payment_allocations', 'customer_payment_id', 'invoice_id')->withPivot('amount');
    }
}
