<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class SalesInvoiceItem extends Model
{
    protected $table = 'acc_sales_invoice_items';

    protected $fillable = [
        'sales_invoice_id', 'account_id', 'description', 'quantity', 'unit_price',
        'discount_percent', 'tax_code_id', 'tax_amount', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'quantity'         => 'decimal:4',
        'unit_price'       => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_amount'       => 'decimal:2',
        'line_total'       => 'decimal:2',
    ];

    public function invoice() { return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id'); }
    public function account() { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function taxCode() { return $this->belongsTo(TaxCode::class, 'tax_code_id'); }
}
