<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentAllocation extends Model
{
    protected $table = 'acc_customer_payment_allocations';

    protected $fillable = ['customer_payment_id', 'invoice_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function payment() { return $this->belongsTo(CustomerPayment::class, 'customer_payment_id'); }
    public function invoice() { return $this->belongsTo(SalesInvoice::class, 'invoice_id'); }
}
