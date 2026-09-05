<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Services\ClaimReportRenderer;
use App\Support\ClaimPdfPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

/**
 * A PDF receipt the free FPDI parser cannot open, reaching the downloaded report anyway.
 *
 * Measured on production 2026-09-05: of 119 PDF receipts, 115 append correctly and 4 fail —
 * every one of them CrossReferenceException::COMPRESSED_XREF (a compressed cross-reference
 * stream, standard in PDF 1.5+), and NOT ONE of them encrypted, despite the form telling the
 * approver the file "may be encrypted or password-protected" on all four. Three of the four
 * belong to hr_approved claims, so the copy of record for an approved expense carried a
 * sentence where its evidence should have been.
 *
 * The server cannot rasterise (no Ghostscript, Imagick or Poppler on the NAS) and the free
 * parser cannot decompress, so the pages pdf.js already captures in the browser are the only
 * route that receipt has into the download.
 */
class ClaimPdfRasterFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Preview paths are DERIVED from the source path, so without a fake disk one test's
        // page 1 satisfies the next test's already-exists short-circuit — the trap
        // ClaimPdfPreviewTest documents.
        Storage::fake('local');
    }

    /**
     * A real PDF that the free parser genuinely refuses, reproducing production's failure
     * rather than approximating it: startxref points at an indirect object that is a stream
     * of /Type /XRef, which is exactly the shape FPDI answers with COMPRESSED_XREF. Verified
     * to raise code 267 and to sniff as application/pdf, so it clears the MIME gate first.
     */
    private function compressedXrefPdf(string $key = 'r1'): string
    {
        $header = "%PDF-1.6\n";
        $object = "1 0 obj\n<< /Type /XRef /Size 2 /W [1 1 1] /Root 2 0 R /Length 6 >>\n"
            ."stream\n\x00\x00\x00\x00\x00\x00\nendstream\nendobj\n";

        $bytes = $header.$object."startxref\n".strlen($header)."\n%%EOF\n";

        $path = 'claim_receipts/test/'.$key.'.pdf';
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /** A PDF the parser opens without complaint, for the untouched happy path. */
    private function readablePdf(string $key = 'ok'): string
    {
        $pdf = new Fpdi;
        $pdf->AddPage();
        $path = 'claim_receipts/test/'.$key.'.pdf';
        Storage::disk('local')->put($path, $pdf->Output('S'));

        return $path;
    }

    /** Store $count rasterised pages for a PDF, as the browser would post them. */
    private function storePreviews(string $pdfPath, int $count, ?int $total = null): void
    {
        for ($page = 1; $page <= $count; $page++) {
            Storage::disk('local')->put(ClaimPdfPreview::pathFor($pdfPath, $page), $this->jpegBytes($page));
        }

        if ($total !== null) {
            ClaimPdfPreview::recordTotal($pdfPath, $total);
        }
    }

    /** A distinguishable real JPEG — GD must be able to decode it, as FPDF's input does. */
    private function jpegBytes(int $seed = 1): string
    {
        $im = imagecreatetruecolor(120, 160);
        imagefilledrectangle($im, 0, 0, 120, 160, imagecolorallocate($im, 200, ($seed * 40) % 255, 100));
        ob_start();
        imagejpeg($im, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    private function category(): ExpenseCategory
    {
        return ExpenseCategory::create([
            'name' => 'Equipment Rental', 'code' => 'C-'.uniqid(),
            'gl_code' => '915-000', 'rate_type' => 'receipt', 'is_active' => true,
        ]);
    }

    private function approvedClaim(array $receipts): ExpenseClaim
    {
        $owner = Employee::factory()->create([
            'company' => 'Enlinea Sdn. Bhd.', 'full_name' => 'Alice Approved',
        ]);

        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id, 'year' => 2026, 'month' => 7,
            'claim_number' => 'EC-2026-07-'.random_int(1000, 9999),
            'title' => 'x', 'event' => 'Mumprenuer Table Ep1 Shoot', 'status' => 'hr_approved',
            'company' => 'Enlinea Sdn. Bhd.',
            'submitted_at' => '2026-07-05 09:00:00',
            'manager_approved_at' => '2026-07-10 09:00:00',
            'hr_approved_at' => '2026-07-20 09:00:00',
            'processed_at' => '2026-07-25 09:00:00',
        ]);

        $cat = $this->category();
        foreach ($receipts as $i => $receipt) {
            $claim->items()->create([
                'expense_category_id' => $cat->id,
                'expense_date' => '2026-07-0'.($i + 1),
                'description' => 'Equipment Rental',
                'amount' => 480, 'total_with_gst' => 480,
                'receipt_path' => $receipt,
            ]);
        }

        return $claim->fresh('items');
    }

    private function render(ExpenseClaim $claim): string
    {
        $claim->loadMissing('items.category', 'employee');

        return ClaimReportRenderer::render($claim, null, $claim->items);
    }

    private function pageCount(string $pdf): int
    {
        return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
    }

    /** Page content streams are deflated, so caption text has to be inflated to be read. */
    private function inflatedText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m);
        $out = '';
        foreach ($m[1] as $stream) {
            $data = @gzuncompress($stream) ?: @gzinflate($stream);
            if ($data !== false) {
                $out .= $data;
            }
        }

        return $out;
    }

    private function hrManager(): User
    {
        $user = User::factory()->hrManager()->withTwoFactor()->create();
        Employee::factory()->withUser($user)->create();

        return $user;
    }

    // ---------------------------------------------------------------- the acceptance test

    /**
     * The whole point: the receipt is IN the downloaded document, not described in it.
     */
    public function test_a_pdf_the_parser_cannot_open_is_reproduced_from_its_rasterised_pages(): void
    {
        $pdfPath = $this->compressedXrefPdf();
        $claim = $this->approvedClaim([$pdfPath]);

        $withoutPreviews = $this->pageCount($this->render($claim));

        $this->storePreviews($pdfPath, 3, 3);
        $bytes = $this->render($claim);

        $this->assertSame(
            $withoutPreviews + 3,
            $this->pageCount($bytes),
            'The rasterised receipt pages were not appended to the report.'
        );

        $text = $this->inflatedText($bytes);
        $this->assertStringContainsString('Page image of the uploaded file', $text);
        $this->assertStringContainsString('Attachment for: Equipment Rental', $text);
    }

    /** End to end through the button HR actually presses, not just the renderer. */
    public function test_the_download_route_serves_the_reproduced_receipt(): void
    {
        $pdfPath = $this->compressedXrefPdf();
        $claim = $this->approvedClaim([$pdfPath]);
        $this->storePreviews($pdfPath, 2, 2);

        $response = $this->actingAs($this->hrManager())->get(route('user.claims.pdf', $claim));

        $response->assertStatus(200);
        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThanOrEqual(3, $this->pageCount($bytes), 'The download carries no appended receipt pages.');
    }

    // ---------------------------------------------------------------- honesty of the wording

    /**
     * None of the four failing production files is encrypted, so telling the employee to
     * remove a password wastes their time and hides a server-side limitation IT can act on.
     */
    public function test_the_reason_names_compression_rather_than_guessing_at_a_password(): void
    {
        $claim = $this->approvedClaim([$this->compressedXrefPdf()]);

        $entry = array_values(ClaimReportRenderer::appendix($claim->items))[0];

        $this->assertFalse($entry['appendable']);
        $this->assertSame('this PDF uses a compression format the server cannot open directly', $entry['reason']);
        $this->assertStringNotContainsStringIgnoringCase('password', (string) $entry['reason']);
    }

    /** A rasterised copy must not be described with the wording reserved for the original. */
    public function test_a_rasterised_reproduction_is_not_called_a_full_one(): void
    {
        $pdfPath = $this->compressedXrefPdf();
        $claim = $this->approvedClaim([$pdfPath]);
        $this->storePreviews($pdfPath, 2, 2);

        $entry = ClaimReportRenderer::appendix($claim->items)[$pdfPath];

        $this->assertTrue($entry['appendable']);
        $this->assertSame('pdf-raster', $entry['kind']);
        $this->assertStringNotContainsString('in full', $this->inflatedText($this->render($claim)));
    }

    /** A capture that stopped short must say so rather than imply the receipt is complete. */
    public function test_a_partial_capture_states_the_shortfall(): void
    {
        $pdfPath = $this->compressedXrefPdf();
        $claim = $this->approvedClaim([$pdfPath]);

        // Two pages captured out of a nine-page source.
        $this->storePreviews($pdfPath, 2, 9);

        $text = $this->inflatedText($this->render($claim));

        $this->assertStringContainsString('Only 2 of 9 pages', $text);
    }

    // ---------------------------------------------------------------- no regressions

    /**
     * Previews are written when somebody opens the claim, so a report pulled before that must
     * read exactly as it always did rather than claim a picture is missing.
     */
    public function test_a_pdf_with_no_previews_reads_exactly_as_it_did_before(): void
    {
        $claim = $this->approvedClaim([$this->compressedXrefPdf()]);

        $entry = array_values(ClaimReportRenderer::appendix($claim->items))[0];

        $this->assertFalse($entry['appendable']);
        $this->assertArrayNotHasKey('images', $entry);
        $this->assertStringStartsWith('%PDF', $this->render($claim));
    }

    /** The 115 that already worked must still be appended as the original vector document. */
    public function test_a_readable_pdf_is_still_appended_as_the_original_document(): void
    {
        $pdfPath = $this->readablePdf();
        $claim = $this->approvedClaim([$pdfPath]);

        $entry = ClaimReportRenderer::appendix($claim->items)[$pdfPath];

        $this->assertSame('pdf', $entry['kind']);
        $this->assertTrue($entry['appendable']);
        $this->assertStringContainsString('Reproduced in full from the uploaded file', $this->inflatedText($this->render($claim)));
    }

    // ---------------------------------------------------------------- the two caps

    /**
     * The row cap and the storage cap answer different questions. Collapsing them either
     * truncates the evidence or floods the item row with a statement's worth of pictures.
     */
    public function test_the_row_shows_the_row_cap_while_the_appendix_keeps_every_page(): void
    {
        config(['claims.pdf_preview.max_pages' => 2, 'claims.pdf_preview.store_max_pages' => 8]);

        $pdfPath = $this->compressedXrefPdf();
        $this->storePreviews($pdfPath, 6, 6);

        $this->assertCount(6, ClaimPdfPreview::existing($pdfPath), 'The appendix must see every stored page.');
        $this->assertCount(2, ClaimPdfPreview::forRow($pdfPath), 'The row must stop at the row cap.');
    }

    /** A file the parser can read needs images only as decoration; one it cannot needs them all. */
    public function test_the_raster_budget_follows_whether_the_parser_can_open_the_file(): void
    {
        config(['claims.pdf_preview.max_pages' => 3, 'claims.pdf_preview.store_max_pages' => 20]);

        $this->assertSame(3, ClaimReportRenderer::rasterBudgetFor($this->readablePdf('budget-ok')));
        $this->assertSame(20, ClaimReportRenderer::rasterBudgetFor($this->compressedXrefPdf('budget-bad')));
    }

    // ---------------------------------------------------------------- completion + cleanup

    /**
     * Without a recorded page count, a complete one-page receipt is re-rasterised on every
     * single visit, because one stored page is fewer than the budget.
     */
    public function test_a_fully_captured_pdf_is_not_rasterised_again(): void
    {
        $pdfPath = $this->compressedXrefPdf();

        $this->storePreviews($pdfPath, 1);
        $this->assertFalse(ClaimPdfPreview::isComplete($pdfPath, 20), 'An unknown page count cannot mean complete.');

        ClaimPdfPreview::recordTotal($pdfPath, 1);
        $this->assertTrue(ClaimPdfPreview::isComplete($pdfPath, 20));
    }

    /** Anything written must be removable, or a replaced receipt leaves stale pages behind. */
    public function test_forgetting_clears_pages_beyond_the_row_cap_and_the_page_count(): void
    {
        config(['claims.pdf_preview.max_pages' => 2, 'claims.pdf_preview.store_max_pages' => 8]);

        $pdfPath = $this->compressedXrefPdf();
        $this->storePreviews($pdfPath, 5, 5);

        ClaimPdfPreview::forget($pdfPath);

        Storage::disk('local')->assertMissing(ClaimPdfPreview::pathFor($pdfPath, 5));
        Storage::disk('local')->assertMissing(ClaimPdfPreview::totalPathFor($pdfPath));
        $this->assertSame([], ClaimPdfPreview::existing($pdfPath));
    }
}
