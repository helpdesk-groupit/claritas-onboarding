<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One captured attachment. Doubles as the idempotency ledger — see the
 * migration for why dedupe is a DB constraint rather than app logic.
 */
class EmailWorkflowCapture extends Model
{
    use HasFactory;

    /** Key claimed; bytes not yet in storage. */
    public const STATUS_PENDING = 'pending';

    /** Bytes in storage; not yet logged to the sheet. */
    public const STATUS_STORED = 'stored';

    /** Fully done — stored and logged. */
    public const STATUS_LOGGED = 'logged';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'email_workflow_id', 'email_workflow_run_id',
        'message_id', 'attachment_name', 'idempotency_key', 'key_hash',
        'status', 'stored_file_id', 'stored_file_url', 'stored_file_name',
        'amount', 'currency', 'needs_review', 'error', 'logged_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'needs_review' => 'boolean',
        'logged_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(EmailWorkflow::class, 'email_workflow_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailWorkflowRun::class, 'email_workflow_run_id');
    }

    /** Stable hash of an idempotency key — the unique index's actual value. */
    public static function hashKey(string $idempotencyKey): string
    {
        return hash('sha256', $idempotencyKey);
    }

    /** Terminal success: nothing left to do for this attachment. */
    public function isComplete(): bool
    {
        return $this->status === self::STATUS_LOGGED;
    }
}
