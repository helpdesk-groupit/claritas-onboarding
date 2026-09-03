<?php

namespace Tests\Feature;

use App\Jobs\BuildClaimZipExport;
use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimZipExport;
use App\Models\User;
use App\Services\ClaimZipExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The HR bulk "Export approved PDFs (ZIP)" download.
 *
 * Originally rendered inline in the web request, bounded by a wall-clock budget + a
 * claim-count cap so it never outran nginx's 60s proxy_read_timeout (a 128 MB memory fatal on
 * 2026-08-18 forced the streaming-to-disk fix; two 504s the same day forced the time budget).
 * That design had a real consequence discovered 2026-09-03: the batch was ordered
 * newest-processed-first, so once a cycle's HR-approved claims exceeded the cap, whatever fell
 * off the tail skewed toward the claims approved EARLIEST in the cycle — production's August
 * 2026 cycle had 79 approved claims against a 60-claim cap, so HR only ever saw roughly the
 * most recently approved ones and nothing older, which read as "only today's approvals show
 * up" however many days the actual approvals were spread across.
 *
 * The export now renders in a background job (BuildClaimZipExport, on the same `database`
 * queue that drains Email Workflow sweeps and Social Strategist generation) instead of inline,
 * so it is never bound by the request's lifetime — every matching claim renders, regardless of
 * how many there are or which day they were approved on. See ExpenseClaimZipExport's migration
 * and config('claims.zip_export') for the full design notes.
 */
class ClaimZipExportTest extends TestCase
{
    use RefreshDatabase;

    private function hrManager(): User
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Medical Fees', 'code' => 'C-'.uniqid(), 'gl_code' => '932-000',
            'rate_type' => 'receipt', 'is_active' => true,
        ]);
    }

    /** A processed (HR-approved) claim — the only kind the ZIP export includes. */
    private function processedClaim(ExpenseCategory $cat, string $name, int $month = 6, ?string $receipt = null, ?string $processedAt = null): ExpenseClaim
    {
        $owner = Employee::factory()->create(['company' => 'Enlinea Sdn. Bhd.', 'full_name' => $name]);
        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id, 'year' => 2026, 'month' => $month,
            'claim_number' => 'EC-2026-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'-'.random_int(1000, 9999),
            'title' => 'x', 'event' => 'Test event', 'status' => 'hr_approved',
            'submitted_at' => "2026-{$month}-05 09:00:00",
            'processed_at' => $processedAt ?? "2026-{$month}-25 09:00:00",
        ]);
        $claim->items()->create([
            'expense_category_id' => $cat->id, 'expense_date' => "2026-{$month}-03",
            'description' => 'Consultation', 'amount' => 50, 'total_with_gst' => 50,
            'receipt_path' => $receipt,
        ]);

        return $claim;
    }

    /**
     * Put a real photo-sized receipt on the private disk and return its path.
     *
     * Size matters here: dompdf decodes every embedded image through GD at w*h*4 bytes, and
     * that decode is what production ran out of room for. A 1x1 placeholder would not
     * reproduce the failure this suite exists to pin down.
     */
    private function receiptImage(string $key): string
    {
        $im = imagecreatetruecolor(900, 1200);
        for ($y = 0; $y < 1200; $y += 4) {
            for ($x = 0; $x < 900; $x += 4) {
                imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, imagecolorallocate($im, ($x + $y) % 256, ($x * 7) % 256, ($y * 13) % 256));
            }
        }
        ob_start();
        imagepng($im, null, 1);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        $path = 'claim_receipts/test/'.$key.'.png';
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /** Request an export via the real HTTP endpoint (queues the job — does not run it). */
    private function requestExport(User $hr, array $params = []): ExpenseClaimZipExport
    {
        $response = $this->actingAs($hr)->postJson(route('hr.claims.download-zip'), $params + ['year' => 2026]);
        $response->assertStatus(200)->assertJson(['ok' => true]);

        return ExpenseClaimZipExport::findOrFail($response->json('export_id'));
    }

    /** Run the background job synchronously, the same way the tests for the sibling async jobs do. */
    private function runJob(ExpenseClaimZipExport $export): ExpenseClaimZipExport
    {
        (new BuildClaimZipExport($export->id))->handle(new ClaimZipExportService);

        return $export->refresh();
    }

    private function requestAndRun(User $hr, array $params = []): ExpenseClaimZipExport
    {
        return $this->runJob($this->requestExport($hr, $params));
    }

    /**
     * Open a downloaded ZIP response and return [entryName => contents].
     *
     * Storage::disk('local')->download() (used by downloadZipExport()) returns a
     * StreamedResponse, not a BinaryFileResponse — there is no file path to read off disk, so
     * the content has to be captured by actually sending it into a buffer.
     */
    private function readZip($response): array
    {
        ob_start();
        $response->baseResponse->sendContent();
        $bytes = (string) ob_get_clean();
        $this->assertNotEmpty($bytes, 'The download response produced no content.');

        $tmp = tempnam(sys_get_temp_dir(), 'zip-test-');
        file_put_contents($tmp, $bytes);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'Downloaded file is not a readable ZIP archive.');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $entries;
    }

    // ── Request → job → download, end to end ────────────────────────────────

    public function test_hr_manager_can_export_and_download_the_batch_zip(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $this->processedClaim($cat, 'Bob Approved');

        $export = $this->requestAndRun($this->hrManager());
        $this->assertSame(ExpenseClaimZipExport::STATUS_READY, $export->status);
        $this->assertSame(2, $export->total_matched);

        $response = $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip.file', $export));
        $response->assertStatus(200);

        $entries = $this->readZip($response);
        $this->assertCount(2, $entries, 'Both processed claims should be in the archive.');
        foreach (array_keys($entries) as $name) {
            $this->assertStringEndsWith('.pdf', $name);
        }
        foreach ($entries as $name => $bytes) {
            $this->assertStringStartsWith('%PDF', (string) $bytes, "Entry {$name} is not a real PDF.");
        }
    }

    /**
     * The bug this whole redesign exists to fix: claims approved across several different
     * days within the same cycle must ALL appear, "no matter how many was newly approved" —
     * not just whichever were approved most recently. Confirmed against real production data
     * (August 2026 cycle: 79 approved claims against the old 60-claim cap).
     */
    public function test_every_matching_claim_is_included_regardless_of_which_day_it_was_approved_on(): void
    {
        $cat = $this->category();
        $names = ['Alice', 'Bob', 'Carol', 'Dave', 'Erin', 'Frank', 'Grace', 'Heidi', 'Ivan', 'Judy'];
        foreach ($names as $i => $name) {
            // Spread across ten different approval days within the same June submission cycle —
            // exactly the shape that used to lose the earliest-approved claims off the tail.
            $this->processedClaim($cat, $name, 6, null, sprintf('2026-06-%02d 09:00:00', 10 + $i));
        }

        $export = $this->requestAndRun($this->hrManager());
        $this->assertSame(ExpenseClaimZipExport::STATUS_READY, $export->status);
        $this->assertSame(10, $export->total_matched);
        $this->assertNull($export->omitted_claims, 'Nothing should have been left out for a normal-sized cycle.');

        $response = $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip.file', $export));
        $entries = $this->readZip($response);
        $pdfs = array_filter(array_keys($entries), fn ($n) => str_ends_with($n, '.pdf'));
        $this->assertCount(10, $pdfs, 'Every claim, however many days apart they were approved, must be in the ZIP.');
        $this->assertArrayNotHasKey('_EXPORT-NOTES.txt', $entries, 'A complete export carries no notes file.');
    }

    /**
     * The bug that took production down: every rendered PDF was held in memory until the
     * archive closed, so the resident cost grew with the batch. Peak memory must be flat in
     * the number of claims, not linear. Measured around the JOB now, since that is where
     * rendering happens.
     *
     * This only reproduces with real receipt images attached — an image-less claim renders a
     * PDF far too small for the accumulation to show above the allocator's 2 MB granularity.
     */
    public function test_peak_memory_does_not_grow_with_the_number_of_claims(): void
    {
        $cat = $this->category();
        $service = new ClaimZipExportService;

        $measure = function (int $count) use ($cat, $service): int {
            ExpenseClaim::query()->delete();
            ExpenseClaimZipExport::query()->delete();
            for ($i = 0; $i < $count; $i++) {
                $this->processedClaim($cat, 'Employee '.$i, 6, $this->receiptImage('r'.$i));
            }

            $export = ExpenseClaimZipExport::create([
                'requested_by_id' => $this->hrManager()->id, 'year' => 2026, 'status' => ExpenseClaimZipExport::STATUS_QUEUED,
            ]);

            gc_collect_cycles();
            // PHP's peak counter is a monotonic high-water mark for the whole process, so
            // without this reset the first (small) run's peak masks every later one.
            memory_reset_peak_usage();
            $floor = memory_get_peak_usage(true);

            (new BuildClaimZipExport($export->id))->handle($service);
            $export->refresh();
            $this->assertSame(ExpenseClaimZipExport::STATUS_READY, $export->status);

            $cost = memory_get_peak_usage(true) - $floor;
            $export->discard();

            return $cost;
        };

        $small = $measure(2);
        $large = $measure(10);

        // 5x the claims must not cost anything like 5x the memory. Streaming keeps only one
        // PDF resident at a time. Allow generous slack for dompdf's font cache and allocator
        // fragmentation — a linear profile blows straight past it.
        $this->assertLessThan(
            max((int) ($small * 1.8), 16 * 1024 * 1024),
            $large,
            sprintf(
                'Peak memory scaled with the batch size (2 claims = %.1f MB, 10 claims = %.1f MB) '.
                '— the export is holding rendered PDFs in memory again.',
                $small / 1048576, $large / 1048576
            )
        );
    }

    /**
     * The sanity ceiling is now only a backstop against a genuinely pathological filter, not
     * normal cycle volume — but it must still report itself rather than truncate silently.
     */
    public function test_the_sanity_ceiling_is_reported_in_the_archive_not_silently_applied(): void
    {
        config(['claims.zip_export.max_claims' => 2]);
        $cat = $this->category();
        foreach (['Alice A', 'Bob B', 'Carol C', 'Dave D'] as $name) {
            $this->processedClaim($cat, $name);
        }

        $export = $this->requestAndRun($this->hrManager());
        $this->assertSame(ExpenseClaimZipExport::STATUS_READY, $export->status);
        $this->assertSame(4, $export->total_matched);
        $this->assertNotNull($export->omitted_claims);
        $this->assertCount(2, $export->omitted_claims);

        $response = $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip.file', $export));
        $entries = $this->readZip($response);

        $pdfs = array_filter(array_keys($entries), fn ($n) => str_ends_with($n, '.pdf'));
        $this->assertCount(2, $pdfs, 'The ceiling should bound the number of PDFs.');

        $this->assertArrayHasKey('_EXPORT-NOTES.txt', $entries, 'A truncated export must carry a notes file.');
        $notes = $entries['_EXPORT-NOTES.txt'];
        $this->assertStringContainsString('Matched by your filter: 4', $notes);
        $this->assertStringContainsString('Included in this ZIP:   2', $notes);
        $this->assertStringContainsString('NOT INCLUDED', $notes);
        $this->assertStringContainsString('safety ceiling', $notes);

        // The two left out must be named, so the operator knows exactly what happened.
        $omitted = ExpenseClaim::orderByDesc('processed_at')->get()->slice(2)->values();
        foreach ($omitted as $claim) {
            $this->assertStringContainsString((string) $claim->claim_number, $notes);
        }
    }

    /** A complete export gets no notes file — the archive alone is the answer. */
    public function test_complete_export_carries_no_notes_file(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');

        $export = $this->requestAndRun($this->hrManager());
        $response = $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip.file', $export));
        $entries = $this->readZip($response);

        $this->assertArrayNotHasKey('_EXPORT-NOTES.txt', $entries);
        $this->assertCount(1, $entries);
    }

    /** The job must not strand its per-claim working files, however it ends. */
    public function test_temp_working_files_are_cleaned_up_and_the_archive_persists_for_re_download(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $this->processedClaim($cat, 'Bob Approved');

        $before = glob(rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'claim-zip-*');

        $export = $this->requestAndRun($this->hrManager());
        $this->assertSame(ExpenseClaimZipExport::STATUS_READY, $export->status);

        // The per-claim PDFs are deleted as soon as they are inside the archive.
        $after = glob(rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'claim-zip-*');
        $this->assertSame(
            count((array) $before), count((array) $after),
            'The per-claim PDF working directory was left behind.'
        );

        // Unlike the old synchronous download, the stored archive is NOT deleted after being
        // sent — it must survive a page reload or a second click on "Download ZIP" until the
        // retention sweep reaps it.
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));
        $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip.file', $export))->assertStatus(200);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path), 'The archive must survive a download so it can be fetched again.');
        $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip.file', $export))->assertStatus(200);
    }

    /**
     * The job raises the memory ceiling because a batch render legitimately needs the room —
     * but a flat ini_set is a ceiling as often as it is a floor. Same rule as the Email
     * Workflow sweep: raise a stingy limit, leave a generous or unlimited one alone.
     */
    public function test_memory_limit_is_a_floor_not_a_flat_setting(): void
    {
        $cat = $this->category();
        $service = new ClaimZipExportService;
        $hr = $this->hrManager();
        $original = ini_get('memory_limit');

        $run = function () use ($cat, $hr, $service): void {
            ExpenseClaim::query()->delete();
            $this->processedClaim($cat, 'Alice Approved');
            $export = ExpenseClaimZipExport::create([
                'requested_by_id' => $hr->id, 'year' => 2026, 'status' => ExpenseClaimZipExport::STATUS_QUEUED,
            ]);
            (new BuildClaimZipExport($export->id))->handle($service);
            $export->refresh();
            $this->assertSame(ExpenseClaimZipExport::STATUS_READY, $export->status);
        };

        try {
            config(['claims.pdf_memory_limit' => '384M']);

            ini_set('memory_limit', '128M');
            $run();
            $this->assertSame('384M', ini_get('memory_limit'), 'A stingy limit should have been raised.');

            ini_set('memory_limit', '1G');
            $run();
            $this->assertSame('1G', ini_get('memory_limit'), 'A generous limit must not be lowered.');

            ini_set('memory_limit', '-1');
            $run();
            $this->assertSame('-1', ini_get('memory_limit'), 'An unlimited limit must not be capped.');
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }

    // ── Request endpoint ─────────────────────────────────────────────────────

    public function test_requesting_an_export_dispatches_the_background_job(): void
    {
        Queue::fake();
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');

        $response = $this->actingAs($this->hrManager())->postJson(route('hr.claims.download-zip'), ['year' => 2026]);
        $response->assertStatus(200)->assertJson(['ok' => true, 'total_matched' => 1]);

        Queue::assertPushed(BuildClaimZipExport::class);
        $this->assertDatabaseHas('expense_claim_zip_exports', [
            'id' => $response->json('export_id'),
            'status' => ExpenseClaimZipExport::STATUS_QUEUED,
            'total_matched' => 1,
        ]);
    }

    public function test_non_hr_cannot_request_check_or_download_an_export(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $export = $this->requestAndRun($this->hrManager());

        $employee = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($employee)->create();

        $this->actingAs($employee)->postJson(route('hr.claims.download-zip'), ['year' => 2026])->assertStatus(403);
        $this->actingAs($employee)->get(route('hr.claims.download-zip.status', $export))->assertStatus(403);
        $this->actingAs($employee)->get(route('hr.claims.download-zip.file', $export))->assertStatus(403);
    }

    public function test_empty_filter_is_refused_immediately_without_queuing_a_job(): void
    {
        Queue::fake();

        $this->actingAs($this->hrManager())
            ->postJson(route('hr.claims.download-zip'), ['year' => 2019])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('expense_claim_zip_exports', 0);
    }

    // ── Status polling ───────────────────────────────────────────────────────

    public function test_status_endpoint_reports_progress_then_readiness(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $this->processedClaim($cat, 'Bob Approved');
        $hr = $this->hrManager();

        $export = $this->requestExport($hr);
        $queued = $this->actingAs($hr)->getJson(route('hr.claims.download-zip.status', $export));
        $queued->assertStatus(200)->assertJson(['status' => 'queued', 'ready' => false, 'total_matched' => 2]);

        $this->runJob($export);

        $ready = $this->actingAs($hr)->getJson(route('hr.claims.download-zip.status', $export));
        $ready->assertStatus(200)->assertJson(['status' => 'ready', 'ready' => true, 'rendered_count' => 2]);
        $this->assertNotNull($ready->json('download_url'));
    }

    public function test_download_is_refused_before_the_export_is_ready(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $export = $this->requestExport($this->hrManager()); // job not run — still queued

        $this->actingAs($this->hrManager())
            ->get(route('hr.claims.download-zip.file', $export))
            ->assertStatus(404);
    }

    // ── The HR Claims page itself ───────────────────────────────────────────

    /** Pins the rewritten modal markup and catches any Blade compile error in it. */
    public function test_the_hr_claims_page_renders_the_export_modal(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');

        $response = $this->actingAs($this->hrManager())->get(route('hr.claims.index', ['year' => 2026]));

        $response->assertStatus(200);
        $response->assertSee('id="exportZipModal"', false);
        $response->assertSee('id="exportZipForm"', false);
        $response->assertSee('id="exportZipSubmit"', false);
        $response->assertSee('id="exportZipProgress"', false);
    }

    // ── Retention sweep ──────────────────────────────────────────────────────

    public function test_prune_command_discards_finished_exports_past_retention_and_their_file(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $export = $this->requestAndRun($this->hrManager());
        $path = $export->file_path;
        $this->assertTrue(Storage::disk('local')->exists($path));

        // A finished export inside the window survives.
        $this->artisan('claims:prune-zip-exports')->assertExitCode(0);
        $this->assertModelExists($export);
        $this->assertTrue(Storage::disk('local')->exists($path));

        // Past the retention window, both the row and the file go.
        $export->forceFill(['completed_at' => now()->subHours(72)])->save();
        $this->artisan('claims:prune-zip-exports')->assertExitCode(0);
        $this->assertDatabaseMissing('expense_claim_zip_exports', ['id' => $export->id]);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_prune_dry_run_discards_nothing(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $export = $this->requestAndRun($this->hrManager());
        $export->forceFill(['completed_at' => now()->subHours(72)])->save();

        $this->artisan('claims:prune-zip-exports --dry-run')->assertExitCode(0);

        $this->assertModelExists($export);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));
    }
}
