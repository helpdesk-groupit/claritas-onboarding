<?php

namespace App\Console\Commands;

use App\Models\VendorImportBatch;
use Illuminate\Console\Command;

/**
 * Discard vendor lists that were uploaded for import but never confirmed.
 *
 * The import stores the spreadsheet BEFORE any vendor exists, so an operator who uploads a
 * file and then walks away leaves it on the private disk with nothing that will ever read it.
 * A confirmed import discards its own batch, and so does Cancel — this sweep is for the
 * closed tab and the abandoned decision.
 *
 * Deleting the file with the row is safe by construction, and more plainly so than for
 * document scans: an import COPIES values out of the spreadsheet, so no vendor record ever
 * points at the file. A row that still exists is always an unclaimed upload.
 *
 * Kept apart from `vendors:prune-document-scans` rather than folded into it: that command's
 * name says what it sweeps, and widening it silently would leave a command doing something
 * its name does not mention.
 */
class PruneVendorImportBatches extends Command
{
    protected $signature = 'vendors:prune-import-batches
                            {--hours= : Override the retention window from config}
                            {--dry-run : List what would be discarded and discard nothing}';

    protected $description = 'Discard uploaded vendor lists that were never imported, and their files';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('vendors.import.retention_hours', 24));
        $dry = (bool) $this->option('dry-run');

        if ($hours < 1) {
            $this->error('The retention window must be at least one hour.');

            return self::FAILURE;
        }

        $stale = VendorImportBatch::stale($hours)->get();

        if ($stale->isEmpty()) {
            $this->info('No abandoned vendor imports older than '.$hours.'h.');

            return self::SUCCESS;
        }

        foreach ($stale as $batch) {
            $this->line(($dry ? '[dry-run] ' : '')
                .'Discarding import #'.$batch->id.' by user '.$batch->user_id.' — '
                .($batch->original_filename ?: $batch->file_path)
                .' ('.$batch->row_count.' rows)');

            if (! $dry) {
                $batch->discard();
            }
        }

        $this->info(($dry ? 'Would discard ' : 'Discarded ').$stale->count().' abandoned import(s).');

        return self::SUCCESS;
    }
}
