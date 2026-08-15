<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Jobs\SummariseVendorDocument;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A quotation IT files on a disposal cycle is also a document that vendor sent us, so it is
 * copied onto their Contracts tab automatically — the same PDF is never uploaded twice.
 *
 * The filed row is a RECORD, not a contract: no term, its figure follows the cycle, its state
 * is derived from the cycle live, and it cannot be edited or deleted from the vendor profile.
 */
class EwasteQuotationFilingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
        Storage::fake('local');
        // The reading is queued (nobody is watching a modal here), so it is faked rather than
        // run — these tests are about the filing, not the summariser.
        Queue::fake();
    }

    private function quote(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            Pdf::loadHTML('<html><body><h1>QUOTATION '.e($name).'</h1></body></html>')->output()
        );
    }

    private function cycle(): AssetDecommissionBatch
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
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
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function vendor(string $name = 'RecycleCo'): Vendor
    {
        return Vendor::firstOrCreate(
            ['name' => $name],
            ['vendor_types' => ['ewaste'], 'pic_email' => 'ops@recycleco.com', 'is_active' => true]
        );
    }

    private function upload(User $it, AssetDecommissionBatch $batch, Vendor $vendor, string $file, ?float $amount = 900): void
    {
        $this->actingAs($it)->post(route('ewaste.quotation', $batch), [
            'vendor_id' => $vendor->id,
            'quotation_file' => $this->quote($file),
            'quotation_amount' => $amount,
        ])->assertRedirect();
    }

    // ── Filing ───────────────────────────────────────────────────────────────
    public function test_uploading_a_quotation_files_a_copy_on_the_vendors_contracts_tab(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();

        $this->upload($it, $batch, $vendor, 'recycleco-offer.pdf');

        $contract = VendorContract::where('vendor_id', $vendor->id)->firstOrFail();
        $revision = $batch->fresh()->quotations()->first();

        $this->assertSame(VendorContract::TYPE_EWASTE_QUOTATION, $contract->contract_type);
        $this->assertSame($revision->id, $contract->asset_decommission_quotation_id);
        $this->assertSame('EWA-2026-Q3', $contract->contract_reference);
        $this->assertSame('900.00', (string) $contract->contract_value);
        // The vendor's own filename, not the random storage name.
        $this->assertSame('recycleco-offer.pdf', $contract->original_filename);
        // No term: a scrap offer is not an agreement with a start and an end.
        $this->assertNull($contract->start_date);
        $this->assertNull($contract->end_date);
        // Both counterparties are known facts here, not a reading.
        $this->assertEqualsCanonicalizing(
            ['Claritas Asia Sdn Bhd', 'RecycleCo'],
            collect($contract->companiesInvolvedList())->all()
        );

        Queue::assertPushed(SummariseVendorDocument::class);
    }

    /**
     * COPIED, not moved or referenced. The cycle's report renderer still merges the original
     * into the final PDF, and the two directories carry different role gates.
     */
    public function test_the_document_is_copied_leaving_the_cycles_own_file_in_place(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();

        $this->upload($it, $batch, $vendor, 'offer.pdf');

        $revision = $batch->fresh()->quotations()->first();
        $contract = VendorContract::where('vendor_id', $vendor->id)->firstOrFail();

        $this->assertNotSame($revision->path, $contract->file_path);
        Storage::disk('local')->assertExists($revision->path);
        Storage::disk('local')->assertExists($contract->file_path);
        $this->assertStringStartsWith('vendor_contracts/'.$vendor->id.'/', $contract->file_path);
        $this->assertSame(
            Storage::disk('local')->get($revision->path),
            Storage::disk('local')->get($contract->file_path),
        );
    }

    /**
     * EVERY revision from EVERY vendor. Keeping only the winner would leave a losing vendor's
     * record empty on a cycle they did quote for; keeping only the latest would drop the offer
     * a re-quote replaced, which is the document a question about the price change is about.
     */
    public function test_every_revision_from_every_vendor_is_filed(): void
    {
        $it = $this->itManager();
        $a = $this->vendor('RecycleCo');
        $b = $this->vendor('ScrapWorks');
        $batch = $this->cycle();

        $this->upload($it, $batch, $a, 'a-rev1.pdf', 700);
        $this->upload($it, $batch, $a, 'a-rev2.pdf', 850);   // a re-quote
        $this->upload($it, $batch, $b, 'b-rev1.pdf', 800);

        $this->assertSame(2, VendorContract::where('vendor_id', $a->id)->count());
        $this->assertSame(1, VendorContract::where('vendor_id', $b->id)->count());

        // Only the second revision is numbered — a lone quotation reading "(revision 1)"
        // implies a history that does not exist.
        $titles = VendorContract::where('vendor_id', $a->id)->pluck('title')->all();
        $this->assertContains('E-waste quotation — EWA-2026-Q3', $titles);
        $this->assertContains('E-waste quotation — EWA-2026-Q3 (revision 2)', $titles);
    }

    /** The UNIQUE index is the real guard; the service must not provoke it on a retry. */
    public function test_filing_the_same_revision_twice_produces_one_record(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();
        $this->upload($it, $batch, $vendor, 'offer.pdf');

        $revision = $batch->fresh()->quotations()->first();
        \App\Services\EwasteQuotationFilingService::file($revision);
        \App\Services\EwasteQuotationFilingService::file($revision);

        $this->assertSame(1, VendorContract::where('vendor_id', $vendor->id)->count());
    }

    // ── The badge is derived from the cycle, never stored ────────────────────
    public function test_the_badge_reads_the_cycles_state_rather_than_going_stale(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();
        $this->upload($it, $batch, $vendor, 'offer.pdf');

        $contract = VendorContract::where('vendor_id', $vendor->id)->firstOrFail();
        $this->assertSame('Submitted', $contract->stateBadge()['label']);

        // A filed quotation has no end date. Without the derived branch stateBadge() falls all
        // the way through to a green "Active", asserting a one-off scrap offer is a live
        // agreement — which is the failure this whole branch exists to prevent.
        $this->assertNotSame('Active', $contract->stateBadge()['label']);

        $revision = $batch->fresh()->quotations()->first();
        $batch->submitForApproval($revision, null, $it->id);
        $this->assertSame('Under review', $contract->fresh()->stateBadge()['label']);

        $batch->fresh()->recordManagementDecision('approved', $it->id, null, $revision);
        $this->assertSame('Approved', $contract->fresh()->stateBadge()['label']);
    }

    public function test_a_replaced_offer_reads_as_superseded_and_a_losing_one_as_not_selected(): void
    {
        $it = $this->itManager();
        $a = $this->vendor('RecycleCo');
        $b = $this->vendor('ScrapWorks');
        $batch = $this->cycle();

        $this->upload($it, $batch, $a, 'a-rev1.pdf', 700);
        $this->upload($it, $batch, $a, 'a-rev2.pdf', 850);
        $this->upload($it, $batch, $b, 'b-rev1.pdf', 900);

        $fresh = $batch->fresh();
        $winner = $fresh->quotations()->where('vendor_id', $b->id)->first();
        $fresh->submitForApproval($winner, null, $it->id);
        $fresh->fresh()->recordManagementDecision('approved', $it->id, null, $winner);

        $byQuotation = fn ($q) => VendorContract::where('asset_decommission_quotation_id', $q->id)->firstOrFail();
        $aRev1 = $batch->fresh()->quotations()->where('vendor_id', $a->id)->where('revision', 1)->first();
        $aRev2 = $batch->fresh()->quotations()->where('vendor_id', $a->id)->where('revision', 2)->first();

        // Replaced by its OWN vendor's later offer.
        $this->assertSame('Superseded', $byQuotation($aRev1)->stateBadge()['label']);
        // Their current offer — they simply lost. Not "Superseded": that would suggest they
        // sent a revision they never sent.
        $this->assertSame('Not selected', $byQuotation($aRev2)->stateBadge()['label']);
        $this->assertSame('Approved', $byQuotation($winner)->stateBadge()['label']);
    }

    /** A cancelled cycle keeps its filed quotations — the vendor did send them. */
    public function test_cancelling_a_cycle_keeps_the_quotations_and_marks_them_cancelled(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();
        $this->upload($it, $batch, $vendor, 'offer.pdf');

        $this->actingAs($it)->post(route('decommission.cancel', $batch))->assertRedirect();

        $contract = VendorContract::where('vendor_id', $vendor->id)->firstOrFail();
        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertSame('Cancelled cycle', $contract->fresh()->stateBadge()['label']);
        Storage::disk('local')->assertExists($contract->file_path);
    }

    // ── Amount corrections ───────────────────────────────────────────────────
    /**
     * The figure follows the cycle; the DOCUMENT never changes. Correcting a mis-read amount
     * must reach the filed copy or the two records state different offers for one document.
     */
    public function test_correcting_the_amount_syncs_the_figure_and_leaves_the_document_alone(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();
        $this->upload($it, $batch, $vendor, 'offer.pdf', 900);

        $contract = VendorContract::where('vendor_id', $vendor->id)->firstOrFail();
        $before = Storage::disk('local')->get($contract->file_path);
        $revision = $batch->fresh()->quotations()->first();

        $this->actingAs($it)->post(route('ewaste.amount', $batch), [
            'field' => 'quotation', 'amount' => 1250.50, 'quotation_id' => $revision->id,
        ])->assertRedirect();

        $this->assertSame('1250.50', (string) $contract->fresh()->contract_value);
        $this->assertSame($before, Storage::disk('local')->get($contract->fresh()->file_path));
    }

    /** Clearing the amount means "see the attached document" — never 0.00 on either record. */
    public function test_clearing_the_amount_clears_it_on_the_filed_copy_too(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();
        $this->upload($it, $batch, $vendor, 'offer.pdf', 900);

        $revision = $batch->fresh()->quotations()->first();
        $this->actingAs($it)->post(route('ewaste.amount', $batch), [
            'field' => 'quotation', 'amount' => '', 'quotation_id' => $revision->id,
        ])->assertRedirect();

        $this->assertNull(VendorContract::where('vendor_id', $vendor->id)->firstOrFail()->contract_value);
    }

    // ── Read-only on the vendor profile ──────────────────────────────────────
    /**
     * A hidden button is a courtesy, not a rule. The document is evidence of what a vendor
     * offered on a disposal that may already have been approved on the strength of it.
     */
    public function test_a_filed_quotation_cannot_be_edited_or_deleted_from_the_vendor_profile(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();
        $this->upload($it, $batch, $vendor, 'offer.pdf');

        $contract = VendorContract::where('vendor_id', $vendor->id)->firstOrFail();

        $this->actingAs($it)
            ->put(route('vendors.contracts.update', [$vendor, $contract]), ['ai_summary' => 'rewritten'])
            ->assertForbidden();

        $this->actingAs($it)
            ->delete(route('vendors.contracts.destroy', [$vendor, $contract]))
            ->assertForbidden();

        $this->assertDatabaseHas('vendor_contracts', ['id' => $contract->id]);
        Storage::disk('local')->assertExists($contract->file_path);
    }

    public function test_the_contracts_tab_shows_it_read_only_and_points_at_its_cycle(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();
        $this->upload($it, $batch, $vendor, 'offer.pdf');

        $contract = VendorContract::where('vendor_id', $vendor->id)->firstOrFail();

        $this->actingAs($it)->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->assertSee('E-waste quotation — EWA-2026-Q3')
            ->assertSee(route('decommission.show', $batch), false)
            // The controls the row must not offer. Asserted on the FORM, not the URL: the
            // destroy URI is the contract's own URL under DELETE, and it is a prefix of the
            // assistant panel's legitimate .../summarise action — so assertDontSee on the
            // route would fail on an unrelated control. Same trap as vendors.destroy.
            ->assertDontSee('data-confirm-title="Delete contract"', false)
            ->assertDontSee('contractModal'.$contract->id, false);
    }

    // ── Fail-open ────────────────────────────────────────────────────────────
    /**
     * The quotation gates the whole cycle — no offer, no comparison, no collection. A
     * bookkeeping copy failing must never bounce the upload that carries it.
     */
    public function test_a_filing_failure_does_not_lose_the_quotation(): void
    {
        $it = $this->itManager();
        $vendor = $this->vendor();
        $batch = $this->cycle();

        // A vendor row deleted between validation and filing is the cheapest way to make the
        // insert throw: the contract's FK to vendors has nowhere to point.
        Vendor::where('id', $vendor->id)->delete();

        $this->actingAs($it)->post(route('ewaste.quotation', $batch), [
            'vendor_id' => $vendor->id,
            'quotation_file' => $this->quote('offer.pdf'),
            'quotation_amount' => 900,
        ])->assertSessionHasErrors('vendor_id');

        // A deleted vendor is refused by validation, so nothing was filed and nothing was lost.
        $this->assertSame(0, $batch->fresh()->quotations()->count());
    }

    /**
     * A legacy revision with no vendor (the RFQ was skipped because none was configured) is
     * filed against nobody rather than being attributed to a company that may never have quoted.
     */
    public function test_a_revision_with_no_vendor_files_nothing(): void
    {
        $batch = $this->cycle();
        $revision = $batch->quotations()->create([
            'vendor_id' => null, 'revision' => 1, 'path' => 'ewaste_quotations/x.pdf', 'amount' => 500,
        ]);

        $this->assertNull(\App\Services\EwasteQuotationFilingService::file($revision));
        $this->assertSame(0, VendorContract::count());
    }
}
