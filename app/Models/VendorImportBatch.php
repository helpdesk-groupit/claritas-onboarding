<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An uploaded vendor list waiting to be reviewed and filed.
 *
 * Lives for the length of one import: the operator picks a spreadsheet, it is stored and
 * read here, they check (and correct) the column mapping the reader worked out, and
 * confirming turns the rows into Vendor records. Nothing is written to `vendors` until that
 * confirmation, so an abandoned import is just this row plus its file.
 *
 * Anything left behind is swept by `vendors:prune-import-batches`. Unlike VendorDocumentScan
 * — whose file is CLAIMED by the record it becomes — an import's file is never referenced by
 * anything afterwards, so the row and the file are always discarded together, including on a
 * successful import.
 */
class VendorImportBatch extends Model
{
    /**
     * Where the uploaded list is parked.
     *
     * Deliberately NOT registered in secure_file_url() / SecureFileController — nothing ever
     * serves this file back. It is read server-side during the import and then deleted; a
     * download route would be a second way to reach a document the operator already has.
     */
    public const DIRECTORY = 'vendor_imports';

    protected $fillable = [
        'user_id', 'token', 'original_filename', 'file_path', 'sheet_name', 'row_count',
    ];

    protected $casts = [
        'row_count' => 'integer',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public static function freshToken(): string
    {
        return Str::random(40);
    }

    /** Imports abandoned before they were confirmed. */
    public function scopeStale(Builder $query, int $hours): Builder
    {
        return $query->where('created_at', '<', now()->subHours($hours));
    }

    /**
     * Drop the row and the spreadsheet it was holding.
     *
     * Always safe: no vendor record ever points at this file — the import copies values OUT
     * of it — so a row that still exists is by definition one whose file nothing needs.
     */
    public function discard(): void
    {
        if ($this->file_path && Storage::disk('local')->exists($this->file_path)) {
            Storage::disk('local')->delete($this->file_path);
        }

        $this->delete();
    }

    /** The absolute path the reader parses, or null when the upload has gone missing. */
    public function absolutePath(): ?string
    {
        if (! $this->file_path || ! Storage::disk('local')->exists($this->file_path)) {
            return null;
        }

        return Storage::disk('local')->path($this->file_path);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_filename, PATHINFO_EXTENSION));
    }
}
