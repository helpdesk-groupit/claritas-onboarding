<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $table = 'acc_currencies';

    protected $fillable = ['code', 'name', 'symbol', 'exchange_rate', 'is_base'];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'is_base'       => 'boolean',
    ];
}
