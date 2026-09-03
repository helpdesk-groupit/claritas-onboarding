<?php

namespace App\Console\Commands;

use App\Models\ExpenseClaimZipExport;
use Illuminate\Console\Command;

/**
 * Discard finished "Export approved PDFs (ZIP)" requests and their archives.
 *
 * A finished export (ready or failed) sits on the private disk purely so the HR page can
 * offer its download link; nothing else ever points at that file. Deleting the row with the
 * file is safe by construction — the archive is only ever reached through this row's own
 * download route — the same reasoning as vendors:prune-document-scans and
 * vendors:prune-import-batches.
 */
class PruneClaimZipExports extends Command
{
    protected $signature = 'claims:prune-zip-exports
                            {--hours= : Override the retention window from config}
                            {--dry-run : List what would be discarded and discard nothing}';

    protected $description = 'Discard finished claim ZIP exports and their archives after the retention window';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('claims.zip_export.retention_hours', 48));
        $dry = (bool) $this->option('dry-run');

        if ($hours < 1) {
            $this->error('The retention window must be at least one hour.');

            return self::FAILURE;
        }

        $stale = ExpenseClaimZipExport::stale($hours)->get();

        if ($stale->isEmpty()) {
            $this->info('No finished claim ZIP exports older than '.$hours.'h.');

            return self::SUCCESS;
        }

        foreach ($stale as $export) {
            $this->line(($dry ? '[dry-run] ' : '')
                .'Discarding export #'.$export->id.' ('.$export->status.') requested by user '.$export->requested_by_id);

            if (! $dry) {
                $export->discard();
            }
        }

        $this->info(($dry ? 'Would discard ' : 'Discarded ').$stale->count().' finished export(s).');

        return self::SUCCESS;
    }
}
