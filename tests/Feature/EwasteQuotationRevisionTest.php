<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Mail\EwasteQuotationApprovalMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\EwasteCompanyApprover;
use App\Models\User;
use App\Models\Vendor;
use App\Services\DecommissionReportRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

/**
 * Finance rejects a quotation, the vendor re-quotes, IT uploads the new document — and the
 * cycle must still be able to say what the first offer was and why it was refused.
 *
 * It could not before: the quotation lived in a single set of columns on the batch, so the
 * re-upload overwrote the document, the amount and the uploader, and deliberately nulled
 * finance_status / finance_reviewed_* / finance_remarks. The log then read as one quotation
 * approved first time — the rejection had never happened. Every assertion here pins the fact
 * that a re-quote ADDS a revision rather than replacing one.
 */
class EwasteQuotationRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
        Storage::fake('local');
    }

    /** A real, FPDI-parseable quotation document. */
    private function quote(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            Pdf::loadHTML('<html><body><h1>VENDOR QUOTATION '.e($name).'</h1></body></html>')->output()
        );
    }

    /** An e-waste cycle with one staged asset, waiting for its first quotation. */
    private function cycle(): AssetDecommissionBatch
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
            // A cycle is per company since Phase 4, and the company is what decides which
            // management approver may authorise it.
            'company' => 'Claritas Asia Sdn Bhd',
        ]);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'decommission_batch_id' => $batch->id]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        return $batch;
    }

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager', 'name' => 'Martha Janice']);
    }

    private function financeManager(): User
    {
        return User::factory()->create(['role' => 'finance_manager', 'name' => 'Priya Ramasamy', 'work_email' => 'fin@claritas.com']);
    }

    /** The one e-waste vendor these tests quote from — offers belong to a vendor since Phase 5. */
    private function vendor(): Vendor
    {
        return Vendor::firstOrCreate(
            ['name' => 'RecycleCo'],
            ['vendor_types' => ['ewaste'], 'pic_email' => 'ops@recycleco.com', 'is_active' => true]
        );
    }

    /** Amounts are typed, so resolveAmount() short-circuits and no OCR call is attempted. */
    private function upload(User $it, AssetDecommissionBatch $batch, string $file, float $amount): void
    {
        $this->actingAs($it)->post(route('ewaste.quotation', $batch), [
            'vendor_id' => $this->vendor()->id,
            'quotation_file' => $this->quote($file),
            'quotation_amount' => $amount,
        ])->assertRedirect();
    }

    /** IT put the offers up for review — nothing is decidable until they do (Phase 5). */
    private function submit(User $it, AssetDecommissionBatch $batch): void
    {
        $current = $batch->fresh()->quotationsForComparison()->first();

        $this->actingAs($it)->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $current->id,
        ])->assertRedirect();
    }

    private function managementApprover(): User
    {
        $user = User::factory()->create(['role' => 'employee', 'name' => 'Kelvin Approver']);
        EwasteCompanyApprover::create(['company' => 'Claritas Asia Sdn Bhd', 'user_id' => $user->id]);

        return $user;
    }

    /**
     * offer → refused (with reason) → revised offer → approved.
     *
     * Both parties act at each round since Phase 5: Finance record their position, management
     * make the decision that actually moves the cycle. The rejection reason the assertions
     * below care about is Finance's, recorded on the revision itself.
     */
    private function rejectedThenApproved(User $it, User $finance): AssetDecommissionBatch
    {
        $batch = $this->cycle();
        $mgmt = $this->managementApprover();

        $this->upload($it, $batch, 'first.pdf', 1000);
        $this->submit($it, $batch);
        $this->actingAs($finance)->post(route('finance.ewaste.reject', $batch), [
            'remarks' => 'Offer is below the market rate for 3 laptops.',
        ])->assertRedirect();
        // Management's rejection is what sends the cycle back for a revised offer.
        $this->actingAs($mgmt)->post(route('management.ewaste.reject', $batch), [
            'remarks' => 'Agreed — go back for a better price.',
        ])->assertRedirect();

        $this->upload($it, $batch, 'second.pdf', 1450);
        $this->submit($it, $batch);
        $this->actingAs($finance)->post(route('finance.ewaste.approve', $batch), [
            'remarks' => 'Revised offer accepted.',
        ])->assertRedirect();
        $this->actingAs($mgmt)->post(route('management.ewaste.approve', $batch))->assertRedirect();

        return $batch->fresh();
    }

    /** Page content streams are deflated, so caption text has to be inflated to be read. */
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

    private function pageCount(string $pdf): int
    {
        return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
    }

    public function test_a_requote_keeps_the_rejected_quotation_and_the_reason_it_was_refused(): void
    {
        $it = $this->itManager();
        $finance = $this->financeManager();

        $batch = $this->rejectedThenApproved($it, $finance);

        $revisions = $batch->quotations;
        $this->assertCount(2, $revisions, 'The re-quote replaced the first quotation instead of adding a revision.');

        [$first, $second] = [$revisions[0], $revisions[1]];

        // The refused offer survived the re-quote in full: document, amount, actor, reason.
        $this->assertSame(1, $first->revision);
        $this->assertSame('1000.00', $first->amount);
        $this->assertSame($it->id, $first->uploaded_by);
        $this->assertTrue($first->isRejected());
        $this->assertSame('Offer is below the market rate for 3 laptops.', $first->finance_remarks);
        $this->assertSame($finance->id, $first->finance_reviewed_by);
        $this->assertNotNull($first->finance_reviewed_at);

        $this->assertSame(2, $second->revision);
        $this->assertSame('1450.00', $second->amount);
        $this->assertTrue($second->isApproved());
        $this->assertSame('Revised offer accepted.', $second->finance_remarks);

        // Two distinct documents, both still on disk — neither upload overwrote the other.
        $this->assertNotSame($first->path, $second->path);
        $this->assertTrue(Storage::disk('local')->exists($first->path));
        $this->assertTrue(Storage::disk('local')->exists($second->path));

        // The batch columns cache the offer IN PLAY — the accepted one since Phase 5, which on
        // a single-vendor cycle is still the current revision.
        $this->assertSame($second->path, $batch->quotation_path);
        $this->assertSame('1450.00', $batch->quotation_amount);
        $this->assertSame('approved', $batch->finance_status);
        // `approved`, not `finance_approved`: management authorise a disposal, so a status
        // naming Finance as the decider would misstate who signed it off.
        $this->assertSame('approved', $batch->status);
        $this->assertSame('approved', $batch->management_status);
        $this->assertSame($second->id, $batch->selected_quotation_id);
    }

    public function test_the_cycle_log_shows_both_offers_with_the_rejection_between_them(): void
    {
        $it = $this->itManager();
        $batch = $this->rejectedThenApproved($it, $this->financeManager());

        $response = $this->actingAs($it)->get(route('decommission.show', $batch))->assertOk();

        // Both revisions, in order, each with its own Finance decision.
        $response->assertSee('Revision 1 of 2', false);
        $response->assertSee('Superseded', false);
        $response->assertSee('Rejected', false);
        $response->assertSee('Offer is below the market rate for 3 laptops.', false);
        $response->assertSee('Revision 2 of 2', false);
        $response->assertSee('Approved', false);
        $response->assertSee('RM 1,000.00', false);
        $response->assertSee('RM 1,450.00', false);

        // Both documents stay reachable from the log — one link per revision.
        $this->assertSame(2, substr_count($response->getContent(), 'view quote'));
    }

    /**
     * Before the re-upload, IT is told what was refused and why.
     *
     * The "this will be revision N" prediction went with the single-vendor form: the upload
     * form now asks WHICH vendor sent the document, so the next revision number is not known
     * until that is picked, and the comparison table above states each vendor's current
     * revision anyway. What still has to be on screen is the reason, which is what IT act on.
     */
    public function test_the_reupload_form_states_the_rejection_it_is_answering(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $mgmt = $this->managementApprover();

        $this->upload($it, $batch, 'first.pdf', 1000);
        $this->submit($it, $batch);
        // Finance's decision is now a mandatory, co-equal gate — either party rejecting closes
        // the cycle outright, so the other can no longer also weigh in on the same revision
        // (management.ewaste.reject would be refused once the cycle is no longer awaiting a
        // decision). Management's rejection alone is enough to demonstrate the reupload form
        // states the rejection it is answering.
        $this->actingAs($mgmt)->post(route('management.ewaste.reject', $batch), [
            'remarks' => 'Go back for a better price.',
        ])->assertRedirect();

        $this->actingAs($it)->get(route('decommission.show', $batch))->assertOk()
            ->assertSee('Management rejected this disposal', false)
            ->assertSee('Go back for a better price.', false);
    }

    public function test_the_report_lists_every_revision_and_reproduces_both_documents(): void
    {
        $it = $this->itManager();
        $batch = $this->rejectedThenApproved($it, $this->financeManager())->fresh(['vendor', 'items.asset']);

        $appendix = DecommissionReportRenderer::appendix($batch);

        // Both quotations are appended, oldest first; the current one keeps the plain key.
        $this->assertSame(['quotation_rev1', 'quotation'], array_keys($appendix));
        $this->assertSame('Quotation (revision 1 of 2)', $appendix['quotation_rev1']['label']);
        $this->assertTrue($appendix['quotation_rev1']['appendable']);
        $this->assertTrue($appendix['quotation']['appendable']);

        // The body carries the decision trail: both offers, both outcomes, the reason.
        $html = view('decommission.report-pdf', ['batch' => $batch, 'appendix' => $appendix])->render();
        $this->assertStringContainsString('Quotations Received', $html);
        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringContainsString('1,450.00', $html);
        $this->assertStringContainsString('Offer is below the market rate for 3 laptops.', $html);

        $body = Pdf::loadView('decommission.report-pdf', ['batch' => $batch, 'appendix' => $appendix])->setPaper('a4')->output();
        $report = DecommissionReportRenderer::render($batch);

        // One appended page per quotation — the rejected document is IN the report, not just named.
        $this->assertSame($this->pageCount($body) + 2, $this->pageCount($report));

        // And its caption says which revision it is and what Finance did with it. Matched
        // without the brackets: a PDF string literal escapes them, so the stream carries
        // `Quotation \(revision 1 of 2\)`.
        $captions = $this->inflatedText($report);
        $this->assertStringContainsString('revision 1 of 2', $captions);
        $this->assertStringContainsString('revision 2 of 2', $captions);
        $this->assertStringContainsString('Rejected by Finance', $captions);
    }

    /** Correcting the live figure must not rewrite what Finance actually rejected. */
    public function test_correcting_the_amount_only_touches_the_current_revision(): void
    {
        $it = $this->itManager();
        $batch = $this->rejectedThenApproved($it, $this->financeManager());
        [$first, $second] = [$batch->quotations[0], $batch->quotations[1]];

        $this->actingAs($it)->post(route('ewaste.amount', $batch), ['field' => 'quotation', 'amount' => 1500])
            ->assertRedirect();

        $this->assertSame('1500.00', $second->fresh()->amount);
        $this->assertSame('1500.00', $batch->fresh()->quotation_amount);
        $this->assertSame('1000.00', $first->fresh()->amount, 'A superseded revision was rewritten.');
    }

    /** Finance is told the offer in front of them is a revised one, and why the first failed. */
    public function test_finance_is_told_the_quotation_is_a_revision_of_a_rejected_one(): void
    {
        $it = $this->itManager();
        $finance = $this->financeManager();
        $batch = $this->cycle();

        $mgmt = $this->managementApprover();

        // The ask goes out when IT SUBMIT the comparison, not on every upload — several
        // vendors are being collected first.
        $this->upload($it, $batch, 'first.pdf', 1000);
        $this->submit($it, $batch);
        $this->actingAs($finance)->post(route('finance.ewaste.reject', $batch), ['remarks' => 'Below market rate.'])->assertRedirect();
        $this->actingAs($mgmt)->post(route('management.ewaste.reject', $batch), ['remarks' => 'Re-quote.'])->assertRedirect();
        $this->upload($it, $batch, 'second.pdf', 1450);
        $this->submit($it, $batch);

        Mail::assertSent(EwasteQuotationApprovalMail::class, function ($mail) {
            $html = $mail->render();

            return str_contains($html, 'revised quotation')
                && str_contains($html, 'Revision 1 was rejected')
                && str_contains($html, 'Below market rate.');
        });
    }

    /**
     * Finance's own approval row says which revision it is looking at and repeats the reason
     * it gave last time — approving a revised offer blind to your own earlier objection is
     * how a rejected price gets waved through on the second pass.
     */
    public function test_the_finance_approval_row_names_the_revision_and_the_earlier_rejection(): void
    {
        $it = $this->itManager();
        $finance = $this->financeManager();
        $batch = $this->cycle();

        $mgmt = $this->managementApprover();

        $this->upload($it, $batch, 'first.pdf', 1000);
        $this->submit($it, $batch);
        $this->actingAs($finance)->post(route('finance.ewaste.reject', $batch), [
            'remarks' => 'Below market rate for 3 laptops.',
        ])->assertRedirect();
        $this->actingAs($mgmt)->post(route('management.ewaste.reject', $batch), ['remarks' => 'Re-quote.'])->assertRedirect();
        $this->upload($it, $batch, 'second.pdf', 1450);
        $this->submit($it, $batch);

        // Management → Decommissioning since 2026-08-14 — Finance's review moved off
        // Accounting → Assets → "Disposed" onto the single e-waste review surface.
        $this->actingAs($finance)->get(route('reports.decommission'))
            ->assertOk()
            ->assertSee('Revision 2', false)
            ->assertSee('you rejected revision 1', false)
            ->assertSee('Below market rate for 3 laptops.', false);
    }

    /** A cycle that went through first time reads exactly as it always did — no revision noise. */
    public function test_a_single_quotation_cycle_shows_no_revision_numbering(): void
    {
        $it = $this->itManager();
        $this->financeManager();
        $batch = $this->cycle();

        $this->upload($it, $batch, 'only.pdf', 900);

        $this->actingAs($it)->get(route('decommission.show', $batch))->assertOk()
            ->assertSee('Quotation uploaded', false)
            ->assertSee('RM 900.00', false)
            ->assertDontSee('Revision', false)
            ->assertDontSee('Superseded', false);

        $appendix = DecommissionReportRenderer::appendix($batch->fresh(['vendor', 'items.asset']));
        $this->assertSame(['quotation'], array_keys($appendix));
        $this->assertSame('Quotation', $appendix['quotation']['label']);

        // No revision table in the body either — there is nothing to compare.
        $this->assertStringNotContainsString(
            'Quotation Revisions',
            view('decommission.report-pdf', ['batch' => $batch->fresh(['vendor', 'items.asset']), 'appendix' => $appendix])->render()
        );
    }

    /**
     * A cycle whose quotation predates the revision table has no rows at all — its document
     * and decision live only in the batch's columns, and both screens must still show them.
     * The migration backfills these, but nothing may depend on the backfill having run.
     */
    public function test_a_legacy_cycle_with_no_revision_rows_still_shows_its_quotation(): void
    {
        Storage::disk('local')->put('ewaste_quotations/legacy.pdf', Pdf::loadHTML('<h1>LEGACY QUOTE</h1>')->output());

        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q2', 'type' => 'e_waste', 'status' => 'finance_approved',
            'quotation_path' => 'ewaste_quotations/legacy.pdf', 'quotation_uploaded_at' => now(),
            'quotation_amount' => 800, 'finance_status' => 'approved', 'finance_reviewed_at' => now(),
        ]);

        $this->assertCount(0, $batch->quotations);

        $this->actingAs($this->itManager())->get(route('decommission.show', $batch))->assertOk()
            ->assertSee('RM 800.00', false)
            ->assertSee('Approved', false)
            ->assertDontSee('Revision', false);

        $appendix = DecommissionReportRenderer::appendix($batch->fresh());
        $this->assertSame(['quotation'], array_keys($appendix));
        $this->assertTrue($appendix['quotation']['appendable']);
    }
}
