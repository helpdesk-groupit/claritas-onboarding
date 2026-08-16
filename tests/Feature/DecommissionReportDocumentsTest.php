<?php

namespace Tests\Feature;

use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\Employee;
use App\Models\User;
use App\Services\DecommissionReportRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

/**
 * The finance evidence — the vendor's quotation and the payment receipt — has to be
 * SHOWN in the decommissioning report, not merely named. dompdf can rasterise an image
 * but can never embed another PDF, and every real vendor document arrives as a PDF, so
 * the pages are merged in after dompdf by DecommissionReportRenderer.
 */
class DecommissionReportDocumentsTest extends TestCase
{
    use RefreshDatabase;

    /** A real, parseable PDF carrying $text — generated the same way the app makes PDFs. */
    private function samplePdf(string $text): string
    {
        return Pdf::loadHTML('<html><body><h1>'.e($text).'</h1></body></html>')->setPaper('a4')->output();
    }

    private function pageCount(string $pdf): int
    {
        return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
    }

    /** Page content streams are deflated, so caption text has to be inflated to be seen. */
    private function inflatedText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $m);
        $out = '';
        foreach ($m[1] as $stream) {
            $data = @gzuncompress($stream) ?: @gzinflate($stream);
            if ($data !== false) {
                $out .= $data."\n";
            }
        }

        return $out;
    }

    private function ewasteBatch(array $attributes = []): AssetDecommissionBatch
    {
        $batch = AssetDecommissionBatch::create(array_merge([
            'batch_number' => 'EWA-2026-Q4',
            'type' => 'e_waste',
            'status' => 'completed',
        ], $attributes));

        $asset = AssetInventory::factory()->create([
            'asset_condition' => 'not_good',
            'decommission_batch_id' => $batch->id,
        ]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        return $batch->fresh(['vendor', 'items.asset']);
    }

    public function test_uploaded_quotation_and_receipt_pdfs_are_appended_as_real_pages(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ewaste_quotations/q.pdf', $this->samplePdf('VENDOR QUOTATION 4321'));
        Storage::disk('local')->put('ewaste_receipts/r.pdf', $this->samplePdf('PAYMENT RECEIPT 4321'));

        $batch = $this->ewasteBatch([
            'quotation_path' => 'ewaste_quotations/q.pdf',
            'receipt_path' => 'ewaste_receipts/r.pdf',
        ]);

        $body = Pdf::loadView('decommission.report-pdf', ['batch' => $batch])->setPaper('a4')->output();
        $report = DecommissionReportRenderer::render($batch);

        // One appended page per source page — the documents are in the file, not just referenced.
        $this->assertSame(
            $this->pageCount($body) + 2,
            $this->pageCount($report),
            'The quotation and receipt pages were not appended to the report.'
        );

        // And each appended page is captioned so it is unmistakably identified.
        // The dash is CP1252 0x97: FPDF core fonts declare /WinAnsiEncoding, so the em-dash
        // survives. Targeting ISO-8859-1 instead would silently degrade it to a hyphen.
        $captions = $this->inflatedText($report);
        $this->assertStringContainsString("EWA-2026-Q4 \x97 Quotation", $captions);
        $this->assertStringContainsString("EWA-2026-Q4 \x97 Payment Receipt", $captions);
        $this->assertStringContainsString('Reproduced in full from the uploaded file', $captions);
    }

    /**
     * The body carries no Quotation / Payment Receipt section any more — both documents
     * are reproduced as captioned pages, so a summary block would only restate them.
     */
    public function test_report_body_has_no_quotation_or_receipt_section(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ewaste_quotations/q.pdf', $this->samplePdf('VENDOR QUOTATION'));
        Storage::disk('local')->put('ewaste_receipts/r.pdf', $this->samplePdf('PAYMENT RECEIPT'));

        $batch = $this->ewasteBatch([
            'quotation_path' => 'ewaste_quotations/q.pdf',
            'receipt_path' => 'ewaste_receipts/r.pdf',
            'quotation_amount' => 350,
            'receipt_amount' => 350,
        ]);

        $html = view('decommission.report-pdf', ['batch' => $batch])->render();

        $this->assertStringNotContainsString("Quotation (vendor's offer", $html);
        $this->assertStringNotContainsString('Payment Receipt (proof of payment', $html);
        $this->assertStringNotContainsString('Amount received', $html);
        // The dead-end wording the appended pages replaced must not come back either.
        $this->assertStringNotContainsString('non-image, not embedded', $html);

        // The sign-offs are system actions with no document to attach — they stay. The section
        // is "Authorisation" since Phase 5, because it now carries BOTH management's decision
        // (which authorises the disposal) and Finance's position beside it.
        $this->assertStringContainsString('Authorisation', $html);
    }

    /**
     * A LEGACY Finance approval (pre-2026-08-16, when Finance's position doubled as a
     * verdict) still names the reviewer, their role, and when — the report states what
     * actually happened on that cycle rather than rewriting history to fit the current rule.
     * Nothing produces a new 'approved' finance_status any more; this is built directly.
     */
    public function test_a_legacy_finance_approval_names_the_approver_with_details_and_timestamp(): void
    {
        Storage::fake('local');

        $approver = User::factory()->create(['role' => 'finance_manager', 'name' => 'Fallback Name']);
        Employee::factory()->create([
            'user_id' => $approver->id,
            'full_name' => 'Priya Ramasamy',
            'designation' => 'Finance Manager',
            'department' => 'Finance',
        ]);

        $batch = $this->ewasteBatch([
            'finance_status' => 'approved',
            'finance_reviewed_by' => $approver->id,
            'finance_reviewed_at' => now(),
            'finance_remarks' => 'Offer is in line with market rates.',
        ]);

        $html = view('decommission.report-pdf', ['batch' => $batch->load('financeReviewer.employee')])->render();

        $this->assertStringContainsString('Finance approved (legacy)', $html);
        $this->assertStringContainsString('Priya Ramasamy', $html);
        $this->assertStringContainsString('Finance Manager', $html);
        $this->assertStringContainsString(fmt_datetime($batch->finance_reviewed_at), $html);
        $this->assertStringContainsString('Offer is in line with market rates.', $html);
    }

    /**
     * A CURRENT Finance review (remarks only, since 2026-08-16) prints the remarks under a
     * neutral "Finance Remarks" heading — no verdict language, because there is no verdict.
     */
    public function test_a_current_finance_remark_prints_under_a_neutral_heading(): void
    {
        Storage::fake('local');

        $reviewer = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->ewasteBatch([
            'finance_status' => 'noted',
            'finance_reviewed_by' => $reviewer->id,
            'finance_reviewed_at' => now(),
            'finance_remarks' => 'Seems reasonable for this volume.',
        ]);

        $html = view('decommission.report-pdf', ['batch' => $batch->load('financeReviewer.employee')])->render();

        $this->assertStringContainsString('Finance Remarks', $html);
        $this->assertStringContainsString('Seems reasonable for this volume.', $html);
        $this->assertStringNotContainsString('Finance approved', $html);
        $this->assertStringNotContainsString('Finance objected', $html);
    }

    /** No remarks at all is stated plainly — never rendered as a blank or a missing decision. */
    public function test_no_finance_remarks_reads_as_optional_not_missing(): void
    {
        Storage::fake('local');

        $batch = $this->ewasteBatch();

        $html = view('decommission.report-pdf', ['batch' => $batch])->render();

        $this->assertStringContainsString('No remarks left by Finance', $html);
        $this->assertStringContainsString('optional and advisory only', $html);
    }

    /** Each appended document is accountable: when it was uploaded and by whom. */
    public function test_appended_pages_carry_the_upload_timestamp_and_uploader(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ewaste_quotations/q.pdf', $this->samplePdf('VENDOR QUOTATION'));

        $uploader = User::factory()->create(['role' => 'it_manager', 'name' => 'Fallback Name']);
        Employee::factory()->create([
            'user_id' => $uploader->id,
            'full_name' => 'Daniel Tan',
            'designation' => 'IT Manager',
            'department' => 'Group IT',
        ]);

        $uploadedAt = now()->subDay();
        $batch = $this->ewasteBatch([
            'quotation_path' => 'ewaste_quotations/q.pdf',
            'quotation_uploaded_at' => $uploadedAt,
            'quotation_uploaded_by' => $uploader->id,
        ]);

        $captions = $this->inflatedText(DecommissionReportRenderer::render($batch));

        $this->assertStringContainsString('Uploaded '.fmt_datetime($uploadedAt).' by Daniel Tan', $captions);
        $this->assertStringContainsString('IT Manager', $captions);
    }

    /**
     * The uploader was never captured on batches predating the column. Those pages state
     * the timestamp alone — inventing a name would fabricate an audit trail.
     */
    public function test_a_legacy_upload_with_no_recorded_actor_says_so(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ewaste_quotations/q.pdf', $this->samplePdf('VENDOR QUOTATION'));

        $batch = $this->ewasteBatch([
            'quotation_path' => 'ewaste_quotations/q.pdf',
            'quotation_uploaded_at' => now(),
            'quotation_uploaded_by' => null,
        ]);

        $this->assertStringContainsString(
            'uploader not recorded',
            $this->inflatedText(DecommissionReportRenderer::render($batch))
        );
    }

    /**
     * A document we cannot reproduce (encrypted, or gone from disk) must SAY so.
     * Silently omitting it would leave the reader believing there was no evidence.
     */
    public function test_an_unreadable_document_is_stated_not_silently_dropped(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ewaste_quotations/broken.pdf', 'this is not a pdf at all');

        $batch = $this->ewasteBatch([
            'quotation_path' => 'ewaste_quotations/broken.pdf',
            'receipt_path' => 'ewaste_receipts/vanished.pdf',
        ]);

        $appendix = DecommissionReportRenderer::appendix($batch);
        $this->assertFalse($appendix['quotation']['appendable']);
        $this->assertFalse($appendix['receipt']['appendable']);
        $this->assertSame('the file is no longer on record', $appendix['receipt']['reason']);

        $html = view('decommission.report-pdf', ['batch' => $batch, 'appendix' => $appendix])->render();
        $this->assertStringContainsString('Documents Not Reproduced', $html);
        $this->assertStringContainsString('the file is no longer on record', $html);

        // A broken upload must never cost the whole report.
        $this->assertSame(
            $this->pageCount(Pdf::loadView('decommission.report-pdf', ['batch' => $batch, 'appendix' => $appendix])->setPaper('a4')->output()),
            $this->pageCount(DecommissionReportRenderer::render($batch))
        );
    }

    /**
     * A photographed or scanned quote is an image, and the body no longer has an inline
     * section to preview it in — so it gets a captioned page like a PDF upload does.
     */
    public function test_image_uploads_are_appended_as_a_captioned_page(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ewaste_quotations/q.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $batch = $this->ewasteBatch([
            'quotation_path' => 'ewaste_quotations/q.png',
            'quotation_uploaded_at' => now(),
        ]);

        $appendix = DecommissionReportRenderer::appendix($batch);
        $this->assertSame('image', $appendix['quotation']['kind']);
        $this->assertTrue($appendix['quotation']['appendable']);

        $body = Pdf::loadView('decommission.report-pdf', ['batch' => $batch, 'appendix' => $appendix])->setPaper('a4')->output();
        $report = DecommissionReportRenderer::render($batch);

        $this->assertSame($this->pageCount($body) + 1, $this->pageCount($report));
        $this->assertStringContainsString('EWA-2026-Q4', $this->inflatedText($report));
    }

    /**
     * Regression: this exact 1x1 PNG is corrupt past its header. FPDF parses PNG chunks by
     * hand, read its trailing bytes as a chunk length, and tried to allocate ~2 GB — a
     * memory-exhaustion FATAL that no try/catch can trap, killing report generation for the
     * whole cycle. Images are validated with GD (which rejects it) and re-encoded through GD
     * before FPDF ever sees them, so a bad upload degrades to a stated reason instead.
     */
    public function test_a_corrupt_image_upload_cannot_crash_report_generation(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ewaste_quotations/bad.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        $batch = $this->ewasteBatch([
            'quotation_path' => 'ewaste_quotations/bad.png',
            'quotation_uploaded_at' => now(),
        ]);

        $appendix = DecommissionReportRenderer::appendix($batch);
        $this->assertFalse($appendix['quotation']['appendable']);
        $this->assertSame('the image file is corrupt or unreadable', $appendix['quotation']['reason']);

        // The report still renders, and says why the document isn't in it.
        $report = DecommissionReportRenderer::render($batch);
        $this->assertGreaterThan(0, $this->pageCount($report));
        $this->assertStringContainsString(
            'Documents Not Reproduced',
            view('decommission.report-pdf', ['batch' => $batch, 'appendix' => $appendix])->render()
        );
    }

    /** A vendor return has no quotation/receipt cycle at all. */
    public function test_vendor_return_reports_have_no_appendix(): void
    {
        Storage::fake('local');

        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'RET-2026-0009', 'type' => 'vendor_return', 'status' => 'acknowledged',
        ]);

        $this->assertSame([], DecommissionReportRenderer::appendix($batch->fresh()));
    }
}
