<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetInventory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The vendor PROFILE: SST B2B exemption, contracts, billing documents and the asset link.
 * The directory page + access gates live in VendorManagementTest.
 */
class VendorMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        // Behave as with no Anthropic key: the document is stored, the fields are as typed,
        // nothing throws and no reading is attempted.
        config()->set('vendors.ai.enabled', false);
        config()->set('vendors.own_sst_category', null);
    }

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function vendor(array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => 'Acme Rentals Sdn Bhd',
            'vendor_types' => ['rental'],
            'is_active' => true,
        ], $attrs));
    }

    /**
     * A real PDF. `valid_file_content` checks magic bytes against the extension, so
     * UploadedFile::fake()->create() (zero-filled) is rejected before the controller runs.
     */
    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>SAMPLE VENDOR DOCUMENT</h1>')->output()
        );
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
            'ownership_type' => 'company',
        ], $attrs));
    }

    // ── SST / B2B exemption ──────────────────────────────────────────────────
    public function test_sst_verdict_is_not_determined_until_our_own_category_is_configured(): void
    {
        $vendor = $this->vendor(['sst_category' => 'professional', 'sst_number' => 'W10-1']);

        $verdict = $vendor->sstVerdict();

        // The rule must never guess. With our side unset, asserting "chargeable" would be
        // a statement about money we have no basis for.
        $this->assertSame('unknown', $verdict['state']);
        $this->assertStringContainsString('not configured', $verdict['reason']);
        $this->assertFalse($vendor->isSstExemptToUs());
    }

    public function test_same_sst_category_means_the_vendor_cannot_charge_us_sst(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_category' => 'professional', 'sst_number' => 'W10-1']);

        $verdict = $vendor->sstVerdict();

        $this->assertSame('exempt', $verdict['state']);
        $this->assertTrue($vendor->isSstExemptToUs());
    }

    public function test_a_different_sst_category_may_charge_us_sst(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_category' => 'logistics', 'sst_number' => 'W10-2']);

        $this->assertSame('chargeable', $vendor->sstVerdict()['state']);
        $this->assertFalse($vendor->isSstExemptToUs());
    }

    public function test_an_unregistered_vendor_cannot_charge_sst_whatever_our_category_is(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_category' => 'not_registered']);

        $this->assertSame('not_registered', $vendor->sstVerdict()['state']);
        $this->assertTrue($vendor->isSstExemptToUs());
    }

    /**
     * The flag is advisory and must NEVER block filing a real invoice: the vendor's SST
     * category is master data that can be stale or mis-keyed, and refusing the document
     * would be worse than showing the discrepancy to whoever can check it.
     */
    public function test_an_sst_line_from_an_exempt_vendor_is_flagged_but_still_saved(): void
    {
        Storage::fake('local');
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_category' => 'professional']);

        $this->actingAs($this->itManager())->post(route('vendors.billing.store', $vendor), [
            'doc_type' => 'invoice',
            'status' => 'received',
            'doc_number' => 'INV-900',
            'subtotal' => '1000.00',
            'sst_amount' => '80.00',
            'total' => '1080.00',
            'currency' => 'MYR',
        ])->assertSessionHasNoErrors();

        $doc = VendorBillingDocument::where('doc_number', 'INV-900')->first();
        $this->assertNotNull($doc, 'the invoice must be stored even though the SST line is disputed');
        $this->assertNotNull($doc->sstFlag());
        $this->assertStringContainsString('charges SST', $doc->sstFlag());
    }

    public function test_no_sst_flag_when_the_document_carries_no_sst(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_category' => 'professional']);

        $doc = VendorBillingDocument::create([
            'vendor_id' => $vendor->id, 'doc_type' => 'invoice', 'status' => 'received',
            'sst_amount' => 0, 'total' => 500,
        ]);

        $this->assertNull($doc->sstFlag());
    }

    // ── Profile page ─────────────────────────────────────────────────────────
    public function test_the_vendor_profile_renders_its_tabs(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee($vendor->name)
            ->assertSee('id="addContractBtn"', false)
            ->assertSee('id="addBillingBtn"', false)
            // The modal-reopen script is @push'ed from inside the content section; assert it
            // actually reaches @stack('scripts') rather than being silently dropped.
            ->assertSee('getOrCreateInstance', false);
    }

    /**
     * CSP: the app ships 'unsafe-hashes' with NO hash list, so it permits nothing and any
     * inline handler attribute is dead on arrival. Asserted against the vendor view FILES
     * rather than the rendered response, because the shared layout still carries inline
     * handlers of its own (see CLAUDE.md) and a response-level assertion would fail for
     * reasons that have nothing to do with these pages.
     */
    public function test_vendor_views_contain_no_inline_event_handlers(): void
    {
        $offenders = [];
        $dir = resource_path('views/vendors');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $body = file_get_contents($file->getPathname());
            if (preg_match('/\son(click|change|input|submit|load|focus|blur)\s*=/i', $body, $m)) {
                $offenders[] = $file->getFilename().' → '.$m[0];
            }
        }

        $this->assertSame([], $offenders, 'Inline event handlers are blocked by the CSP: '.implode(', ', $offenders));
    }

    /** A viewer who cannot manage vendors must not be shown controls that would 403. */
    public function test_a_read_only_viewer_sees_no_management_controls(): void
    {
        $vendor = $this->vendor();
        VendorContract::create(['vendor_id' => $vendor->id, 'title' => 'Visible', 'status' => 'active']);

        // Every role that reaches the page can also manage it today, so simulate the
        // read-only branch directly — the view must not assume the two are the same.
        $this->actingAs($this->itManager())
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Visible');

        $view = view('vendors.show', [
            'vendor' => $vendor->load(['contracts', 'billingDocuments', 'assets']),
            'assets' => $vendor->assets,
            'summary' => [
                'rented' => 0, 'purchased' => 0, 'monthly_rental' => 0.0,
                'contracts_active' => 1, 'contracts_expiring' => 0,
                'quotations' => 0, 'invoices' => 0, 'sst_flags' => 0,
            ],
            'canManage' => false,
            'sstVerdict' => $vendor->sstVerdict(),
            // Kept in step with VendorController::show() by hand — this render bypasses
            // the controller, so a view variable added there has to be added here too.
            'pendingAssets' => \App\Models\RentalAssetAcknowledgement::pendingAssetsFor($vendor),
            'acknowledgements' => $vendor->rentalAcknowledgements,
            'assetFormStatus' => collect(),
            'askable' => $vendor->askableDocuments(),
            'chatMessages' => $vendor->chatMessages,
            'askFocus' => null,
        ])->render();

        $this->assertStringContainsString('Visible', $view);
        $this->assertStringNotContainsString('id="addContractBtn"', $view);
        $this->assertStringNotContainsString('id="addBillingBtn"', $view);
        $this->assertStringNotContainsString('contractModalNew', $view);
    }

    /**
     * /vendors/create must keep resolving to the create form after the detail route was
     * added — a {vendor} wildcard registered first would swallow it and 404 on binding.
     */
    public function test_the_create_route_is_not_swallowed_by_the_detail_route(): void
    {
        $this->actingAs($this->itManager())
            ->get(route('vendors.create'))
            ->assertOk();
    }

    // ── Contracts ────────────────────────────────────────────────────────────
    public function test_a_contract_can_be_uploaded_and_appears_on_the_profile(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())->post(route('vendors.contracts.store', $vendor), [
            'title' => 'Laptop Rental Agreement 2026',
            'status' => 'active',
            'contract_type' => 'rental',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contract_value' => '24000.00',
            'currency' => 'MYR',
            'document' => $this->pdf('agreement.pdf'),
        ])->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'contracts']));

        $contract = VendorContract::first();
        $this->assertNotNull($contract);
        $this->assertSame('Laptop Rental Agreement 2026', $contract->title);
        $this->assertSame('agreement.pdf', $contract->original_filename);
        $this->assertStringStartsWith('vendor_contracts/'.$vendor->id.'/', $contract->file_path);
        Storage::disk('local')->assertExists($contract->file_path);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Laptop Rental Agreement 2026');
    }

    /**
     * Document AI must FAIL OPEN. With it switched off the upload still succeeds and the
     * typed fields survive untouched — a reading is a bonus on top of the record, never a
     * condition of filing one.
     */
    public function test_a_contract_uploads_fine_when_document_ai_is_unavailable(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())->post(route('vendors.contracts.store', $vendor), [
            'title' => 'Support Agreement',
            'status' => 'active',
            'payment_terms' => '30 days from invoice date',
            'document' => $this->pdf('support.pdf'),
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::first();
        $this->assertSame('30 days from invoice date', $contract->payment_terms);
        Storage::disk('local')->assertExists($contract->file_path);
    }

    public function test_a_contract_can_be_recorded_without_a_document(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())->post(route('vendors.contracts.store', $vendor), [
            'title' => 'Verbal SLA, minuted',
            'status' => 'draft',
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::first();
        $this->assertNull($contract->file_path);
        $this->assertNull($contract->ai_status);
    }

    /**
     * The per-field OCR was removed on 2026-08-11: the fields are typed by hand and the
     * only machine reading is the whole-document summary.
     *
     * Pinned because the removal is easy to half-undo — a re-added scan endpoint with no
     * button, or a button pointing at a route that no longer exists, both look like the
     * feature works until somebody presses Save. Asserting on the route NAMES rather than
     * on rendered markup is what makes it fail loudly if the endpoints come back.
     */
    public function test_the_per_field_document_scan_is_gone_from_the_routes_and_the_forms(): void
    {
        foreach ([
            'vendors.contracts.scan', 'vendors.contracts.rescan',
            'vendors.billing.scan', 'vendors.billing.rescan',
        ] as $name) {
            $this->assertNull(
                app('router')->getRoutes()->getByName($name),
                "Route {$name} should not exist — the per-field scan was removed."
            );
        }

        $vendor = $this->vendor();
        $contract = VendorContract::create([
            'vendor_id' => $vendor->id,
            'title' => 'Laptop Rental Agreement 2026',
            'status' => 'active',
            'file_path' => 'vendor_contracts/'.$vendor->id.'/a.pdf',
            'original_filename' => 'a.pdf',
        ]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-vnd-scan', $html);
        $this->assertStringNotContainsString('Scan document', $html);
        $this->assertStringNotContainsString('name="ocr_token"', $html);

        // The summary control is deliberately still there — it is the reading that stayed.
        $this->assertStringContainsString(
            route('vendors.contracts.summarise', [$vendor, $contract]),
            $html
        );
    }

    /**
     * Type / Period / Value came off the contracts LISTING on 2026-08-11 — the row's
     * summary says what the contract is, so the columns were noise.
     *
     * They are emphatically still stored, and this pins that: the assistant pairs these
     * recorded fields with the document text (`recordedFields()`), which is the only reason
     * "does this invoice match the contract rate?" is answerable and how a mis-keyed value
     * is caught. A later reading of "we removed those fields" that deleted the columns
     * would gut the assistant with nothing on screen to show for it.
     */
    public function test_the_contract_terms_survive_being_dropped_from_the_listing(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())->post(route('vendors.contracts.store', $vendor), [
            'title' => 'Laptop Rental Agreement 2026',
            'status' => 'active',
            'contract_type' => 'rental',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contract_value' => '493.00',
            'billing_cycle' => 'monthly',
            'notice_period_days' => 30,
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::first();
        $this->assertSame('rental', $contract->contract_type);
        $this->assertSame('493.00', (string) $contract->contract_value);
        $this->assertSame('monthly', $contract->billing_cycle);
        $this->assertSame(30, $contract->notice_period_days);
        $this->assertSame('2026-12-31', $contract->end_date->toDateString());

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        // Gone as columns…
        $this->assertStringNotContainsString('<th>Type</th>', $html);
        $this->assertStringNotContainsString('<th>Period</th>', $html);
        $this->assertStringNotContainsString('<th class="text-end">Value</th>', $html);

        // …but still editable, and the expiry signal still reaches the listing via the
        // derived State badge, which is what the Period column was load-bearing for.
        $this->assertStringContainsString('name="contract_value"', $html);
        $this->assertStringContainsString('name="end_date"', $html);
        $this->assertStringContainsString($contract->stateBadge()['label'], $html);
    }

    /**
     * The billing tab lost Dates / Contract / Subtotal / SST / Total as columns on
     * 2026-08-11, for the same reason as the contracts tab.
     *
     * The two WARNINGS those columns carried had to survive the cut, and this is the half
     * of the change worth pinning. Neither is a figure the summary restates: the SST flag
     * says a document charges tax this vendor may not charge, and the banner above the
     * table counts flagged documents without naming one — so losing the per-row marker
     * would leave "3 documents charge SST" with no way to tell which. Overdue is the same
     * shape: derived from the due date, and the due date is no longer on screen.
     */
    public function test_the_billing_row_keeps_its_warnings_after_the_figures_left_the_table(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_category' => 'professional']);

        $doc = VendorBillingDocument::create([
            'vendor_id' => $vendor->id,
            'doc_type' => 'invoice',
            'status' => 'received',
            'doc_number' => 'INV-900',
            'doc_date' => now()->subMonth()->toDateString(),
            'due_date' => now()->subWeek()->toDateString(),
            'subtotal' => 1000,
            'sst_amount' => 80,
            'total' => 1080,
            'currency' => 'MYR',
        ]);

        // Both conditions really hold, so the assertions below test the RENDERING and not
        // an accidentally-inert fixture.
        $this->assertTrue($doc->isOverdue());
        $this->assertNotNull($doc->sstFlag());

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'billing']))
            ->assertOk()
            ->getContent();

        // Gone as columns…
        $this->assertStringNotContainsString('<th>Dates</th>', $html);
        $this->assertStringNotContainsString('<th>Contract</th>', $html);
        $this->assertStringNotContainsString('<th class="text-end">Subtotal</th>', $html);
        $this->assertStringNotContainsString('<th class="text-end">SST</th>', $html);
        $this->assertStringNotContainsString('<th class="text-end">Total</th>', $html);

        // …the warnings survive on the row…
        $this->assertStringContainsString('Overdue', $html);
        $this->assertStringContainsString($doc->sstFlag(), $html);

        // …and every figure is still stored and still editable.
        $this->assertStringContainsString('name="subtotal"', $html);
        $this->assertStringContainsString('name="sst_amount"', $html);
        $this->assertStringContainsString('name="total"', $html);
        $this->assertStringContainsString('name="due_date"', $html);
        $this->assertStringContainsString('name="vendor_contract_id"', $html);
        $this->assertSame('1080.00', (string) $doc->fresh()->total);
    }

    // ── Assets tab: only the sections that apply to this vendor ──────────────
    public function test_a_rental_vendor_is_not_shown_a_purchased_assets_section(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['rental']]);
        $this->asset(['ownership_type' => 'rental', 'vendor_id' => $vendor->id]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('Assets we rent from them')
            ->assertDontSee('Assets we purchased from them');
    }

    public function test_a_supplier_is_not_shown_a_rented_assets_section(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['purchase']]);
        $this->asset(['ownership_type' => 'company', 'vendor_id' => $vendor->id]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('Assets we purchased from them')
            ->assertDontSee('Assets we rent from them');
    }

    /** A vendor tagged both keeps both — the sections follow the tags, not one-or-the-other. */
    public function test_a_vendor_that_both_rents_and_supplies_keeps_both_sections(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['rental', 'purchase']]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('Assets we rent from them')
            ->assertSee('Assets we purchased from them');
    }

    /**
     * The section is hidden for being irrelevant AND empty — never for the tag alone.
     * Vendor types are editable at any time, so keying purely off them would mean
     * untagging a vendor silently hides assets that really are linked to it, on the only
     * page that lists them per vendor. An asset on the "wrong" side must stay visible.
     */
    public function test_an_asset_on_the_wrong_side_is_still_shown_despite_the_vendor_type(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['rental']]);
        $this->asset([
            'asset_tag' => 'BOUGHT-ANYWAY-1',
            'ownership_type' => 'company',
            'vendor_id' => $vendor->id,
        ]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('Assets we purchased from them')
            ->assertSee('BOUGHT-ANYWAY-1');
    }

    /** Neither side applies and nothing is linked — say so rather than render a blank tab. */
    public function test_a_vendor_with_neither_asset_role_gets_an_explanation_not_a_blank_tab(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['ewaste']]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertDontSee('Assets we rent from them')
            ->assertDontSee('Assets we purchased from them')
            ->assertSee('not registered for asset rental or supply');
    }

    /**
     * Expiry is read off the DATE, never off `status` — a contract that lapsed while
     * nobody updated the dropdown is expired whatever the dropdown says.
     */
    public function test_contract_state_is_derived_from_the_end_date_not_the_status(): void
    {
        $vendor = $this->vendor();

        $lapsed = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Lapsed', 'status' => 'active',
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $soon = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Soon', 'status' => 'active',
            'end_date' => now()->addDays(10)->toDateString(),
        ]);
        $open = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Evergreen', 'status' => 'active',
            'end_date' => null, 'auto_renew' => true,
        ]);

        $this->assertSame('danger', $lapsed->stateBadge()['color']);
        $this->assertSame('warning', $soon->stateBadge()['color']);
        $this->assertSame('success', $open->stateBadge()['color']);
        $this->assertFalse($open->isExpired());
    }

    /**
     * Both ids come from the URL. Without the ownership check a contract could be edited
     * or deleted through any other vendor's route and would then be filed under it.
     */
    public function test_a_contract_cannot_be_reached_through_another_vendors_url(): void
    {
        $owner = $this->vendor();
        $other = $this->vendor(['name' => 'Unrelated Vendor']);
        $contract = VendorContract::create([
            'vendor_id' => $owner->id, 'title' => 'Owned', 'status' => 'active',
        ]);

        $this->actingAs($this->itManager())
            ->delete(route('vendors.contracts.destroy', [$other, $contract]))
            ->assertNotFound();

        $this->assertDatabaseHas('vendor_contracts', ['id' => $contract->id]);
    }

    // ── Billing ──────────────────────────────────────────────────────────────
    public function test_a_quotation_can_be_uploaded_and_linked_to_a_contract(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $contract = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Master Agreement', 'status' => 'active',
        ]);

        $this->actingAs($this->itManager())->post(route('vendors.billing.store', $vendor), [
            'doc_type' => 'quotation',
            'status' => 'received',
            'doc_number' => 'QT-2026-001',
            'vendor_contract_id' => $contract->id,
            'doc_date' => '2026-03-01',
            'subtotal' => '900.00',
            'sst_amount' => '72.00',
            'total' => '972.00',
            'currency' => 'MYR',
            'document' => $this->pdf('quote.pdf'),
        ])->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'billing']));

        $doc = VendorBillingDocument::first();
        $this->assertSame('quotation', $doc->doc_type);
        $this->assertSame($contract->id, $doc->vendor_contract_id);
        $this->assertStringStartsWith('vendor_billing/'.$vendor->id.'/', $doc->file_path);
    }

    /**
     * A contract belonging to a different vendor would silently file the document under a
     * relationship it has nothing to do with.
     */
    public function test_a_billing_document_cannot_be_filed_against_another_vendors_contract(): void
    {
        $vendor = $this->vendor();
        $other = $this->vendor(['name' => 'Other Vendor']);
        $foreign = VendorContract::create([
            'vendor_id' => $other->id, 'title' => 'Not ours', 'status' => 'active',
        ]);

        $this->actingAs($this->itManager())
            ->from(route('vendors.show', $vendor))
            ->post(route('vendors.billing.store', $vendor), [
                'doc_type' => 'invoice',
                'status' => 'received',
                'vendor_contract_id' => $foreign->id,
            ])
            ->assertSessionHasErrors('vendor_contract_id');

        $this->assertSame(0, VendorBillingDocument::count());
    }

    /**
     * Regression: clearing the Currency text input 500'd the save.
     *
     * An emptied input posts '', ConvertEmptyStringsToNull turns it into null, and an
     * EXPLICIT null defeats the column's DB default — so `currency` (NOT NULL DEFAULT
     * 'MYR') took a null and died on an integrity violation. Coalesced in the controller
     * rather than by making the column nullable: an amount with no currency beside it is
     * not a meaningful financial record.
     */
    public function test_clearing_the_currency_field_does_not_break_the_save(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.billing.store', $vendor), [
            'doc_type' => 'invoice',
            'status' => 'received',
            'doc_number' => 'INV-CLEARED',
            'total' => '250.00',
            'currency' => '',
        ])->assertSessionHasNoErrors();

        $doc = VendorBillingDocument::where('doc_number', 'INV-CLEARED')->first();
        $this->assertNotNull($doc);
        $this->assertSame(VendorBillingDocument::DEFAULT_CURRENCY, $doc->currency);

        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), [
            'title' => 'Cleared currency contract',
            'status' => 'active',
            'currency' => '',
            'auto_renew' => '',
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::where('title', 'Cleared currency contract')->first();
        $this->assertNotNull($contract);
        $this->assertSame(VendorContract::DEFAULT_CURRENCY, $contract->currency);
        $this->assertFalse($contract->auto_renew);
    }

    // ── Asset link ───────────────────────────────────────────────────────────

    /**
     * `rental_vendor` carries the vendor's PIC — the person we deal with — which the form
     * auto-fills from the picked vendor alongside their contact number. It is deliberately
     * NOT overwritten with the vendor's company name any more; the company is on the FK.
     */
    public function test_a_rented_asset_links_to_its_vendor_and_keeps_the_submitted_pic(): void
    {
        $vendor = $this->vendor(['name' => 'LeaseCo Sdn Bhd', 'pic_name' => 'Jamie Tan', 'pic_phone' => '019-8872 6542']);
        $asset = $this->asset(['ownership_type' => 'rental']);

        $this->actingAs($this->itManager())->put(route('assets.update', $asset), [
            'asset_tag' => $asset->asset_tag,
            'asset_category' => $asset->asset_category,
            'asset_type' => $asset->asset_type,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
            'vendor_id' => $vendor->id,
            'rental_vendor' => 'Jamie Tan',
            'rental_vendor_contact' => '019-8872 6542',
        ])->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertSame($vendor->id, $asset->vendor_id);
        $this->assertSame('Jamie Tan', $asset->rental_vendor);
        $this->assertSame('019-8872 6542', $asset->rental_vendor_contact);
        $this->assertTrue($asset->isRented());
        $this->assertSame($vendor->id, $asset->vendor->id);
    }

    /**
     * The invariant the old name-sync existed to protect, now guaranteed through the FK
     * instead: filtering the listing by a vendor COMPANY still finds an asset whose
     * free-text column holds a person's name. Without this, storing the PIC there would
     * have quietly turned the Vendor filter into a filter by contact person — matching
     * nothing, and hiding every rental asset saved since.
     */
    public function test_the_listing_vendor_filter_finds_an_asset_by_company_not_by_its_pic(): void
    {
        $vendor = $this->vendor(['name' => 'LeaseCo Sdn Bhd', 'pic_name' => 'Jamie Tan']);
        $linked = $this->asset([
            'asset_tag' => 'RENT-LINKED-1',
            'ownership_type' => 'rental',
            'vendor_id' => $vendor->id,
            'rental_vendor' => 'Jamie Tan',
        ]);
        // Never linked to a registered vendor: the free text is the vendor's own name and
        // is all there is to match on, so the filter must still fall back to it.
        $freeText = $this->asset([
            'asset_tag' => 'RENT-FREE-1',
            'ownership_type' => 'rental',
            'rental_vendor' => 'Unregistered Rentals',
        ]);
        $other = $this->asset(['asset_tag' => 'RENT-OTHER-1', 'ownership_type' => 'rental']);

        $response = $this->actingAs($this->itManager())
            ->get(route('assets.index', ['ownership' => 'rental', 'vendor' => 'LeaseCo Sdn Bhd']))
            ->assertOk();

        $response->assertSee($linked->asset_tag);
        $response->assertDontSee($other->asset_tag);

        $this->actingAs($this->itManager())
            ->get(route('assets.index', ['ownership' => 'rental', 'vendor' => 'Unregistered Rentals']))
            ->assertOk()
            ->assertSee($freeText->asset_tag);

        // The dropdown offers COMPANY names — the linked vendor's, never its PIC.
        $list = $this->actingAs($this->itManager())->get(route('assets.index'))->assertOk();
        $list->assertSee('LeaseCo Sdn Bhd');
        $list->assertSee('Unregistered Rentals');
    }

    /**
     * The whole point of widening the link: a COMPANY-OWNED asset keeps its vendor too.
     * The old rental_vendor_id was nulled for every non-rental asset, so "who did we buy
     * this from" had nowhere to live but free text.
     */
    public function test_a_company_owned_asset_keeps_the_vendor_it_was_purchased_from(): void
    {
        $vendor = $this->vendor(['name' => 'Supplier Sdn Bhd', 'vendor_types' => ['purchase']]);
        $asset = $this->asset(['ownership_type' => 'company']);

        $this->actingAs($this->itManager())->put(route('assets.update', $asset), [
            'asset_tag' => $asset->asset_tag,
            'asset_category' => $asset->asset_category,
            'asset_type' => $asset->asset_type,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'company',
            'vendor_id' => $vendor->id,
        ])->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertSame($vendor->id, $asset->vendor_id);
        $this->assertSame('Supplier Sdn Bhd', $asset->purchase_vendor);
        $this->assertFalse($asset->isRented());

        // …and it shows up under the vendor, in the purchased list rather than the rented one.
        $this->assertTrue($vendor->purchasedAssets()->pluck('id')->contains($asset->id));
        $this->assertFalse($vendor->rentedAssets()->pluck('id')->contains($asset->id));
    }

    /**
     * The Add-Asset modal is the primary way an asset is registered, and its vendor picker
     * is only reachable through AssetController::store(). Pinned separately from the edit
     * path because the two build their payload through the same buildAssetData() but are
     * validated and gated differently — a regression in store() alone would leave every
     * newly-registered asset with a null vendor_id while the edit form kept working.
     */
    public function test_registering_a_rental_asset_saves_the_picked_vendor(): void
    {
        $vendor = $this->vendor(['name' => 'PickedCo Sdn Bhd', 'pic_name' => 'Aisyah Rahim', 'pic_phone' => '012-345 6789']);

        $this->actingAs($this->itManager())->post(route('assets.store'), [
            'asset_tag' => 'NEW-RENT-001',
            'asset_category' => 'IT Equipment',
            'asset_type' => 'Laptop',
            'brand' => 'Dell',
            'model' => 'Latitude 5450',
            'serial_number' => 'SN-NEW-RENT-001',
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
            'vendor_id' => $vendor->id,
            // What the Add-Asset modal's picker auto-fills: the vendor's PIC, not its name.
            'rental_vendor' => 'Aisyah Rahim',
            'rental_vendor_contact' => '012-345 6789',
        ])->assertSessionHasNoErrors();

        $asset = AssetInventory::where('asset_tag', 'NEW-RENT-001')->first();
        $this->assertNotNull($asset);
        $this->assertSame($vendor->id, $asset->vendor_id);
        $this->assertSame('Aisyah Rahim', $asset->rental_vendor);
        $this->assertSame('012-345 6789', $asset->rental_vendor_contact);
    }

    public function test_registering_a_company_owned_asset_saves_the_supplier(): void
    {
        $vendor = $this->vendor(['name' => 'BoughtFrom Sdn Bhd', 'vendor_types' => ['purchase']]);

        $this->actingAs($this->itManager())->post(route('assets.store'), [
            'asset_tag' => 'NEW-BUY-001',
            'asset_category' => 'IT Equipment',
            'asset_type' => 'Laptop',
            'brand' => 'HP',
            'model' => 'EliteBook 840',
            'serial_number' => 'SN-NEW-BUY-001',
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'company',
            'vendor_id' => $vendor->id,
        ])->assertSessionHasNoErrors();

        $asset = AssetInventory::where('asset_tag', 'NEW-BUY-001')->first();
        $this->assertNotNull($asset);
        $this->assertSame($vendor->id, $asset->vendor_id);
        $this->assertSame('BoughtFrom Sdn Bhd', $asset->purchase_vendor);
    }

    public function test_the_vendor_profile_lists_both_rented_and_purchased_assets(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['rental', 'purchase']]);
        $rented = $this->asset(['asset_tag' => 'RENT-001', 'ownership_type' => 'rental', 'vendor_id' => $vendor->id]);
        $bought = $this->asset(['asset_tag' => 'BUY-001', 'ownership_type' => 'company', 'vendor_id' => $vendor->id]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee($rented->asset_tag)
            ->assertSee($bought->asset_tag);
    }

    /**
     * The picker must not offer a vendor for work they aren't registered for — an e-waste
     * recycler is not a laptop supplier.
     */
    public function test_the_asset_vendor_picker_is_scoped_by_what_the_vendor_is_engaged_for(): void
    {
        $rentalVendor = $this->vendor(['name' => 'RentalOnly Sdn Bhd', 'vendor_types' => ['rental']]);
        $supplier = $this->vendor(['name' => 'SupplyOnly Sdn Bhd', 'vendor_types' => ['purchase']]);
        $recycler = $this->vendor(['name' => 'RecycleOnly Sdn Bhd', 'vendor_types' => ['ewaste']]);

        $response = $this->actingAs($this->itManager())->get(route('assets.index'));
        $response->assertOk();

        $options = $response->viewData('vendorOptions');
        $this->assertTrue($options['rental']->contains('id', $rentalVendor->id));
        $this->assertFalse($options['rental']->contains('id', $supplier->id));
        $this->assertTrue($options['purchase']->contains('id', $supplier->id));
        $this->assertFalse($options['purchase']->contains('id', $recycler->id));
    }

    /**
     * Regression: retiring a vendor used to silently orphan its assets.
     *
     * The picker lists only ACTIVE vendors, so once a linked vendor was deactivated it had
     * no <option>; the select fell back to "" and the very next save of an UNRELATED field
     * NULLed vendor_id. That destroyed the historical reference which deactivating (rather
     * than deleting) a vendor exists to preserve. The asset's own vendor is now always
     * offered, flagged "(retired)".
     */
    public function test_editing_an_asset_does_not_drop_its_link_to_a_retired_vendor(): void
    {
        $vendor = $this->vendor(['name' => 'Retired Lessor Sdn Bhd']);
        $asset = $this->asset(['ownership_type' => 'rental', 'vendor_id' => $vendor->id]);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.toggle-active', $vendor))->assertRedirect();
        $this->assertFalse($vendor->fresh()->is_active);

        // The retired vendor must still be offered on THIS asset's form...
        $this->actingAs($actor)
            ->get(route('assets.edit', $asset))
            ->assertOk()
            ->assertSee('Retired Lessor Sdn Bhd (retired)');

        // ...so an edit to an unrelated field round-trips it instead of clearing it.
        $this->actingAs($actor)->put(route('assets.update', $asset), [
            'asset_tag' => $asset->asset_tag,
            'asset_category' => $asset->asset_category,
            'asset_type' => $asset->asset_type,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
            'vendor_id' => $vendor->id,
            'ram_size' => '32GB',
        ])->assertSessionHasNoErrors();

        $this->assertSame($vendor->id, $asset->fresh()->vendor_id);
    }

    public function test_an_inactive_vendor_is_not_offered_in_the_asset_picker(): void
    {
        $this->vendor(['name' => 'Retired Vendor', 'vendor_types' => ['rental'], 'is_active' => false]);

        $options = $this->actingAs($this->itManager())
            ->get(route('assets.index'))
            ->viewData('vendorOptions');

        $this->assertCount(0, $options['rental']);
    }

    // ── Bank details + TIN ───────────────────────────────────────────────────
    public function test_the_profile_prints_the_payment_instruction(): void
    {
        $vendor = $this->vendor([
            'tin_number' => 'C12345678901',
            'bank_name' => 'Maybank',
            'bank_account_name' => 'Acme Rentals Sdn. Bhd.',
            'bank_account_number' => '512345678901',
            'bank_branch' => 'Jalan Tun Perak',
            'bank_swift' => 'MBBEMYKL',
        ]);

        $this->actingAs($this->itManager())->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('C12345678901')
            ->assertSee('512345678901')
            ->assertSee('MBBEMYKL')
            // The beneficiary name is the half of the instruction a bank rejects a transfer
            // over, so it has to be printed and not merely stored.
            ->assertSee('Acme Rentals Sdn. Bhd.')
            ->assertDontSee('No bank details recorded');
    }

    /**
     * A bank name with no account number cannot be paid from, but rendered in the same grid
     * as a complete record it reads as one. The profile must say which half is missing.
     */
    public function test_a_half_entered_account_is_flagged_rather_than_read_as_complete(): void
    {
        $vendor = $this->vendor(['bank_name' => 'Maybank']);

        $this->assertFalse($vendor->hasBankDetails());

        $this->actingAs($this->itManager())->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Maybank')
            ->assertSee('Incomplete');
    }

    public function test_a_vendor_with_no_bank_details_says_so(): void
    {
        $vendor = $this->vendor();

        $this->assertFalse($vendor->hasBankDetails());

        $this->actingAs($this->itManager())->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('No bank details recorded');
    }

    public function test_complete_bank_details_are_not_flagged(): void
    {
        $vendor = $this->vendor(['bank_name' => 'Maybank', 'bank_account_number' => '512345678901']);

        $this->assertTrue($vendor->hasBankDetails());

        $this->actingAs($this->itManager())->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertDontSee('Incomplete');
    }

    /** The registration form has to offer every field, or nothing can be entered. */
    public function test_the_registration_form_offers_the_bank_and_tin_fields(): void
    {
        $this->actingAs($this->itManager())->get(route('vendors.create'))
            ->assertOk()
            ->assertSee('name="tin_number"', false)
            ->assertSee('name="bank_name"', false)
            ->assertSee('name="bank_account_name"', false)
            ->assertSee('name="bank_account_number"', false)
            ->assertSee('name="bank_branch"', false)
            ->assertSee('name="bank_swift"', false);
    }

    /** Editing an existing vendor must re-populate them, not silently start blank. */
    public function test_the_edit_form_repopulates_the_stored_bank_details(): void
    {
        $vendor = $this->vendor([
            'tin_number' => 'C12345678901',
            'bank_name' => 'CIMB Bank',
            'bank_account_number' => '800123456789',
        ]);

        $this->actingAs($this->itManager())->get(route('vendors.edit', $vendor))
            ->assertOk()
            ->assertSee('C12345678901')
            ->assertSee('CIMB Bank')
            ->assertSee('800123456789');
    }
}
