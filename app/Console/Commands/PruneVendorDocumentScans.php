<?php

namespace App\Console\Commands;

use App\Models\VendorDocumentScan;
use Illuminate\Console\Command;

/**
 * Discard uploads that were scanned but never filed.
 *
 * The Add-Document flow stores the file and reads it BEFORE the contract or billing row
 * exists, so an operator who closes the modal leaves a file on the private disk that
 * nothing will ever point at. Without this sweep those accumulate silently and forever.
 *
 * Deleting the file with the row is safe by construction: saving a document deletes its
 * staging row and KEEPS the file, so a row still present is by definition one whose file
 * was never claimed.
 */
class PruneVendorDocumentScans extends Command
{
    protected $signature = 'vendors:prune-document-scans
                            {--hours= : Override the retention window from config}
                            {--dry-run : List what would be discarded and discard nothing}';

    protected $description = 'Discard vendor document scans that were never filed, and their uploaded files';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('vendors.ai.scan_retention_hours', 24));
        $dry = (bool) $this->option('dry-run');

        if ($hours < 1) {
            $this->error('The retention window must be at least one hour.');

            return self::FAILURE;
        }

        $stale = VendorDocumentScan::stale($hours)->get();

        if ($stale->isEmpty()) {
            $this->info('No abandoned document scans older than '.$hours.'h.');

            return self::SUCCESS;
        }

        foreach ($stale as $scan) {
            $this->line(($dry ? '[dry-run] ' : '')
                .'Discarding scan #'.$scan->id.' ('.$scan->kind.', vendor '.$scan->vendor_id.') — '
                .($scan->original_filename ?: $scan->file_path));

            if (! $dry) {
                $scan->discard();
            }
        }

        $this->info(($dry ? 'Would discard ' : 'Discarded ').$stale->count().' abandoned scan(s).');

        return self::SUCCESS;
    }
}
