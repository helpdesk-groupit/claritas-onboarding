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
        'requested_by_id', 'year', 'month', 'from_date', 'to_date', 'companies', 'employee_ids', 'status',
        'total_matched', 'rendered_count', 'file_path', 'file_size', 'parts',
        'omitted_claims', 'failed_claims', 'error', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'companies' => 'array',
        'employee_ids' => 'array',
        'omitted_claims' => 'array',
        'failed_claims' => 'array',
        'parts' => 'array',
        'from_date' => 'date',
        'to_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Every archive this export produced, oldest first, as [['path', 'size', 'claims'], ...].
     *
     * `parts` is the source of truth. A row written before multi-part existed carries only
     * `file_path`/`file_size` and answers here as a single part, so nothing that predates the
     * split has to be migrated or special-cased by its callers — the single-part case is just
     * the general case with one entry.
     *
     * @return array<int, array{path: string, size: int, claims: ?int}>
     */
    public function partList(): array
    {
        if (is_array($this->parts) && $this->parts !== []) {
            return array_values($this->parts);
        }

        return $this->file_path
            ? [['path' => $this->file_path, 'size' => (int) $this->file_size, 'claims' => null]]
            : [];
    }

    public function partCount(): int
    {
        return count($this->partList());
    }

    /** True once the download is something the operator has to fetch more than once. */
    public function isMultiPart(): bool
    {
        return $this->partCount() > 1;
    }

    /** One part by its 1-based number, or null if there is no such part. */
    public function partAt(int $number): ?array
    {
        return $this->partList()[$number - 1] ?? null;
    }

    /** Bytes across every part — what the operator ends up downloading in total. */
    public function totalSize(): int
    {
        return array_sum(array_column($this->partList(), 'size'));
    }

    /**
     * What the browser saves this part as.
     *
     * A multi-part export names the part IN the filename: several files landing in one
     * Downloads folder as `approved-claims-2026-09-08.zip`, `… (1).zip`, `… (2).zip` would
     * leave nobody able to say which part is missing when one of them fails.
     */
    public function partFilename(int $number): string
    {
        $stem = 'approved-claims-'.$this->created_at->format('Y-m-d');

        return $this->isMultiPart()
            ? $stem.'-part'.$number.'-of-'.$this->partCount().'.zip'
            : $stem.'.zip';
    }

    /**
     * Was this request made for an explicit approval-date window rather than a cutoff cycle?
     *
     * Both dates are required together — a half-open request would have to invent the missing
     * end, and inventing "today" would make the same saved request mean something different
     * every time it ran.
     */
    public function hasDateRange(): bool
    {
        return $this->from_date !== null && $this->to_date !== null;
    }

    /** "27 Jul 2026 – 31 Aug 2026", for the download filename and the UI. */
    public function rangeLabel(): ?string
    {
        return $this->hasDateRange()
            ? $this->from_date->format('j M Y').' – '.$this->to_date->format('j M Y')
            : null;
    }

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
     * Drop the row and every archive it produced.
     *
     * Always safe: the files are only ever reached through this row's own download route, so a
     * row that still exists is by definition an archive nothing has claimed yet.
     *
     * Iterates partList() rather than file_path — that column only ever names part one, so a
     * multi-part export pruned through it would leave every other part orphaned on the disk
     * with no row left pointing at it, i.e. undeletable by anything but hand.
     */
    public function discard(): void
    {
        foreach ($this->partList() as $part) {
            if (! empty($part['path']) && Storage::disk('local')->exists($part['path'])) {
                Storage::disk('local')->delete($part['path']);
            }
        }

        $this->delete();
    }
}
