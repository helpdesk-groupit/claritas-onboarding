<?php

namespace App\Models\Accounting;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AccountingAuditTrail extends Model
{
    protected $table = 'acc_audit_trail';

    protected $fillable = [
        'company', 'user_id', 'action', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }

    public function auditable()
    {
        return $this->morphTo('auditable');
    }

    public static function log(string $action, Model $model, ?array $oldValues = null, ?array $newValues = null): void
    {
        static::create([
            'company'        => $model->company ?? null,
            'user_id'        => auth()->id(),
            'action'         => $action,
            'auditable_type' => get_class($model),
            'auditable_id'   => $model->getKey(),
            'old_values'     => $oldValues,
            'new_values'     => $newValues,
            'ip_address'     => request()->ip(),
        ]);
    }
}
