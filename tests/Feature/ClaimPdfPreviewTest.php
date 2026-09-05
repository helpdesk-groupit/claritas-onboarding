<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\User;
use App\Support\ClaimPdfPreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Inline pictures for PDF claim receipts.
 *
 * dompdf can embed a JPG/PNG receipt inline but can never embed another PDF, so in the claim
 * form a PDF attachment printed a sentence where every other line showed a picture. The pages
 * themselves were never missing — a real production export (EC-2026-08-0061) downloads as 20
 * pages, 18 of them receipt, appended by ClaimReportRenderer — but an approver reading the
 * item rows saw prose and read it as the receipt having been dropped.
 *
 * The rasterising happens in the browser (pdf.js, already vendored same-origin for the receipt
 * scanner) because this host has no Imagick, Ghostscript or Poppler. That means the image
 * arrives from the CLIENT, which is what most of these tests are about.
 */
class ClaimPdfPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Faked, not merely unique-keyed: these tests write preview files whose names are
        // DERIVED from the source path, so without isolation one test's page 1 satisfies the
        // next test's "already exists" short-circuit — and the run would also litter the real
        // dev storage/app/private with receipts.
        Storage::fake('local');
    }

    private function owner(): Employee
    {
        return Employee::factory()->create([
            'company' => 'Enlinea Sdn. Bhd.', 'full_name' => 'Alice Owner',
        ]);
    }

    private function claimWithPdf(Employee $owner, string $pdfPath): ExpenseClaim
    {
        $claim = ExpenseClaim::create([
            'employee_id' => $owner->id, 'year' => 2026, 'month' => 6,
            'claim_number' => 'EC-2026-06-'.random_int(1000, 9999),
            'title' => 'x', 'event' => 'AMD Editorial', 'status' => 'draft',
            'company' => 'Enlinea Sdn. Bhd.',
        ]);

        $cat = ExpenseCategory::create([
            'name' => 'Medical Fees', 'code' => 'C-'.uniqid(),
            'gl_code' => '932-000', 'rate_type' => 'receipt', 'is_active' => true,
        ]);

        $claim->items()->create([
            'expense_category_id' => $cat->id,
            'expense_date' => '2026-06-01',
            'description' => 'Consultation',
            'amount' => 50, 'total_with_gst' => 50,
            'receipt_path' => $pdfPath,
        ]);

        return $claim;
    }

    /** A file on the private disk that is a PDF as far as everything here is concerned. */
    private function storedPdf(string $key = 'r1'): string
    {
        $path = 'claim_receipts/test/'.$key.'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n%stub\n");

        return $path;
    }

    /** A real JPEG, as the browser's canvas.toBlob() would post. */
    private function pageJpeg(): UploadedFile
    {
        $im = imagecreatetruecolor(120, 160);
        imagefilledrectangle($im, 0, 0, 120, 160, imagecolorallocate($im, 240, 240, 240));
        ob_start();
        imagejpeg($im, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return UploadedFile::fake()->createWithContent('page.jpg', $bytes);
    }

    private function userFor(Employee $employee): User
    {
        $user = User::factory()->withTwoFactor()->create();
        $employee->update(['user_id' => $user->id]);

        return $user->fresh();
    }

    // ── the path convention ────────────────────────────────────────────────────────

    public function test_previews_stop_at_the_first_missing_page(): void
    {
        $pdf = $this->storedPdf();
        Storage::disk('local')->put(ClaimPdfPreview::pathFor($pdf, 1), 'a');
        // page 2 deliberately absent
        Storage::disk('local')->put(ClaimPdfPreview::pathFor($pdf, 3), 'c');

        // Pages 1 and 3 shown as though consecutive would misrepresent the document, so the
        // run stops at the gap rather than reporting what happens to exist.
        $this->assertSame([ClaimPdfPreview::pathFor($pdf, 1)], ClaimPdfPreview::existing($pdf));
    }

    public function test_a_preview_name_cannot_collide_with_a_real_upload(): void
    {
        // Laravel's ->store() writes a random 40-char basename plus ONE extension, so nothing
        // it produces can end in the suffix this appends.
        $this->assertStringEndsWith('.pdf.p1.jpg', ClaimPdfPreview::pathFor('claim_receipts/9/2026-08/abc.pdf', 1));
        $this->assertSame([], ClaimPdfPreview::existing('claim_receipts/9/2026-08/abc.png'));
    }

    // ── the endpoint ───────────────────────────────────────────────────────────────

    public function test_the_owner_can_store_a_rendered_page(): void
    {
        $owner = $this->owner();
        $pdf = $this->storedPdf();
        $this->claimWithPdf($owner, $pdf);

        $this->actingAs($this->userFor($owner))
            ->post(route('user.claims.receipt-preview'), [
                'path' => $pdf, 'page' => 1, 'image' => $this->pageJpeg(),
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'stored' => true]);

        Storage::disk('local')->assertExists(ClaimPdfPreview::pathFor($pdf, 1));
    }

    public function test_a_path_no_claim_item_cites_is_refused(): void
    {
        $owner = $this->owner();
        $this->claimWithPdf($owner, $this->storedPdf('cited'));

        // The path decides which file gets written, so it is resolved to a claim item rather
        // than trusted. Without this the endpoint writes a chosen JPEG anywhere on the private
        // disk whose name ends ".pdf" — employee_contracts included.
        $orphan = 'employee_contracts/secret.pdf';
        Storage::disk('local')->put($orphan, '%PDF-1.4');

        $this->actingAs($this->userFor($owner))
            ->post(route('user.claims.receipt-preview'), [
                'path' => $orphan, 'page' => 1, 'image' => $this->pageJpeg(),
            ])
            ->assertNotFound();

        Storage::disk('local')->assertMissing(ClaimPdfPreview::pathFor($orphan, 1));
    }

    public function test_an_unrelated_employee_cannot_write_a_preview(): void
    {
        $owner = $this->owner();
        $pdf = $this->storedPdf();
        $this->claimWithPdf($owner, $pdf);

        $stranger = Employee::factory()->create(['company' => 'Enlinea Sdn. Bhd.']);

        $this->actingAs($this->userFor($stranger))
            ->post(route('user.claims.receipt-preview'), [
                'path' => $pdf, 'page' => 1, 'image' => $this->pageJpeg(),
            ])
            ->assertForbidden();

        Storage::disk('local')->assertMissing(ClaimPdfPreview::pathFor($pdf, 1));
    }

    public function test_a_body_that_is_not_a_readable_image_is_refused(): void
    {
        $owner = $this->owner();
        $pdf = $this->storedPdf();
        $this->claimWithPdf($owner, $pdf);

        // A .jpg extension proves nothing. Two layers can refuse this — the `mimes` rule on the
        // way in, and imagecreatefromstring() decoding it with the renderer rather than trusting
        // the header (the caution DecommissionReportRenderer documents for FPDF::Image()). The
        // assertion is deliberately on the OUTCOME rather than a status code, so it keeps
        // holding whichever layer catches it: garbage must never reach the disk.
        $response = $this->actingAs($this->userFor($owner))
            ->post(route('user.claims.receipt-preview'), [
                'path' => $pdf, 'page' => 1,
                'image' => UploadedFile::fake()->createWithContent('page.jpg', 'not an image at all'),
            ]);

        $this->assertNotSame(200, $response->getStatusCode(), 'A non-image body was accepted.');
        Storage::disk('local')->assertMissing(ClaimPdfPreview::pathFor($pdf, 1));
    }

    /**
     * The endpoint is bounded by the STORAGE cap, which is deliberately larger than the row
     * cap: a PDF the parser cannot open is captured in full because those images are then the
     * only copy that reaches the download. Beyond storage there is nothing left to keep.
     */
    public function test_a_page_beyond_the_storage_cap_is_refused(): void
    {
        $owner = $this->owner();
        $pdf = $this->storedPdf();
        $this->claimWithPdf($owner, $pdf);

        $this->actingAs($this->userFor($owner))
            ->post(route('user.claims.receipt-preview'), [
                'path' => $pdf, 'page' => ClaimPdfPreview::storeLimit() + 1, 'image' => $this->pageJpeg(),
            ])
            ->assertStatus(302); // validation bounce
    }

    /**
     * The page that used to be refused. Pinned from the other side because this is exactly
     * where the receipt was being lost: stopping at the row cap truncated the evidence of an
     * approved claim to its first few pages.
     */
    public function test_a_page_past_the_row_cap_is_still_stored(): void
    {
        $owner = $this->owner();
        $pdf = $this->storedPdf();
        $this->claimWithPdf($owner, $pdf);

        $page = ClaimPdfPreview::maxPages() + 1;
        $this->assertLessThanOrEqual(ClaimPdfPreview::storeLimit(), $page, 'The caps must differ for this to mean anything.');

        $this->actingAs($this->userFor($owner))
            ->post(route('user.claims.receipt-preview'), [
                'path' => $pdf, 'page' => $page, 'image' => $this->pageJpeg(),
            ])
            ->assertOk();

        Storage::disk('local')->assertExists(ClaimPdfPreview::pathFor($pdf, $page));
    }

    public function test_regenerating_an_existing_page_does_not_rewrite_it(): void
    {
        $owner = $this->owner();
        $pdf = $this->storedPdf();
        $this->claimWithPdf($owner, $pdf);

        Storage::disk('local')->put(ClaimPdfPreview::pathFor($pdf, 1), 'ORIGINAL');

        // The client regenerates on every view of a claim whose previews are incomplete, so
        // this is the common case rather than an error.
        $this->actingAs($this->userFor($owner))
            ->post(route('user.claims.receipt-preview'), [
                'path' => $pdf, 'page' => 1, 'image' => $this->pageJpeg(),
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'stored' => false]);

        $this->assertSame('ORIGINAL', Storage::disk('local')->get(ClaimPdfPreview::pathFor($pdf, 1)));
    }

    // ── what the downloaded report says ────────────────────────────────────────────

    public function test_the_report_shows_the_rendered_pages_instead_of_the_not_embeddable_note(): void
    {
        $pdf = 'claim_receipts/test/inline.pdf';
        Storage::disk('local')->put($pdf, '%PDF-1.4');
        Storage::disk('local')->put(ClaimPdfPreview::pathFor($pdf, 1), $this->jpegBytes());

        $html = view('user.claims._attachment-cell', [
            'path' => $pdf,
            'label' => 'Attachment',
            'maxHeight' => 420,
            'imageExt' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'appendix' => [$pdf => ['appendable' => true, 'reason' => null]],
            'imgData' => fn ($disk, $p) => Storage::disk($disk)->exists($p) ? 'data:image/jpeg;base64,AAAA' : null,
        ])->render();

        $this->assertStringContainsString('data:image/jpeg;base64,AAAA', $html);
        $this->assertStringContainsString('1 page shown above', $html);
        $this->assertStringNotContainsString('not embeddable in this PDF', $html);
        // The appended pages remain the record, so the row must still point at them.
        $this->assertStringContainsString('reproduced in full on the pages after this form', $html);
    }

    public function test_a_pdf_with_no_previews_reads_exactly_as_it_did_before(): void
    {
        $pdf = 'claim_receipts/test/bare.pdf';
        Storage::disk('local')->put($pdf, '%PDF-1.4');

        // Previews are written when someone opens the claim, so a report downloaded before
        // that must still read as it always did rather than claim a picture is missing.
        $html = view('user.claims._attachment-cell', [
            'path' => $pdf,
            'label' => 'Attachment',
            'maxHeight' => 420,
            'imageExt' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'appendix' => [$pdf => ['appendable' => true, 'reason' => null]],
            'imgData' => fn ($disk, $p) => null,
        ])->render();

        $this->assertStringContainsString('Attachment: PDF file (not embeddable in this PDF)', $html);
        $this->assertStringContainsString('reproduced in full on the pages after this form', $html);
    }

    public function test_an_image_attachment_is_untouched_by_any_of_this(): void
    {
        $img = 'claim_receipts/test/photo.jpg';
        Storage::disk('local')->put($img, $this->jpegBytes());

        $html = view('user.claims._attachment-cell', [
            'path' => $img,
            'label' => 'Attachment',
            'maxHeight' => 420,
            'imageExt' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'appendix' => [],
            'imgData' => fn ($disk, $p) => 'data:image/jpeg;base64,ZZZZ',
        ])->render();

        $this->assertStringContainsString('data:image/jpeg;base64,ZZZZ', $html);
        $this->assertStringNotContainsString('shown above', $html);
    }

    // ── lifecycle ──────────────────────────────────────────────────────────────────

    public function test_previews_are_dropped_with_the_document_they_depict(): void
    {
        $pdf = $this->storedPdf();
        Storage::disk('local')->put(ClaimPdfPreview::pathFor($pdf, 1), 'a');
        Storage::disk('local')->put(ClaimPdfPreview::pathFor($pdf, 2), 'b');

        ClaimPdfPreview::forget($pdf);

        // A preview outliving its source is a picture of a receipt nothing can trace back to a
        // claim — worse than no preview, because it still renders.
        Storage::disk('local')->assertMissing(ClaimPdfPreview::pathFor($pdf, 1));
        Storage::disk('local')->assertMissing(ClaimPdfPreview::pathFor($pdf, 2));
    }

    private function jpegBytes(): string
    {
        $im = imagecreatetruecolor(40, 40);
        ob_start();
        imagejpeg($im, null, 70);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }
}
