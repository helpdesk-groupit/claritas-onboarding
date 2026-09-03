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
        // before this job ran is still picked up here.
        $matched = $service->matchingClaims($export->year, $export->month, $export->companies ?? [], $export->employee_ids ?? []);

        if ($matched->isEmpty()) {
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'No processed claims match the filter any more.',
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
        $zipPath = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'claims-'.bin2hex(random_bytes(8)).'.zip';

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $service->deleteTempDir($tmpDir);
            Log::error('Claim ZIP export: could not open archive', ['zip' => $zipPath, 'export_id' => $export->id]);
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'Could not start the ZIP file on the server.',
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            $result = $service->renderZip($claims, $zip, $tmpDir, function (int $count) use ($export) {
                $export->update(['rendered_count' => $count]);
            });

            if ($notes = $service->exportNotes($matched->count(), count($result['used']), $omitted, $result['failed'], $cap)) {
                $zip->addFromString('_EXPORT-NOTES.txt', $notes);
            }
            $zip->close();
        } catch (\Throwable $e) {
            try {
                $zip->close();
            } catch (\Throwable) {
                // Already broken — discarding either way.
            }
            $service->deleteTempDir($tmpDir);
            @unlink($zipPath);
            report($e);
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'The export failed while building the file. Please try again.',
                'completed_at' => now(),
            ]);

            return;
        }

        $service->deleteTempDir($tmpDir);

        if (empty($result['used'])) {
            @unlink($zipPath);
            $export->update([
                'status' => ExpenseClaimZipExport::STATUS_FAILED,
                'error' => 'None of the matching claims could be rendered to PDF. Please contact IT.',
                'failed_claims' => $result['failed'],
                'completed_at' => now(),
            ]);

            return;
        }

        $destination = ExpenseClaimZipExport::DIRECTORY.'/'.$export->id.'.zip';
        Storage::disk('local')->makeDirectory(ExpenseClaimZipExport::DIRECTORY);
        $bytes = file_get_contents($zipPath);
        Storage::disk('local')->put($destination, $bytes);
        @unlink($zipPath);
        unset($bytes);

        $export->update([
            'status' => ExpenseClaimZipExport::STATUS_READY,
            'file_path' => $destination,
            'file_size' => Storage::disk('local')->size($destination),
            'omitted_claims' => $omitted->isNotEmpty()
                ? $omitted->map(fn ($c) => ($c->claim_number ?: 'Claim #'.$c->id).' — '.($c->employee?->full_name ?? 'unknown employee'))->values()->all()
                : null,
            'failed_claims' => ! empty($result['failed']) ? $result['failed'] : null,
            'completed_at' => now(),
        ]);
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
