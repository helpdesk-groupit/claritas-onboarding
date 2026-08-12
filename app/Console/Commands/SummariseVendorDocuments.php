<?php

namespace App\Console\Commands;

use App\Jobs\SummariseVendorDocument;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorContract;
use Illuminate\Console\Command;

/**
 * Queue the summary + transcription pass for vendor documents that have never had one.
 *
 * Every contract, quotation and invoice filed BEFORE this feature existed has a stored
 * file and no reading of it, so its row shows no summary and the assistant cannot answer
 * anything about it. Without this they only get read one click at a time, and nobody
 * clicks through a hundred rows.
 *
 * Not scheduled — a one-off after deployment, and again after a provider change that made
 * PDFs readable (`--redo=skipped`). Same shape as decommission:regenerate-reports.
 */
class SummariseVendorDocuments extends Command
{
    protected $signature = 'vendors:summarise-documents
                            {--vendor= : Only this vendor id}
                            {--all : Include documents that already have a reading}
                            {--redo= : Only re-read documents currently at this status (e.g. skipped, failed)}
                            {--dry-run : List what would be queued and queue nothing}';

    protected $description = 'Queue AI summary + transcription for vendor contracts and billing documents';

    public function handle(): int
    {
        $vendorId = $this->option('vendor');
        $redo = $this->option('redo');
        $all = (bool) $this->option('all');
        $dry = (bool) $this->option('dry-run');

        if ($vendorId && ! Vendor::whereKey($vendorId)->exists()) {
            $this->error("No vendor with id {$vendorId}.");

            return self::FAILURE;
        }

        $queued = 0;

        foreach (['contract' => VendorContract::class, 'billing' => VendorBillingDocument::class] as $label => $class) {
            $query = $class::query()
                ->whereNotNull('file_path')
                ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
                ->with('vendor');

            if ($redo) {
                $query->where('ai_status', $redo);
            } elseif (! $all) {
                // The default: only documents nobody has ever tried to read. A `failed` or
                // `skipped` row was tried and is a deliberate choice to re-run with --redo,
                // not something to silently retry and re-bill on every deployment.
                $query->whereNull('ai_status');
            }

            foreach ($query->cursor() as $document) {
                $this->line(sprintf(
                    '%s  %-9s #%-6d %s',
                    $dry ? 'would queue' : 'queued    ',
                    $label,
                    $document->id,
                    $document->vendor?->name ?? '(vendor missing)'
                ));

                if (! $dry) {
                    SummariseVendorDocument::dispatchFor($document);
                }
                $queued++;
            }
        }

        if ($queued === 0) {
            $this->info('Nothing to read — every matching document already has a reading.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info($dry
            ? "{$queued} document(s) would be queued. Re-run without --dry-run to queue them."
            : "{$queued} document(s) queued. They are read by the scheduler's queue worker; "
                .'expect one AI call each.');

        return self::SUCCESS;
    }
}
