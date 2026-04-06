<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    protected $table = 'acc_chart_of_accounts';

    protected $fillable = [
        'company', 'account_code', 'name', 'type', 'sub_type', 'parent_id',
        'description', 'normal_balance', 'opening_balance', 'opening_balance_date',
        'currency', 'is_active', 'is_system', 'allow_direct_posting',
    ];

    protected $casts = [
        'opening_balance'      => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_active'            => 'boolean',
        'is_system'            => 'boolean',
        'allow_direct_posting' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines()
    {
        return $this->hasMany(JournalEntryLine::class, 'account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function getBalanceAttribute(): float
    {
        $debits  = $this->journalLines()
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->sum('debit');
        $credits = $this->journalLines()
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->sum('credit');

        return $this->normal_balance === 'debit'
            ? ($debits - $credits + $this->opening_balance)
            : ($credits - $debits + $this->opening_balance);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }
}
