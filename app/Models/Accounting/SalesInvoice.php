<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class SalesInvoice extends Model
{
    protected $table = 'acc_sales_invoices';

    protected $fillable = [
        'company', 'customer_id', 'invoice_number', 'date', 'due_date',
        'reference', 'description', 'subtotal', 'tax_total', 'discount_total',
        'total', 'amount_paid', 'balance_due', 'currency', 'exchange_rate',
        'status', 'notes', 'terms', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'date'           => 'date',
        'due_date'       => 'date',
        'approved_at'    => 'datetime',
        'subtotal'       => 'decimal:2',
        'tax_total'      => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total'          => 'decimal:2',
        'amount_paid'    => 'decimal:2',
        'balance_due'    => 'decimal:2',
        'exchange_rate'  => 'decimal:6',
    ];

    public function customer()  { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function items()     { return $this->hasMany(SalesInvoiceItem::class, 'sales_invoice_id')->orderBy('sort_order'); }
    public function payments()  { return $this->hasManyThrough(CustomerPayment::class, CustomerPaymentAllocation::class, 'invoice_id', 'id', 'id', 'customer_payment_id'); }
    public function creditNotes() { return $this->hasMany(CreditNote::class, 'invoice_id'); }
    public function createdByUser()  { return $this->belongsTo(User::class, 'created_by'); }
    public function approvedByUser() { return $this->belongsTo(User::class, 'approved_by'); }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now()->toDateString())->whereNotIn('status', ['paid', 'void']);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereNotIn('status', ['paid', 'void']);
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items()->sum('line_total');
        $this->tax_total = $this->items()->sum('tax_amount');
        $this->total = $this->subtotal + $this->tax_total - $this->discount_total;
        $this->balance_due = $this->total - $this->amount_paid;
        $this->save();
    }

    public function isOverdue(): bool
    {
        return $this->due_date < now() && !in_array($this->status, ['paid', 'void']);
    }
}
