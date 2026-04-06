<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    protected $table = 'acc_credit_notes';

    protected $fillable = [
        'company', 'customer_id', 'invoice_id', 'credit_note_number', 'date',
        'reference', 'subtotal', 'tax_total', 'total', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'date'     => 'date',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total'    => 'decimal:2',
    ];

    public function customer()      { return $this->belongsTo(Customer::class, 'customer_id'); }
    public function invoice()       { return $this->belongsTo(SalesInvoice::class, 'invoice_id'); }
    public function items()         { return $this->hasMany(CreditNoteItem::class, 'credit_note_id'); }
    public function createdByUser() { return $this->belongsTo(User::class, 'created_by'); }
}
