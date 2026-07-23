<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generation run of a social strategy — the observability record the wizard
 * polls while RunStrategyGeneration works through the sections. Mirrors
 * EmailWorkflowRun.
 */
class SocialStrategyRun extends Model
{
    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_REGENERATE = 'regenerate';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    /** Completed, but at least one section failed. */
    public const STATUS_PARTIAL = 'partial';

    /** The run blew up before any section could be written. */
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'social_strategy_id', 'trigger', 'triggered_by', 'status',
        'target_sections_json', 'total_sections', 'completed_sections',
        'failed_sections', 'current_section', 'error', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'target_sections_json' => 'array',
        'total_sections' => 'integer',
        'completed_sections' => 'integer',
        'failed_sections' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(SocialStrategy::class, 'social_strategy_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function durationSeconds(): ?int
    {
        if (! $this->started_at || ! $this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
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
