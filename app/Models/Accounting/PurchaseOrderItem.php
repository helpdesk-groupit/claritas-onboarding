<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $table = 'acc_purchase_order_items';

    protected $fillable = [
        'purchase_order_id', 'account_id', 'description', 'quantity', 'unit_price',
        'tax_code_id', 'tax_amount', 'line_total', 'received_quantity', 'sort_order',
    ];

    protected $casts = [
        'quantity'          => 'decimal:4',
        'unit_price'        => 'decimal:2',
        'tax_amount'        => 'decimal:2',
        'line_total'        => 'decimal:2',
        'received_quantity' => 'decimal:4',
    ];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function account()       { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function taxCode()       { return $this->belongsTo(TaxCode::class, 'tax_code_id'); }
}
