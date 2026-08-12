<?php

namespace App\Console\Commands;

use App\Models\AssetDecommissionBatch;
use App\Services\DecommissionReportRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-render the archived decommissioning report PDFs in place.
 *
 * The stored report is a snapshot taken at finalisation, so a change to the
 * report template (e.g. appending the actual quotation / receipt documents rather
 * than merely naming them) does NOT reach the copies already on disk. This
 * backfills them. Safe to re-run: it overwrites `decommission_reports/{ref}.pdf`
 * and re-points `report_pdf_path` at the same deterministic path.
 */
class RegenerateDecommissionReports extends Command
{
    protected $signature = 'decommission:regenerate-reports
                            {--batch=* : Limit to these batch numbers (repeatable); default is every archived report}
                            {--all : Include batches that have no stored report yet (renders one for them)}
                            {--dry-run : Report what would be rewritten without writing}';

    protected $description = 'Re-render the stored asset decommissioning report PDFs with the current template';

    public function handle(): int
    {
        $query = AssetDecommissionBatch::query()->with(['vendor', 'items.asset']);

        if ($numbers = array_filter((array) $this->option('batch'))) {
            $query->whereIn('batch_number', $numbers);
        } elseif (! $this->option('all')) {
            // Default: only the reports that actually exist on disk today.
            $query->whereNotNull('report_pdf_path');
        }

        $batches = $query->orderBy('batch_number')->get();

        if ($batches->isEmpty()) {
            $this->warn('No matching batches found.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $rewritten = 0;
        $failed = 0;

        foreach ($batches as $batch) {
            $appendix = DecommissionReportRenderer::appendix($batch);
            $appended = collect($appendix)->filter(fn ($d) => $d['appendable']);
            $blocked = collect($appendix)->reject(fn ($d) => $d['appendable']);

            $detail = $appended->isEmpty()
                ? 'no appendable documents'
                : $appended->map(fn ($d) => strtolower($d['label']).' ('.$d['pages'].'p)')->implode(', ');

            if ($dry) {
                $this->line("  [dry] {$batch->batch_number} — {$detail}");
                $rewritten++;

                continue;
            }

            try {
                $path = 'decommission_reports/'.$batch->batch_number.'.pdf';
                Storage::disk('local')->put($path, DecommissionReportRenderer::render($batch));

                if ($batch->report_pdf_path !== $path) {
                    $batch->forceFill(['report_pdf_path' => $path])->saveQuietly();
                }

                $size = number_format(Storage::disk('local')->size($path) / 1024, 0);
                $this->info("  ✓ {$batch->batch_number} — {$detail} — {$size} KB");
                $rewritten++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$batch->batch_number} — {$e->getMessage()}");
                $failed++;

                continue;
            }

            foreach ($blocked as $doc) {
                $this->warn("      {$doc['label']} not appended: {$doc['reason']}");
            }
        }

        $this->newLine();
        $this->line($dry
            ? "{$rewritten} report(s) would be rewritten."
            : "{$rewritten} report(s) rewritten".($failed ? ", {$failed} failed." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
