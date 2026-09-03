<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One "Export approved PDFs (ZIP)" request — the record BuildClaimZipExport renders into and
 * the HR page polls. See the creating migration for why this exists as a background job
 * rather than a synchronous download.
 */
class ExpenseClaimZipExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /** Where a finished archive is parked until downloaded or swept. Not web-served directly. */
    public const DIRECTORY = 'claim_zip_exports';

    protected $fillable = [
        'requested_by_id', 'year', 'month', 'companies', 'employee_ids', 'status',
        'total_matched', 'rendered_count', 'file_path', 'file_size',
        'omitted_claims', 'failed_claims', 'error', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'companies' => 'array',
        'employee_ids' => 'array',
        'omitted_claims' => 'array',
        'failed_claims' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function isDone(): bool
    {
        return in_array($this->status, [self::STATUS_READY, self::STATUS_FAILED], true);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** Exports finished (either way) longer ago than the retention window. */
    public function scopeStale(Builder $query, int $hours): Builder
    {
        return $query->whereIn('status', [self::STATUS_READY, self::STATUS_FAILED])
            ->where(function (Builder $q) use ($hours) {
                $q->where('completed_at', '<', now()->subHours($hours))
                    ->orWhere(function (Builder $q2) use ($hours) {
                        // Guard against a completed_at that never got stamped.
                        $q2->whereNull('completed_at')->where('updated_at', '<', now()->subHours($hours));
                    });
            });
    }

    /**
     * Drop the row and the archive it produced.
     *
     * Always safe: the file is only ever reached through this row's own download route, so a
     * row that still exists is by definition an archive nothing has claimed yet.
     */
    public function discard(): void
    {
        if ($this->file_path && Storage::disk('local')->exists($this->file_path)) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->delete();
    }
}
