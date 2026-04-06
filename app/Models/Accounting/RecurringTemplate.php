<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecurringTemplate extends Model
{
    protected $table = 'acc_recurring_templates';

    protected $fillable = [
        'company', 'name', 'type', 'frequency', 'next_run_date',
        'end_date', 'template_data', 'is_active', 'last_run_at', 'created_by',
    ];

    protected $casts = [
        'next_run_date'  => 'date',
        'end_date'       => 'date',
        'template_data'  => 'array',
        'is_active'      => 'boolean',
        'last_run_at'    => 'datetime',
    ];

    public function createdByUser() { return $this->belongsTo(User::class, 'created_by'); }
}
