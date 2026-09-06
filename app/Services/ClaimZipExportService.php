<?php

namespace App\Services;

use App\Models\Company;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimPolicy;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The matching + rendering engine behind "Export approved PDFs (ZIP)".
 *
 * Used by both ExpenseClaimController::index() (to populate the modal's cycle/company
 * options) and BuildClaimZipExport (the background job that actually renders the archive),
 * so the two can never disagree about what a cycle contains.
 *
 * Rendering used to happen inline in the web request, bounded by a wall-clock budget so it
 * never outran nginx's 60s proxy_read_timeout. That bound is gone here on purpose: this
 * class is only ever called from a queued job now (see BuildClaimZipExport), which has no
 * request to time out — so every matching claim renders, however many there are. What
 * remains is a generous sanity ceiling (config('claims.zip_export.max_claims')) against a
 * genuinely pathological filter, not normal cycle volume.
 */
class ClaimZipExportService
{
    /** Per-instance cache of company name → claim submission cutoff day. */
    private array $companyCutoffCache = [];

    public function companyCutoffDay(?string $company): int
    {
        $key = (string) $company;
        if (! array_key_exists($key, $this->companyCutoffCache)) {
            $this->companyCutoffCache[$key] = (int) (ExpenseClaimPolicy::forCompany($company)->submission_deadline_day ?? 20);
        }

        return $this->companyCutoffCache[$key];
    }

    /**
     * The approval cutoff cycle [year, month] a claim falls in, using its company's cutoff —
     * 21st of the previous month to the 20th of this one, measured from the date the claim was
     * fully approved (processed_at, stamped when HR approves — see hrApprove()/bulkApprove()),
     * NOT the date it was submitted (changed 2026-09-06, reversing the 2026-09-05 submission-
     * cycle design). A claim submitted in one cycle is often approved in a later one
     * (corrections, rejection/resubmission loops, a manager on leave), and both HR's and
     * Finance's monthly pack are meant to reflect what actually landed as approved spend in
     * that 21st–20th window, not when the employee first typed the claim in.
     *
     * Falls back to submitted_at/created_at only for a claim with no processed_at yet — which
     * matchingClaims() never passes in (it gates on processed_at IS NOT NULL), but the HR "all
     * statuses" CSV export does, since its whole purpose includes claims that are not approved.
     */
    public function claimCycle(ExpenseClaim $claim): array
    {
        $when = $claim->processed_at ?? $claim->submitted_at ?? $claim->created_at;

        return ClaimRulesService::submissionCycle($when, $this->companyCutoffDay($claim->resolvedCompany()));
    }

    /**
     * Coarse [start, endExclusive) calendar range that safely contains a cutoff cycle, so the
     * DB query stays bounded before the precise per-company cycle filter runs in PHP. A
     * (year, month) cycle only touches calendar months month-1 and month; a year-only request
     * spans Dec(year-1)–Dec(year).
     */
    public function cycleFetchRange(?int $year, ?int $month): array
    {
        if (! $year) {
            return [null, null];
        }
        if ($month) {
            $anchor = Carbon::create($year, $month, 1)->startOfDay();

            return [$anchor->copy()->subMonthNoOverflow(), $anchor->copy()->addMonthNoOverflow()];
        }

        return [Carbon::create($year - 1, 12, 1)->startOfDay(), Carbon::create($year + 1, 1, 1)->startOfDay()];
    }

    /**
     * Every PROCESSED (HR-approved, processed_at stamped) claim matching the cycle year/month
     * and optional company/employee filters — ordered newest-processed-first. Re-run fresh
     * every time this is called (never cached across requests), so a claim approved a minute
     * ago is included exactly like one approved weeks ago.
     */
    public function matchingClaims(?int $year, ?int $month, array $companies = [], array $employeeIds = []): Collection
    {
        $q = ExpenseClaim::whereNotNull('processed_at')->with(['employee', 'items.category']);

        $employeeIds = array_values(array_filter($employeeIds));
        if (! empty($employeeIds)) {
            $q->whereIn('employee_id', $employeeIds);
        }

        $companies = array_values(array_filter($companies, fn ($v) => $v !== '' && $v !== null));
        if (! empty($companies)) {
            // Filter by the claim's snapshot company (set at submission), so a claim stays in
            // the company it was submitted under even after the employee moves.
            $q->whereIn('company', $companies);
        }

        // The coarse bound reads processed_at directly (never a COALESCE) because every row
        // here already satisfies whereNotNull('processed_at') above — that is also the field
        // claimCycle() keys on for a processed claim, so the two stay in exact lockstep.
        [$rangeStart, $rangeEnd] = $this->cycleFetchRange($year, $month);
        if ($rangeStart) {
            $q->where('processed_at', '>=', $rangeStart->toDateTimeString());
        }
        if ($rangeEnd) {
            $q->where('processed_at', '<', $rangeEnd->toDateTimeString());
        }

        return $q->orderByDesc('processed_at')->get()
            ->filter(function (ExpenseClaim $claim) use ($year, $month) {
                $cycle = $this->claimCycle($claim);

                return (! $year || $cycle['year'] === $year) && (! $month || $cycle['month'] === $month);
            })
            ->values();
    }

    /**
     * Distinct cutoff-cycle years across every processed claim, newest first — the year list
     * the finance report offers when it reads by cycle.
     *
     * Deliberately NOT a `DISTINCT expense_claims.year`: that column is the reporting-month
     * stamp, a different axis entirely, and even a DISTINCT on the approval year would be
     * wrong at the boundary — a claim approved 28 Dec belongs to the NEXT year's January
     * cycle and would otherwise be missing from the year it is actually exported under.
     */
    public function availableCycleYears(): array
    {
        return ExpenseClaim::whereNotNull('processed_at')
            ->with('employee:id,company')
            ->get(['id', 'company', 'employee_id', 'processed_at', 'submitted_at', 'created_at'])
            ->map(fn (ExpenseClaim $claim) => $this->claimCycle($claim)['year'])
            ->unique()->sortDesc()->values()
            ->map(fn ($year) => (int) $year)
            ->all();
    }

    public function buildClaimPdf(ExpenseClaim $claim): string
    {
        // Per-IMAGE cost, so this matters for a single claim too, not just the batch export:
        // one 5-megapixel receipt is ~22 MB of GD buffer inside dompdf and a claim may carry
        // several. Idempotent and cheap, so the ZIP loop calling it repeatedly is harmless.
        $this->raisePdfMemoryFloor();

        $claim->loadMissing('items.category', 'employee', 'managerApprover', 'manager', 'hrApprover');
        $company = Company::forName($claim->resolvedCompany());
        $items = $claim->items;

        return ClaimReportRenderer::render($claim, $company, $items);
    }

    /**
     * Render every claim in $claims into a ZIP at $zipPath. Streams each PDF to a temp file
     * and drops every reference before the next render — peak memory is flat in batch size
     * (see the 2026-08-18 production incident this pattern exists to prevent), which matters
     * more here than it did inline, since a background render has no reason to hurry.
     *
     * $onProgress, if given, is called after each claim with the running rendered-or-failed
     * count so the caller can keep a poller's progress figure current.
     *
     * Returns ['used' => ['Name.pdf' => true, ...], 'failed' => ['EC-... — Employee', ...]].
     */
    public function renderZip(Collection $claims, \ZipArchive $zip, string $tmpDir, ?callable $onProgress = null): array
    {
        $used = [];
        $failed = [];

        foreach ($claims as $index => $claim) {
            $name = $claim->pdfFilename();
            $unique = $name;
            $i = 2;
            while (isset($used[$unique])) {
                $unique = preg_replace('/\.pdf$/', '', $name)." ({$i}).pdf";
                $i++;
            }

            $pdfPath = $tmpDir.DIRECTORY_SEPARATOR.$index.'.pdf';

            try {
                $pdf = $this->buildClaimPdf($claim);
                file_put_contents($pdfPath, $pdf);
                unset($pdf);
                gc_collect_cycles();
            } catch (\Throwable $e) {
                // One unrenderable claim must not cost the whole export — but it is named in
                // _EXPORT-NOTES.txt so it can never pass unnoticed.
                report($e);
                @unlink($pdfPath);
                $failed[] = ($claim->claim_number ?: 'Claim #'.$claim->id).' — '.($claim->employee?->full_name ?? 'unknown employee');
                if ($onProgress) {
                    $onProgress($index + 1);
                }

                continue;
            }

            $used[$unique] = true;
            $zip->addFile($pdfPath, $unique);
            if ($onProgress) {
                $onProgress($index + 1);
            }
        }

        return ['used' => $used, 'failed' => $failed];
    }

    /**
     * Plain-language manifest for a ZIP export, written only when the archive is NOT the
     * complete answer to the filter — i.e. the sanity ceiling truncated it, or a claim failed
     * to render. Silence over a partial finance export is the failure mode worth engineering
     * against; an operator who gets every file they asked for gets no notes file at all.
     */
    public function exportNotes(int $matchedCount, int $includedCount, Collection $omitted, array $failed, int $cap): ?string
    {
        if ($omitted->isEmpty() && empty($failed)) {
            return null;
        }

        $lines = [
            'Claim PDF export — '.now()->format('d-m-Y H:i'),
            'Matched by your filter: '.$matchedCount,
            'Included in this ZIP:   '.$includedCount,
            '',
        ];

        if ($omitted->isNotEmpty()) {
            $lines[] = 'NOT INCLUDED — this filter matched more than the '.$cap.'-claim safety ceiling ('.$omitted->count().' left out).';
            $lines[] = 'Re-run the export filtered to one company, or contact IT if a cycle is genuinely this large:';
            foreach ($omitted as $claim) {
                $lines[] = '  - '.($claim->claim_number ?: 'Claim #'.$claim->id)
                    .' — '.($claim->employee?->full_name ?? 'unknown employee')
                    .' — '.($claim->resolvedCompany() ?: 'no company');
            }
            $lines[] = '';
        }

        if (! empty($failed)) {
            $lines[] = 'FAILED TO RENDER — these claims are missing from this ZIP ('.count($failed).').';
            $lines[] = 'Download them individually from the claim page, and report this to IT:';
            foreach ($failed as $f) {
                $lines[] = '  - '.$f;
            }
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    public function deleteTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    private function raisePdfMemoryFloor(): void
    {
        $floor = (string) config('claims.pdf_memory_limit', '');
        if ($floor === '') {
            return;
        }

        $current = ini_get('memory_limit');
        // -1 means unlimited: already better than anything we would set.
        if ($current === false || trim((string) $current) === '-1') {
            return;
        }

        $toBytes = function (string $value): int {
            $value = trim($value);
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        if ($toBytes((string) $current) < $toBytes($floor)) {
            @ini_set('memory_limit', $floor);
        }
    }
}
