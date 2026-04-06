<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TaxReturn extends Model
{
    protected $table = 'acc_tax_returns';

    protected $fillable = [
        'company', 'return_type', 'period_start', 'period_end',
        'total_output_tax', 'total_input_tax', 'net_tax_payable',
        'status', 'filed_date', 'due_date', 'payment_date', 'reference', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_start'     => 'date',
        'period_end'       => 'date',
        'filed_date'       => 'date',
        'due_date'         => 'date',
        'payment_date'     => 'date',
        'total_output_tax' => 'decimal:2',
        'total_input_tax'  => 'decimal:2',
        'net_tax_payable'  => 'decimal:2',
    ];

    public function lines()         { return $this->hasMany(TaxReturnLine::class, 'tax_return_id'); }
    public function createdByUser() { return $this->belongsTo(User::class, 'created_by'); }
}
