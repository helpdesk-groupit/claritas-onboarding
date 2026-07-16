<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One capture run of an EmailWorkflow — the observability record behind
 * "Last run" on the list page and the run-history panel.
 */
class EmailWorkflowRun extends Model
{
    use HasFactory;

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    /** Completed, but at least one attachment failed. */
    public const STATUS_PARTIAL = 'partial';

    /** The run itself blew up (auth, folder, sheet) — nothing usable happened. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'email_workflow_id', 'trigger', 'triggered_by', 'status',
        'scanned_count', 'matched_count', 'captured_count',
        'skipped_count', 'failed_count', 'error', 'coverage_warning',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'scanned_count' => 'integer',
        'matched_count' => 'integer',
        'captured_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(EmailWorkflow::class, 'email_workflow_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function captures(): HasMany
    {
        return $this->hasMany(EmailWorkflowCapture::class, 'email_workflow_run_id');
    }

    /** Wall-clock duration in seconds, or null while still running. */
    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->finished_at);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_SUCCESS => 'bg-success',
            self::STATUS_PARTIAL => 'bg-warning text-dark',
            self::STATUS_FAILED => 'bg-danger',
            default => 'bg-info text-dark', // running
        };
    }
}
