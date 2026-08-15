<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetInventory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Assets grouped by the invoice they arrived on.
 *
 * The link is `asset_inventories.origin_billing_document_id` — ONE invoice per asset, the
 * one it came in on, deliberately not "every invoice this asset has appeared on" (a rental
 * is billed again every month; those get their own pivot beside this column when they are
 * wanted). Grouping has two arms: the FK, and the free-text `rental_contract_reference` for
 * everything not linked yet. The second arm is what keeps unregistered vendors' assets on
 * the page at all.
 */
class AssetInvoiceGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        // Behave as with no Anthropic key: documents are stored, nothing is read.
        config()->set('vendors.ai.enabled', false);
        config()->set('vendors.own_sst_category', null);
        // Track every rental asset regardless of when it was registered, so a fixture's
        // rental asset always leaves the vendor with a Report tab — assetsPane() needs it
        // as the boundary of its slice.
        config()->set('vendors.aarf_track_from', null);
    }

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function vendor(array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => 'Acme Rentals Sdn Bhd '.fake()->unique()->numberBetween(1, 9999),
            'vendor_types' => ['rental'],
            'is_active' => true,
        ], $attrs));
    }

    private function invoice(Vendor $vendor, array $attrs = []): VendorBillingDocument
    {
        $doc = new VendorBillingDocument(array_merge([
            'doc_type' => 'invoice',
            'doc_number' => 'IV-25270',
            'status' => 'received',
            'doc_date' => '2026-05-12',
            'total' => 4500.00,
            'currency' => 'MYR',
        ], $attrs));
        $doc->vendor_id = $vendor->id;
        $doc->save();

        return $doc;
    }

    private function asset(array $attrs = []): AssetInventory
    {
        return AssetInventory::create(array_merge([
            'asset_tag' => 'AST-'.fake()->unique()->numberBetween(1000, 9999),
            'asset_category' => 'IT Equipment',
            'asset_type' => 'Laptop',
            'brand' => 'Dell',
            'model' => 'Latitude 5450',
            'serial_number' => strtoupper(fake()->unique()->bothify('SN####??')),
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
        ], $attrs));
    }

    // ── The grouping itself ───────────────────────────────────────────────────
    public function test_assets_are_grouped_by_the_invoice_they_arrived_on(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);

        $a = $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $invoice->id, 'rental_cost_per_month' => 99]);
        $b = $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $invoice->id, 'rental_cost_per_month' => 99]);
        $other = $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV-99999']);

        $groups = AssetInventory::groupByOriginInvoice(
            AssetInventory::with('originInvoice')->whereIn('id', [$a->id, $b->id, $other->id])->get()
        );

        $this->assertCount(2, $groups);

        $registered = $groups->firstWhere('state', 'registered');
        $this->assertSame($invoice->id, $registered['document']->id);
        $this->assertSame(2, $registered['count']);
        // Only the live rental commitment — see the decommissioned-asset test below.
        $this->assertSame(198.0, $registered['monthly']);

        $free = $groups->firstWhere('state', 'unregistered');
        $this->assertSame('IV-99999', $free['reference']);
        $this->assertSame(1, $free['count']);
    }

    public function test_the_free_text_arm_is_case_and_spacing_insensitive_but_keeps_punctuation(): void
    {
        $vendor = $this->vendor();
        $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'iv-25270']);
        $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => ' IV-25270 ']);
        // Dashes are NOT stripped: folding these two together would merge genuinely
        // different invoices under one heading.
        $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV25270']);

        $groups = AssetInventory::groupByOriginInvoice(AssetInventory::with('originInvoice')->get());

        $this->assertCount(2, $groups);
        $this->assertSame(2, $groups->firstWhere('reference', 'iv-25270')['count']);
        $this->assertSame(1, $groups->firstWhere('reference', 'IV25270')['count']);
    }

    public function test_an_asset_with_neither_an_invoice_nor_a_reference_is_still_listed(): void
    {
        $vendor = $this->vendor();
        $orphan = $this->asset(['vendor_id' => $vendor->id, 'asset_tag' => 'AST-ORPHAN']);

        $groups = AssetInventory::groupByOriginInvoice(AssetInventory::with('originInvoice')->get());

        // Dropping it would remove an asset from the only page that lists this vendor's kit.
        $this->assertCount(1, $groups);
        $this->assertSame('none', $groups->first()['state']);
        $this->assertTrue($groups->first()['assets']->contains('id', $orphan->id));

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('No invoice recorded')
            ->assertSee('AST-ORPHAN');
    }

    public function test_a_returned_asset_stops_counting_towards_the_groups_monthly_commitment(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $invoice->id, 'rental_cost_per_month' => 99]);
        $this->asset([
            'vendor_id' => $vendor->id,
            'origin_billing_document_id' => $invoice->id,
            'rental_cost_per_month' => 99,
            'decommissioned_at' => now(),
        ]);

        $group = AssetInventory::groupByOriginInvoice(AssetInventory::with('originInvoice')->get())->first();

        // Both assets stay in the group — the invoice did cover them — but we have stopped
        // paying for the returned one, so posting it as a live commitment would overstate
        // what this vendor bills us every month.
        $this->assertSame(2, $group['count']);
        $this->assertSame(99.0, $group['monthly']);
    }

    public function test_both_asset_tables_group_through_the_same_function(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['rental', 'purchase']]);
        $rentalInvoice = $this->invoice($vendor, ['doc_number' => 'IV-RENT-1']);
        $purchaseInvoice = $this->invoice($vendor, ['doc_number' => 'IV-BUY-1']);

        $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $rentalInvoice->id, 'ownership_type' => 'rental']);
        $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $purchaseInvoice->id, 'ownership_type' => 'company']);

        // The purchased table is grouped too: an asset we bought also arrived on an invoice,
        // and leaving one side flat would give two answers to the same question on one page.
        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('id="inv-doc-'.$rentalInvoice->id.'"', false)
            ->assertSee('id="inv-doc-'.$purchaseInvoice->id.'"', false);
    }

    // ── The tab carries no tie to the billing register ────────────────────────
    public function test_the_group_header_names_the_invoice_and_links_to_no_document(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor, [
            'doc_number' => 'IV-25268',
            'file_path' => 'vendor_billing/'.$vendor->id.'/filed.pdf',
            'original_filename' => 'filed.pdf',
        ]);

        $this->asset([
            'vendor_id' => $vendor->id,
            'origin_billing_document_id' => $invoice->id,
            'invoice_documents' => ['invoices/asset-copy.pdf'],
        ]);

        $pane = $this->assetsPane(
            $this->actingAs($this->itManager())
                ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
                ->assertOk()
                ->getContent()
        );

        // The number still leads the group — it is what says which invoice these assets
        // arrived on, and that fact is recorded on the asset itself.
        $this->assertStringContainsString('IV-25268', $pane);

        // Neither document is reachable from here any more: not the filed copy, not the
        // asset's own, and not the Billing tab.
        $this->assertStringNotContainsString(secure_file_url($invoice->file_path), $pane);
        $this->assertStringNotContainsString(secure_file_url('invoices/asset-copy.pdf'), $pane);
        $this->assertStringNotContainsString('Open in Billing', $pane);
        $this->assertStringNotContainsString(route('vendors.show', [$vendor, 'tab' => 'billing']), $pane);
    }

    public function test_an_unregistered_reference_offers_no_way_to_file_it(): void
    {
        $vendor = $this->vendor();
        $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV-25270']);

        $response = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk();

        // A typed reference reads exactly like a registered one: the claim being made is
        // "these assets arrived on IV-25270", which is equally true either way, and there is
        // no longer an action here for which the difference would matter.
        $response->assertSee('IV-25270')
            ->assertDontSee('Not in the billing register')
            ->assertDontSee(route('vendors.billing.register-from-assets', $vendor), false);
    }

    public function test_the_billing_row_no_longer_points_at_the_assets_tab(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $invoice->id]);
        $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $invoice->id]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'billing']))
            ->assertOk()
            ->assertDontSee('assets arrived on this')
            ->assertDontSee('#inv-doc-'.$invoice->id, false);
    }

    // ── The six columns ───────────────────────────────────────────────────────
    public function test_the_asset_tables_carry_only_the_six_agreed_columns(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['rental', 'purchase']]);
        $this->asset([
            'vendor_id' => $vendor->id,
            'ownership_type' => 'rental',
            'asset_tag' => 'DEMO-LPT-006',
            'serial_number' => 'SN-DEMO-1012',
            'rental_cost_per_month' => 99,
        ]);

        $this->asset([
            'vendor_id' => $vendor->id,
            'ownership_type' => 'company',
            'asset_tag' => 'DEMO-LPT-900',
            'purchase_cost' => 4200,
            'warranty_expiry_date' => '2027-01-01',
        ]);

        $page = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk();

        // Matched as markup, not as words: "Cost", "Status" and "Model" all occur in prose
        // elsewhere on this page, so a bare assertSee would pass on the wrong thing.
        $headings = [
            '<th class="ps-3">Asset Tag</th>',
            '<th>Asset Type</th>',
            '<th>Model</th>',
            '<th>Spec</th>',
            '<th>Rental Period</th>',
            '<th class="text-end">Monthly Charge</th>',
            '<th>Purchased</th>',
            '<th class="text-end">Cost</th>',
        ];
        foreach ($headings as $heading) {
            $page->assertSee($heading, false);
        }

        // Dropped columns. Assigned To / Status / AARF came off this tab by operator
        // decision — the tab answers "what kit sits against this vendor", and who holds it,
        // whether it is free and whether it has been signed for are answered on the asset
        // record and the Report tab respectively.
        $dropped = [
            '<th>Assigned To</th>',
            '<th>Description</th>',
            '<th>Warranty</th>',
            '<th class="text-center">Status</th>',
            '<th class="text-center">AARF</th>',
        ];
        foreach ($dropped as $heading) {
            $page->assertDontSee($heading, false);
        }

        // The serial keeps the row about one machine rather than a model we rent several of.
        $page->assertSee('S/N SN-DEMO-1012');
    }

    public function test_the_specification_is_shown_as_one_column(): void
    {
        $vendor = $this->vendor();
        $this->asset([
            'vendor_id' => $vendor->id,
            'processor' => 'Intel Core i5-1335U',
            'ram_size' => '16GB',
            'storage' => '512GB SSD',
            // operating_system deliberately blank — an empty field is dropped, not printed
            // as a dash, or a machine with one field filled in reads as one with none.
            'screen_size' => '14"',
            'spec_others' => 'Backlit keyboard',
        ]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            // Escaped, not raw: the screen size carries a double quote and Blade renders it
            // as &quot;. assertSee() escapes the expected value the same way.
            ->assertSee('Intel Core i5-1335U · 16GB · 512GB SSD · 14" · Backlit keyboard');
    }

    public function test_an_asset_with_nothing_in_section_b_has_an_empty_spec_summary(): void
    {
        $asset = $this->asset(['vendor_id' => $this->vendor()->id]);

        // Empty rather than a string of separators or dashes — the caller decides what an
        // unrecorded specification says, and both tables print a single em-dash.
        $this->assertSame('', $asset->specSummary());
    }

    // ── The asset form ────────────────────────────────────────────────────────
    public function test_an_invoice_belonging_to_another_vendor_is_refused(): void
    {
        $ours = $this->vendor();
        $theirs = $this->vendor(['name' => 'Someone Else Sdn Bhd']);
        $theirInvoice = $this->invoice($theirs);

        $asset = $this->asset(['vendor_id' => $ours->id]);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'vendor_id' => $ours->id,
                'origin_billing_document_id' => $theirInvoice->id,
            ]))
            ->assertSessionHasErrors('origin_billing_document_id');

        // Filing it anyway would group this asset under another company's bill.
        $this->assertNull($asset->fresh()->origin_billing_document_id);
    }

    public function test_clearing_the_vendor_clears_the_invoice_the_asset_arrived_on(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $asset = $this->asset(['vendor_id' => $vendor->id, 'origin_billing_document_id' => $invoice->id]);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'vendor_id' => null,
                'origin_billing_document_id' => $invoice->id,
            ]))
            ->assertSessionHasNoErrors();

        // A link to an invoice with no vendor beside it would keep grouping the asset under
        // a company the record no longer claims.
        $this->assertNull($asset->fresh()->origin_billing_document_id);
    }

    public function test_an_asset_can_be_linked_to_its_vendors_invoice_from_the_form(): void
    {
        $vendor = $this->vendor();
        $invoice = $this->invoice($vendor);
        $asset = $this->asset(['vendor_id' => $vendor->id]);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'vendor_id' => $vendor->id,
                'origin_billing_document_id' => $invoice->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($invoice->id, $asset->fresh()->origin_billing_document_id);
    }

    // ── Register this invoice ─────────────────────────────────────────────────
    public function test_registering_a_reference_files_it_and_links_every_asset_under_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('invoices/asset-copy.pdf', '%PDF-1.4 sample');

        $vendor = $this->vendor();
        $a = $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV-25270', 'invoice_documents' => ['invoices/asset-copy.pdf']]);
        $b = $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'iv-25270 ']);

        $this->actingAs($this->itManager())
            ->post(route('vendors.billing.register-from-assets', $vendor), ['reference' => 'IV-25270'])
            ->assertRedirect();

        $doc = VendorBillingDocument::where('vendor_id', $vendor->id)->firstOrFail();
        $this->assertSame('invoice', $doc->doc_type);
        $this->assertSame('IV-25270', $doc->doc_number);
        // No amounts are invented — the figures are typed by hand, and a total guessed off an
        // inventory row would be a finance figure nobody entered.
        $this->assertNull($doc->total);
        $this->assertNull($doc->doc_date);

        // The file is COPIED into vendor_billing/, and the asset keeps its own copy.
        $this->assertNotNull($doc->file_path);
        $this->assertStringStartsWith('vendor_billing/'.$vendor->id.'/', $doc->file_path);
        Storage::disk('local')->assertExists($doc->file_path);
        Storage::disk('local')->assertExists('invoices/asset-copy.pdf');

        $this->assertSame($doc->id, $a->fresh()->origin_billing_document_id);
        $this->assertSame($doc->id, $b->fresh()->origin_billing_document_id);
    }

    public function test_registering_a_number_already_in_the_register_links_to_it_instead_of_filing_a_second(): void
    {
        $vendor = $this->vendor();
        $existing = $this->invoice($vendor, ['doc_number' => 'IV-25270']);
        $asset = $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'iv-25270']);

        $this->actingAs($this->itManager())
            ->post(route('vendors.billing.register-from-assets', $vendor), ['reference' => 'iv-25270'])
            ->assertRedirect();

        // Two register rows for one bill is exactly what the register exists to prevent.
        $this->assertSame(1, VendorBillingDocument::where('vendor_id', $vendor->id)->count());
        $this->assertSame($existing->id, $asset->fresh()->origin_billing_document_id);
    }

    public function test_registering_cannot_sweep_in_another_vendors_asset(): void
    {
        $ours = $this->vendor();
        $theirs = $this->vendor(['name' => 'Someone Else Sdn Bhd']);

        $mine = $this->asset(['vendor_id' => $ours->id, 'rental_contract_reference' => 'IV-25270']);
        // Same reference, different vendor — a coincidence, not a shared invoice.
        $notMine = $this->asset(['vendor_id' => $theirs->id, 'rental_contract_reference' => 'IV-25270']);

        $this->actingAs($this->itManager())
            ->post(route('vendors.billing.register-from-assets', $ours), ['reference' => 'IV-25270'])
            ->assertRedirect();

        $doc = VendorBillingDocument::where('vendor_id', $ours->id)->firstOrFail();
        $this->assertSame($doc->id, $mine->fresh()->origin_billing_document_id);
        $this->assertNull($notMine->fresh()->origin_billing_document_id);
    }

    public function test_registering_a_reference_no_asset_carries_changes_nothing(): void
    {
        $vendor = $this->vendor();
        $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV-25270']);

        $this->actingAs($this->itManager())
            ->post(route('vendors.billing.register-from-assets', $vendor), ['reference' => 'IV-00000'])
            ->assertSessionHas('error');

        $this->assertSame(0, VendorBillingDocument::where('vendor_id', $vendor->id)->count());
    }

    public function test_a_read_only_role_cannot_register_an_invoice(): void
    {
        $vendor = $this->vendor();
        $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV-25270']);

        $this->actingAs(User::factory()->create(['role' => 'employee']))
            ->post(route('vendors.billing.register-from-assets', $vendor), ['reference' => 'IV-25270'])
            ->assertForbidden();

        $this->assertSame(0, VendorBillingDocument::where('vendor_id', $vendor->id)->count());
    }

    // ── The backfill ──────────────────────────────────────────────────────────
    public function test_the_backfill_links_an_unambiguous_reference_and_leaves_a_duplicate_alone(): void
    {
        $vendor = $this->vendor();

        $unique = $this->invoice($vendor, ['doc_number' => 'IV-25270']);
        $linkable = $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'iv-25270']);

        // Two register rows carrying the same number — doc_number has no unique constraint,
        // so this is a real state, and guessing between them would file the asset under a
        // bill it may never have been on.
        $this->invoice($vendor, ['doc_number' => 'IV-DUP']);
        $this->invoice($vendor, ['doc_number' => 'IV-DUP']);
        $ambiguous = $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV-DUP']);

        // Matching only within the vendor: another company's identical number is not ours.
        $other = $this->vendor(['name' => 'Someone Else Sdn Bhd']);
        $crossVendor = $this->asset(['vendor_id' => $other->id, 'rental_contract_reference' => 'IV-25270']);

        $this->runBackfill();

        $this->assertSame($unique->id, $linkable->fresh()->origin_billing_document_id);
        $this->assertNull($ambiguous->fresh()->origin_billing_document_id);
        $this->assertNull($crossVendor->fresh()->origin_billing_document_id);
    }

    public function test_the_backfill_never_creates_a_billing_document(): void
    {
        $vendor = $this->vendor();
        $this->asset(['vendor_id' => $vendor->id, 'rental_contract_reference' => 'IV-NOT-FILED']);

        $this->runBackfill();

        // Inventing a finance record out of an inventory free-text field is not something a
        // migration gets to do silently — that is what the Register button is for.
        $this->assertSame(0, VendorBillingDocument::count());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    /**
     * The Assets pane on its own.
     *
     * Every tab pane renders into the DOM whichever one is active, and the Billing pane
     * legitimately links the very document the Assets tab no longer does — so an
     * assertDontSee over the whole page is answered by the wrong markup and passes or fails
     * for the wrong reason. The nav's own "Billing" tab link is outside the pane too.
     */
    private function assetsPane(string $html): string
    {
        $start = strpos($html, 'id="vndAssets"');
        $this->assertNotFalse($start, 'The vendor profile rendered no Assets pane.');

        // The slice MUST be bounded: the modals that follow the panes carry document links
        // of their own, so running to the end of the document would quietly widen this.
        $end = strpos($html, 'id="vndReport"', $start);
        $this->assertNotFalse($end, 'No Report pane to bound the Assets pane with — the fixture needs a pending rental asset.');

        return substr($html, $start, $end - $start);
    }

    /**
     * The asset edit form posts every Section C field; a partial payload would blank the
     * ones left out and the test would be asserting against a different save than the UI's.
     */
    private function formPayload(AssetInventory $asset, array $overrides = []): array
    {
        return array_merge([
            'asset_tag' => $asset->asset_tag,
            'asset_category' => $asset->asset_category,
            'asset_type' => $asset->asset_type,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'ownership_type' => $asset->ownership_type,
            'status' => $asset->status,
            'asset_condition' => $asset->asset_condition,
        ], $overrides);
    }

    /** Run the migration's data step against the current rows, without re-running its schema change. */
    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_12_100000_link_assets_to_originating_invoice.php');

        $method = new \ReflectionMethod($migration, 'backfillFromReference');
        $method->setAccessible(true);
        $method->invoke($migration);
    }
}
