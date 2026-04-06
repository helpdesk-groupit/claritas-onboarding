<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    protected $table = 'acc_bills';

    protected $fillable = [
        'company', 'vendor_id', 'bill_number', 'vendor_bill_number', 'date', 'due_date',
        'reference', 'description', 'subtotal', 'tax_total', 'total',
        'amount_paid', 'balance_due', 'currency', 'exchange_rate',
        'status', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'date'          => 'date',
        'due_date'      => 'date',
        'approved_at'   => 'datetime',
        'subtotal'      => 'decimal:2',
        'tax_total'     => 'decimal:2',
        'total'         => 'decimal:2',
        'amount_paid'   => 'decimal:2',
        'balance_due'   => 'decimal:2',
        'exchange_rate'  => 'decimal:6',
    ];

    public function vendor()         { return $this->belongsTo(Vendor::class, 'vendor_id'); }
    public function items()          { return $this->hasMany(BillItem::class, 'bill_id')->orderBy('sort_order'); }
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
        $this->total = $this->subtotal + $this->tax_total;
        $this->balance_due = $this->total - $this->amount_paid;
        $this->save();
    }
}
