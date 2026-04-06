<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'acc_customers';

    protected $fillable = [
        'company', 'customer_code', 'name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country',
        'tax_id', 'credit_limit', 'payment_terms_days', 'currency', 'notes', 'is_active',
    ];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'is_active'    => 'boolean',
    ];

    public function invoices()
    {
        return $this->hasMany(SalesInvoice::class, 'customer_id')->latest('date');
    }

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class, 'customer_id');
    }

    public function payments()
    {
        return $this->hasMany(CustomerPayment::class, 'customer_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return (float) $this->invoices()->whereNotIn('status', ['paid', 'void'])->sum('balance_due');
    }
}
