<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HR bulk "Export approved PDFs (ZIP)" download.
 *
 * Production died here on 2026-08-18 with a 128 MB fatal inside dompdf: the export rendered
 * every claim with addFromString(), so all PDFs sat in memory at once, and the 4th claim's
 * receipt then needed a 21 MB GD buffer that no longer fitted. The export now streams each
 * PDF to a temp file, so peak memory is flat in the number of claims — and the batch is
 * bounded by wall clock, with the omissions written into the archive instead of silently
 * dropped.
 *
 * The clock that binds is nginx's `proxy_read_timeout 60s` on the site's own vhost, NOT
 * Cloudflare's 100s edge timeout: the NAS gives up first. Because a claim costs ~0.8s
 * receipt-less and 4.4-4.9s carrying several 7-megapixel photos (measured on the production
 * NAS, 2026-08-18), the bound is a time budget the loop projects against, not a claim count.
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
    private function processedClaim(ExpenseCategory $cat, string $name, int $month = 6, ?string $receipt = null): ExpenseClaim
    {
        $owner = Employee::factory()->create(['company' => 'Enlinea Sdn. Bhd.', 'full_name' => $name]);
        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id, 'year' => 2026, 'month' => $month,
            'claim_number' => 'EC-2026-'.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'-'.random_int(1000, 9999),
            'title' => 'x', 'event' => 'Test event', 'status' => 'hr_approved',
            'submitted_at' => "2026-{$month}-05 09:00:00",
            'processed_at' => "2026-{$month}-25 09:00:00",
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
        // Noise, so the PNG does not compress down to nothing and the embedded base64 is
        // representative of a real photographed receipt.
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
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /**
     * Open a downloaded ZIP response and return [entryName => contents].
     *
     * The endpoint returns a BinaryFileResponse (response()->download()), whose file is only
     * removed once the response is actually SENT — which a test never does — so we can read
     * the archive straight off disk and clean it up ourselves.
     */
    private function readZip($response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($path, 'The download response points at a file that is not there.');

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'Downloaded file is not a readable ZIP archive.');

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $entries[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($path);

        return $entries;
    }

    public function test_hr_manager_can_download_the_batch_zip(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $this->processedClaim($cat, 'Bob Approved');

        $response = $this->actingAs($this->hrManager())
            ->get(route('hr.claims.download-zip', ['year' => 2026]));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/zip');

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
     * The bug that took production down: every rendered PDF was held in memory until the
     * archive closed, so the resident cost grew with the batch. Peak memory must be flat in
     * the number of claims, not linear.
     *
     * This only reproduces with real receipt images attached — an image-less claim renders a
     * PDF far too small for the accumulation to show above the allocator's 2 MB granularity,
     * which is exactly why an earlier version of this test passed against the broken code.
     */
    public function test_peak_memory_does_not_grow_with_the_number_of_claims(): void
    {
        $cat = $this->category();
        $hr = $this->hrManager();

        $measure = function (int $count) use ($cat, $hr): int {
            ExpenseClaim::query()->delete();
            for ($i = 0; $i < $count; $i++) {
                $this->processedClaim($cat, 'Employee '.$i, 6, $this->receiptImage('r'.$i));
            }

            gc_collect_cycles();
            // PHP's peak counter is a monotonic high-water mark for the whole process, so
            // without this reset the first (small) run's peak masks every later one.
            memory_reset_peak_usage();
            $floor = memory_get_peak_usage(true);

            $response = $this->actingAs($hr)->get(route('hr.claims.download-zip', ['year' => 2026]));
            $response->assertStatus(200);
            $cost = memory_get_peak_usage(true) - $floor;
            @unlink($response->baseResponse->getFile()->getPathname());

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
     * A finance export that quietly stops at the cap is worse than one that says so.
     */
    public function test_batch_cap_is_reported_in_the_archive_not_silently_applied(): void
    {
        config(['claims.zip_export.max_claims' => 2]);
        $cat = $this->category();
        foreach (['Alice A', 'Bob B', 'Carol C', 'Dave D'] as $name) {
            $this->processedClaim($cat, $name);
        }

        $response = $this->actingAs($this->hrManager())
            ->get(route('hr.claims.download-zip', ['year' => 2026]));
        $response->assertStatus(200);

        $entries = $this->readZip($response);

        $pdfs = array_filter(array_keys($entries), fn ($n) => str_ends_with($n, '.pdf'));
        $this->assertCount(2, $pdfs, 'The cap should bound the number of PDFs.');

        $this->assertArrayHasKey('_EXPORT-NOTES.txt', $entries, 'A truncated export must carry a notes file.');
        $notes = $entries['_EXPORT-NOTES.txt'];
        $this->assertStringContainsString('Matched by your filter: 4', $notes);
        $this->assertStringContainsString('Included in this ZIP:   2', $notes);
        $this->assertStringContainsString('NOT INCLUDED', $notes);

        // The two left out must be named, so the operator knows exactly what to re-run for.
        $omitted = ExpenseClaim::orderByDesc('processed_at')->get()->slice(2)->values();
        $this->assertCount(2, $omitted);
        foreach ($omitted as $claim) {
            $this->assertStringContainsString((string) $claim->claim_number, $notes);
        }
    }

    /**
     * The bound that actually protects this endpoint is TIME, not a claim count.
     *
     * nginx closes the request at `proxy_read_timeout 60s` (Cloudflare's 100s never gets a
     * chance), and a claim costs anywhere from ~0.8s receipt-less to ~5s carrying several
     * 7-megapixel photos — measured on the production NAS 2026-08-18. So a fixed count cannot
     * express the limit, and the loop stops on the clock instead.
     */
    public function test_the_export_stops_on_the_time_budget_and_names_what_it_left_out(): void
    {
        // Small enough that the projection trips after the first claim, whatever the hardware.
        config(['claims.zip_export.time_budget' => 0.0001]);
        $cat = $this->category();
        foreach (['Alice A', 'Bob B', 'Carol C'] as $name) {
            $this->processedClaim($cat, $name);
        }

        $entries = $this->readZip(
            $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip', ['year' => 2026]))
        );

        $pdfs = array_filter(array_keys($entries), fn ($n) => str_ends_with($n, '.pdf'));
        $this->assertCount(1, $pdfs, 'The first claim must always be rendered — a bound that returns nothing is worse than a partial archive.');

        $notes = $entries['_EXPORT-NOTES.txt'] ?? '';
        $this->assertStringContainsString('time limit', $notes, 'A time-bounded stop must say so — "batch limit of 60" while holding 1 PDF reads as a broken export.');
        $this->assertStringContainsString('Included in this ZIP:   1', $notes);

        // Both survivors named, so the operator knows exactly what to re-run for.
        foreach (ExpenseClaim::orderByDesc('processed_at')->get()->slice(1) as $claim) {
            $this->assertStringContainsString((string) $claim->claim_number, $notes);
        }
    }

    /** The count cap is a backstop now, so it must still name itself when IT is what bit. */
    public function test_the_count_backstop_is_reported_as_a_count_not_as_a_timeout(): void
    {
        config(['claims.zip_export.max_claims' => 1, 'claims.zip_export.time_budget' => 600]);
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice A');
        $this->processedClaim($cat, 'Bob B');

        $entries = $this->readZip(
            $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip', ['year' => 2026]))
        );

        $notes = $entries['_EXPORT-NOTES.txt'] ?? '';
        $this->assertStringContainsString('batch limit of 1 claims', $notes);
        $this->assertStringNotContainsString('time limit', $notes);
    }

    /** A budget the batch never approaches must not truncate anything. */
    public function test_a_generous_time_budget_leaves_the_export_whole(): void
    {
        config(['claims.zip_export.time_budget' => 600]);
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice A');
        $this->processedClaim($cat, 'Bob B');

        $entries = $this->readZip(
            $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip', ['year' => 2026]))
        );

        $this->assertCount(2, $entries);
        $this->assertArrayNotHasKey('_EXPORT-NOTES.txt', $entries);
    }

    /** A complete export gets no notes file — the archive alone is the answer. */
    public function test_complete_export_carries_no_notes_file(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');

        $entries = $this->readZip(
            $this->actingAs($this->hrManager())->get(route('hr.claims.download-zip', ['year' => 2026]))
        );

        $this->assertArrayNotHasKey('_EXPORT-NOTES.txt', $entries);
        $this->assertCount(1, $entries);
    }

    /** The export must not strand its working files, however it ends. */
    public function test_temp_working_files_are_cleaned_up(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $this->processedClaim($cat, 'Bob Approved');

        $before = glob(rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'claim-zip-*');

        $response = $this->actingAs($this->hrManager())
            ->get(route('hr.claims.download-zip', ['year' => 2026]));
        $response->assertStatus(200);

        // The per-claim PDFs are deleted as soon as they are inside the archive — before the
        // response is even returned — so nothing is left waiting on a send that may not come.
        $after = glob(rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.'claim-zip-*');
        $this->assertSame(
            count((array) $before), count((array) $after),
            'The per-claim PDF working directory was left behind.'
        );

        // And the archive itself goes once it has actually been sent. Send it for real (into
        // an output buffer) rather than trusting a flag — deleteFileAfterSend has no public
        // getter, and what matters is the file being gone, not the flag being set.
        $zipPath = $response->baseResponse->getFile()->getPathname();
        $this->assertFileExists($zipPath);

        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        $this->assertFileDoesNotExist($zipPath, 'The ZIP was stranded in the temp dir after download.');
    }

    /**
     * The export raises the memory ceiling because a batch render legitimately needs the room
     * — but a flat ini_set is a ceiling as often as it is a floor. Same rule as the Email
     * Workflow sweep: raise a stingy limit, leave a generous or unlimited one alone.
     *
     * The floor is read from claims.pdf_memory_limit and applied inside buildClaimPdf(), not
     * on this endpoint, because the cost it covers is per-IMAGE and a single-claim download
     * pays it too. Setting the retired claims.zip_export.memory_limit here would still pass —
     * the new key's default is also 512M — so it would assert nothing at all.
     */
    public function test_memory_limit_is_a_floor_not_a_flat_setting(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');
        $hr = $this->hrManager();
        $original = ini_get('memory_limit');

        try {
            config(['claims.pdf_memory_limit' => '384M']);

            // Stingy → raised to the floor.
            ini_set('memory_limit', '128M');
            $this->actingAs($hr)->get(route('hr.claims.download-zip', ['year' => 2026]))->assertStatus(200);
            $this->assertSame('384M', ini_get('memory_limit'), 'A stingy limit should have been raised.');

            // Already generous → left alone, not capped down to the floor.
            ini_set('memory_limit', '1G');
            $this->actingAs($hr)->get(route('hr.claims.download-zip', ['year' => 2026]))->assertStatus(200);
            $this->assertSame('1G', ini_get('memory_limit'), 'A generous limit must not be lowered.');

            // Unlimited → left alone. Capping this would be strictly harmful.
            ini_set('memory_limit', '-1');
            $this->actingAs($hr)->get(route('hr.claims.download-zip', ['year' => 2026]))->assertStatus(200);
            $this->assertSame('-1', ini_get('memory_limit'), 'An unlimited limit must not be capped.');
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }

    public function test_non_hr_cannot_download_the_batch_zip(): void
    {
        $cat = $this->category();
        $this->processedClaim($cat, 'Alice Approved');

        $employee = User::factory()->create(['role' => 'employee']);
        Employee::factory()->withUser($employee)->create();

        $this->actingAs($employee)
            ->get(route('hr.claims.download-zip', ['year' => 2026]))
            ->assertStatus(403);
    }

    public function test_empty_filter_returns_a_message_not_an_empty_archive(): void
    {
        $this->actingAs($this->hrManager())
            ->from(route('hr.claims.index'))
            ->get(route('hr.claims.download-zip', ['year' => 2019]))
            ->assertRedirect(route('hr.claims.index'))
            ->assertSessionHas('error');
    }
}
