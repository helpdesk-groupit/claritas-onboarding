<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $table = 'acc_purchase_orders';

    protected $fillable = [
        'company', 'vendor_id', 'po_number', 'date', 'expected_date',
        'reference', 'subtotal', 'tax_total', 'total', 'status',
        'notes', 'created_by', 'approved_by',
    ];

    protected $casts = [
        'date'          => 'date',
        'expected_date' => 'date',
        'subtotal'      => 'decimal:2',
        'tax_total'     => 'decimal:2',
        'total'         => 'decimal:2',
    ];

    public function vendor()         { return $this->belongsTo(Vendor::class, 'vendor_id'); }
    public function items()          { return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id')->orderBy('sort_order'); }
    public function createdByUser()  { return $this->belongsTo(User::class, 'created_by'); }
    public function approvedByUser() { return $this->belongsTo(User::class, 'approved_by'); }
}
