<?php

namespace App\Jobs;

use App\Models\ExpenseClaimZipExport;
use App\Services\ClaimZipExportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Renders one "Export approved PDFs (ZIP)" request end to end.
 *
 * Runs off the request/response cycle entirely (same pattern as RunStrategyGeneration /
 * RunEmailWorkflowCapture — dispatched to the `database` queue, drained by the scheduler-
 * supervised `queue:work --stop-when-empty`), specifically so it is never bound by nginx's
 * 60s proxy_read_timeout the way the old inline render was. That is what lets every matching
 * claim render regardless of how many there are — see ExpenseClaimZipExport's migration for
 * the production symptom this replaces.
 *
 * ShouldBeUnique per export row: a duplicate dispatch (e.g. a retried HTTP request) can't
 * render the same request twice.
 */
class BuildClaimZipExport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $exportId)
    {
        $this->onConnection('database');
        $this->timeout = (int) config('claims.zip_export.job_timeout', 1800);
    }

    public function uniqueId(): string
    {
        return 'claim-zip-export-'.$this->exportId;
    }

    public function handle(ClaimZipExportService $service): void
    {
        $export = ExpenseClaimZipExport::find($this->exportId);
        if (! $export) {
            return; // row deleted (e.g. swept) before the job ran
        }

        $export->update(['status' => ExpenseClaimZipExport::STATUS_RUNNING, 'started_at' => now()]);

        if (! class_exists(\ZipArchive::class)) {
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'ZIP export isn\'t available on this server (the PHP "zip" extension is disabled). Ask IT to enable it.',
                'completed_at' => now(),
            ]);

            return;
        }

        // Fresh query, not the controller's snapshot — a claim approved after the click but
        // before this job ran is still picked up here. A request carries EITHER an explicit
        // approval-date window or a cutoff cycle; the window wins when present, and both go
        // through ClaimZipExportService so Finance's CSV of the same period matches this ZIP.
        $matched = $export->hasDateRange()
            ? $service->claimsApprovedBetween($export->from_date, $export->to_date, $export->companies ?? [], $export->employee_ids ?? [])
            : $service->matchingClaims($export->year, $export->month, $export->companies ?? [], $export->employee_ids ?? []);

        if ($matched->isEmpty()) {
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => $export->hasDateRange()
                    ? 'No claims were approved between '.$export->rangeLabel().' any more.'
                    : 'No processed claims match the filter any more.',
                'total_matched' => 0,
                'completed_at' => now(),
            ]);

            return;
        }

        // Generous sanity ceiling against a genuinely pathological filter — not normal cycle
        // volume. See config('claims.zip_export.max_claims').
        $cap = max(1, (int) config('claims.zip_export.max_claims', 2000));
        $claims = $matched->take($cap)->values();
        $omitted = $matched->slice($cap)->values();

        $export->update(['total_matched' => $matched->count()]);

        @set_time_limit(0);

        $tmpDir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'claim-zip-'.bin2hex(random_bytes(8));
        if (! @mkdir($tmpDir, 0700, true) && ! is_dir($tmpDir)) {
            Log::error('Claim ZIP export: could not create temp dir', ['dir' => $tmpDir, 'export_id' => $export->id]);
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'Could not create a temporary working folder on the server.',
                'completed_at' => now(),
            ]);

            return;
        }
        // The parts are built in their own scratch directory, separate from the one holding the
        // rendered PDFs, so both can be swept independently and neither can pick up the other's
        // files when it is cleared.
        $zipDir = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'claim-zip-parts-'.bin2hex(random_bytes(8));
        if (! @mkdir($zipDir, 0700, true) && ! is_dir($zipDir)) {
            $service->deleteTempDir($tmpDir);
            Log::error('Claim ZIP export: could not create the archive folder', ['dir' => $zipDir, 'export_id' => $export->id]);
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'Could not start the ZIP file on the server.',
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            // Zero or negative means "never split" — a deliberate escape hatch back to the
            // single-archive behaviour, and unambiguous in a way a silent floor would not be.
            $maxPartBytes = (int) config('claims.zip_export.max_part_bytes', 25 * 1024 * 1024);

            $result = $service->renderZipParts(
                $claims,
                $tmpDir,
                $zipDir,
                $maxPartBytes > 0 ? $maxPartBytes : PHP_INT_MAX,
                function (int $count) use ($export) {
                    $export->update(['rendered_count' => $count]);
                }
            );

            $notes = $service->exportNotes($matched->count(), count($result['used']), $omitted, $result['failed'], $cap);
            $result['parts'] = $service->finalizeParts($result['parts'], $notes);
        } catch (\Throwable $e) {
            $service->deleteTempDir($tmpDir);
            $service->deleteTempDir($zipDir);
            report($e);
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'The export failed while building the file. Please try again.',
                'completed_at' => now(),
            ]);

            return;
        }

        $service->deleteTempDir($tmpDir);

        if (empty($result['used']) || empty($result['parts'])) {
            $service->deleteTempDir($zipDir);
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'None of the matching claims could be rendered to PDF. Please contact IT.',
                'failed_claims' => $result['failed'],
                'completed_at' => now(),
            ]);

            return;
        }

        Storage::disk('local')->makeDirectory(ExpenseClaimZipExport::DIRECTORY);
        $this->allowWebServerToReadDownload(Storage::disk('local')->path(ExpenseClaimZipExport::DIRECTORY), 0750);

        // A single-part export keeps the `{id}.zip` name it has always had, so nothing that
        // predates the split — stored rows, the prune sweep, the download route — sees any
        // change at all in the ordinary case.
        $multi = count($result['parts']) > 1;
        $stored = [];

        foreach ($result['parts'] as $i => $part) {
            $number = $i + 1;
            $destination = ExpenseClaimZipExport::DIRECTORY.'/'.$export->id.($multi ? '-part'.$number : '').'.zip';

            // STREAMED into place, never read whole. `file_get_contents()` here held the entire
            // finished archive in one string — and this is precisely the archive that was
            // observed live at ~217 MiB, which is what forced downloadZipExport() off
            // fpassthru() onto fixed 1 MB chunks. The same file, on the same box, was still
            // being slurped in full one step earlier. That is not free even in the worker:
            // raisePdfMemoryFloor() lifts this process to config('claims.pdf_memory_limit')
            // (512M by default), so a couple more cycles' growth would turn a successful export
            // into a fatal allocation at the very last step, after every PDF had been rendered.
            // Peak memory is bounded by the copy buffer regardless of archive size — the same
            // rule renderZipParts() already follows when building it.
            $handle = fopen($part['path'], 'rb');
            if ($handle === false) {
                $service->deleteTempDir($zipDir);
                foreach ($stored as $done) {
                    Storage::disk('local')->delete($done['path']);
                }
                Log::error('Claim ZIP export: could not reopen a built archive', ['zip' => $part['path'], 'export_id' => $export->id]);
                $export->update([
                    'status' => ExpenseClaimZipExport::STATUS_FAILED,
                    'error' => 'Could not read the finished ZIP file on the server.',
                    'completed_at' => now(),
                ]);

                return;
            }

            Storage::disk('local')->writeStream($destination, $handle);

            // Flysystem's local adapter copies the stream but does not close the source, so
            // this is ours to close — guarded because a future adapter that DOES close it would
            // make an unconditional fclose() a TypeError on an already-closed resource.
            if (is_resource($handle)) {
                fclose($handle);
            }

            $this->allowWebServerToReadDownload(Storage::disk('local')->path($destination), 0640);

            $stored[] = [
                'path' => $destination,
                'size' => Storage::disk('local')->size($destination),
                'claims' => $part['claims'],
            ];
        }

        $service->deleteTempDir($zipDir);

        $export->update([
            'status' => ExpenseClaimZipExport::STATUS_READY,
            'parts' => $stored,
            // Materialised cache of part one, so every reader that predates the split keeps
            // working unchanged. partList() is the source of truth.
            'file_path' => $stored[0]['path'],
            'file_size' => $stored[0]['size'],
            'omitted_claims' => $omitted->isNotEmpty()
                ? $omitted->map(fn ($c) => ($c->claim_number ?: 'Claim #'.$c->id).' — '.($c->employee?->full_name ?? 'unknown employee'))->values()->all()
                : null,
            'failed_claims' => ! empty($result['failed']) ? $result['failed'] : null,
            'completed_at' => now(),
        ]);
    }

    /**
     * Lets the web server (a different OS user than this job on the live NAS — see
     * config('claims.zip_export.storage_group')) read what this job just wrote, without
     * making it world-readable. Group-only, scoped to this one path, root-owned files/dirs
     * only (chgrp/chmod are no-ops for anything this process doesn't own). Silently does
     * nothing when unconfigured (e.g. local dev) or on Windows, where neither call applies.
     */
    private function allowWebServerToReadDownload(string $absolutePath, int $mode): void
    {
        $group = config('claims.zip_export.storage_group');
        if (! $group || PHP_OS_FAMILY === 'Windows') {
            return;
        }

        @chgrp($absolutePath, $group);
        @chmod($absolutePath, $mode);
    }

    public function failed(\Throwable $e): void
    {
        // Reached when the worker itself kills the job (e.g. the $timeout above) rather than
        // handle() catching it — the try/catch inside handle() covers ordinary render
        // failures, but a hard timeout skips straight here.
        $export = ExpenseClaimZipExport::find($this->exportId);
        if ($export && ! $export->isDone()) {
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'The export took too long and was stopped. Try narrowing the filter to one company.',
                'completed_at' => now(),
            ]);
        }
    }
}
