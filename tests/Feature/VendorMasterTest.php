<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetInventory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorContract;
use App\Models\VendorDocumentScan;
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

    /**
     * A document that has been uploaded and READ but not yet filed.
     *
     * Filing a contract or a billing document is a two-request flow since 2026-08-13:
     * VendorDocumentScanController stores the file and reads it, then the modal posts the
     * token with whatever the operator corrected. These tests stage the first half directly
     * so they exercise the save without standing up a fake AI provider — the reading itself
     * is covered in VendorDocumentInsightTest.
     *
     * Requires Storage::fake('local') in the calling test when the file matters.
     */
    private function stagedScan(Vendor $vendor, User $actor, string $kind, array $fields = [], array $overrides = []): VendorDocumentScan
    {
        $path = VendorDocumentScan::directoryFor($kind, $vendor->id).'/'.fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 sample');

        return VendorDocumentScan::create(array_merge([
            'vendor_id' => $vendor->id,
            'user_id' => $actor->id,
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'kind' => $kind,
            'file_path' => $path,
            'original_filename' => 'document.pdf',
            'status' => 'ok',
            'summary' => 'A summary read from the uploaded document.',
            'key_points' => ['Term runs to 31 December 2026'],
            'text' => 'FULL TRANSCRIPT OF THE DOCUMENT',
            'companies' => ['Acme Rentals Sdn Bhd', 'Claritas Sdn Bhd'],
            'fields' => $fields,
        ], $overrides));
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
        $vendor = $this->vendor(['sst_categories' => ['professional'], 'sst_number' => 'W10-1']);

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
        $vendor = $this->vendor(['sst_categories' => ['professional'], 'sst_number' => 'W10-1']);

        $verdict = $vendor->sstVerdict();

        $this->assertSame('exempt', $verdict['state']);
        $this->assertTrue($vendor->isSstExemptToUs());
    }

    public function test_a_different_sst_category_may_charge_us_sst(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => ['logistics'], 'sst_number' => 'W10-2']);

        $this->assertSame('chargeable', $vendor->sstVerdict()['state']);
        $this->assertFalse($vendor->isSstExemptToUs());
    }

    public function test_an_unregistered_vendor_cannot_charge_sst_whatever_our_category_is(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => ['not_registered']]);

        $this->assertSame('not_registered', $vendor->sstVerdict()['state']);
        $this->assertTrue($vendor->isSstExemptToUs());
    }

    /**
     * A vendor registered under several groups is exempt as soon as ONE of them is ours —
     * the exemption is per taxable service, so it cannot require the whole registration to
     * match. Before the column became a list this vendor could only be filed under one
     * group, and filing it under the other read as "chargeable".
     */
    public function test_one_shared_category_out_of_several_is_enough_to_exempt_the_vendor(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => ['rental_leasing', 'professional']]);

        $verdict = $vendor->sstVerdict();

        $this->assertSame('exempt', $verdict['state']);
        $this->assertTrue($vendor->isSstExemptToUs());
    }

    /**
     * …and the reason must say the exemption is only as wide as the shared group. sstFlag()
     * quotes it verbatim onto an invoice, so an unqualified "cannot charge us SST" would
     * assert something false about their Group K work and teach the operator to dismiss it.
     */
    public function test_the_exemption_names_the_categories_it_does_not_cover(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => ['professional', 'rental_leasing']]);

        $reason = $vendor->sstVerdict()['reason'];

        $this->assertStringContainsString('Group G', $reason);
        $this->assertStringContainsString('Group K', $reason);
        $this->assertStringContainsString('may charge SST on', $reason);
    }

    public function test_no_shared_category_across_several_is_still_chargeable(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => ['logistics', 'construction']]);

        $this->assertSame('chargeable', $vendor->sstVerdict()['state']);
        $this->assertFalse($vendor->isSstExemptToUs());
    }

    /**
     * A Sales Tax registrant holds no SERVICE tax registration, so nothing they bill us
     * carries SST — the same verdict as "not registered", reached from a different fact.
     * It is deliberately NOT exclusive, so it must not suppress a group filed beside it.
     */
    public function test_a_sales_tax_registrant_with_no_service_group_cannot_charge_service_tax(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => ['sales_tax']]);

        $this->assertSame('not_registered', $vendor->sstVerdict()['state']);
        $this->assertTrue($vendor->isSstExemptToUs());

        $vendor->update(['sst_categories' => ['sales_tax', 'logistics']]);

        $this->assertSame('chargeable', $vendor->fresh()->sstVerdict()['state']);
    }

    /**
     * "We have not checked" and "they told us they are not registered" are different
     * answers with different consequences: the first must never make an invoice's SST line
     * look wrong, and the second must.
     */
    public function test_no_category_recorded_is_unknown_not_unregistered(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => null]);

        $this->assertSame('unknown', $vendor->sstVerdict()['state']);
        $this->assertStringContainsString('not been recorded', $vendor->sstVerdict()['reason']);
        $this->assertFalse($vendor->isSstExemptToUs());
    }

    /** Our own side may be a list too, and the exemption turns on the overlap. */
    public function test_our_own_side_may_hold_several_categories(): void
    {
        config()->set('vendors.own_sst_category', ['professional', 'other_services']);
        $vendor = $this->vendor(['sst_categories' => ['other_services']]);

        $this->assertSame('exempt', $vendor->sstVerdict()['state']);
    }

    /**
     * A category the list no longer offers still renders as words. The label is printed on
     * the profile and quoted into the SST verdict, so degrading it to a raw slug would put
     * `healthcare` in front of Finance as if it were a code.
     */
    public function test_a_retired_category_is_still_readable(): void
    {
        $vendor = $this->vendor(['sst_categories' => ['healthcare']]);

        $this->assertNotSame('healthcare', $vendor->sstCategoryLabel(), 'the raw key must not surface as the label');
        $this->assertStringContainsString('no longer offered', $vendor->sstCategoryLabel());
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
        $vendor = $this->vendor(['sst_categories' => ['professional']]);

        $actor = $this->itManager();
        $scan = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_BILLING, [
            'doc_type' => 'invoice',
            'doc_number' => 'INV-900',
            'subtotal' => 1000.00,
            'sst_amount' => 80.00,
            'total' => 1080.00,
            'currency' => 'MYR',
        ]);

        $this->actingAs($actor)->post(route('vendors.billing.store', $vendor), [
            'scan_token' => $scan->token,
        ])->assertSessionHasNoErrors();

        $doc = VendorBillingDocument::where('doc_number', 'INV-900')->first();
        $this->assertNotNull($doc, 'the invoice must be stored even though the SST line is disputed');
        $this->assertNotNull($doc->sstFlag());
        $this->assertStringContainsString('charges SST', $doc->sstFlag());
    }

    public function test_no_sst_flag_when_the_document_carries_no_sst(): void
    {
        config()->set('vendors.own_sst_category', 'professional');
        $vendor = $this->vendor(['sst_categories' => ['professional']]);

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
            // Kept in step with VendorController::show() by hand — this render bypasses
            // the controller, so a view variable added there has to be added here too.
            'pendingAssets' => \App\Models\RentalAssetAcknowledgement::pendingAssetsFor($vendor),
            'acknowledgements' => $vendor->rentalAcknowledgements,
            'ewasteCycles' => collect(),
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
    public function test_a_contract_is_filed_from_a_scanned_document(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $scan = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_CONTRACT, [
            'title' => 'Laptop Rental Agreement 2026',
            'contract_type' => 'rental',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contract_value' => 24000.00,
            'currency' => 'MYR',
        ], ['original_filename' => 'agreement.pdf']);

        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), [
            'scan_token' => $scan->token,
            'ai_summary' => 'A summary read from the uploaded document.',
            'companies_involved' => 'Acme Rentals Sdn Bhd, Claritas Sdn Bhd',
        ])->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'contracts']));

        $contract = VendorContract::first();
        $this->assertNotNull($contract);
        // Every one of these was typed by hand until 2026-08-13 and is now read off the
        // document — the whole point of the change.
        $this->assertSame('Laptop Rental Agreement 2026', $contract->title);
        $this->assertSame('rental', $contract->contract_type);
        $this->assertSame('2026-12-31', $contract->end_date->toDateString());
        $this->assertSame('agreement.pdf', $contract->original_filename);
        $this->assertStringStartsWith('vendor_contracts/'.$vendor->id.'/', $contract->file_path);
        Storage::disk('local')->assertExists($contract->file_path);

        // The reading travels with it rather than being run a second time.
        $this->assertSame('ok', $contract->ai_status);
        $this->assertSame('FULL TRANSCRIPT OF THE DOCUMENT', $contract->ai_text);
        $this->assertSame(['Acme Rentals Sdn Bhd', 'Claritas Sdn Bhd'], $contract->companies_involved);

        // The staging row is consumed; the FILE it was holding is not.
        $this->assertSame(0, VendorDocumentScan::count());

        $this->actingAs($actor)
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Laptop Rental Agreement 2026');
    }

    /**
     * Document AI must FAIL OPEN. A document the reading could not touch is still filed,
     * still keeps its file, and says on its row why there is no summary — an unreadable
     * document must never be an unfileable one.
     */
    public function test_a_contract_whose_document_could_not_be_read_is_still_filed(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $scan = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_CONTRACT, [], [
            'status' => 'failed',
            'summary' => null,
            'key_points' => null,
            'text' => null,
            'companies' => null,
            'original_filename' => 'support.pdf',
        ]);

        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), [
            'scan_token' => $scan->token,
            // Nothing was read, so the operator writes the summary themselves.
            'ai_summary' => 'Support agreement, 30 days from invoice date.',
            'companies_involved' => 'Acme Rentals Sdn Bhd',
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::first();
        $this->assertSame('failed', $contract->ai_status);
        // The title has to resolve to something recognisable: the column is NOT NULL and
        // nobody typed one.
        $this->assertSame('support', $contract->title);
        $this->assertSame('Support agreement, 30 days from invoice date.', $contract->ai_summary);
        $this->assertTrue($contract->summaryIsEdited());
        Storage::disk('local')->assertExists($contract->file_path);
    }

    /**
     * The Add form is an upload form: without a document there is no summary, no parties
     * and no terms — nothing the listing is built to show. Refused rather than filed as an
     * empty row somebody would have to notice was empty.
     */
    public function test_a_document_cannot_be_filed_without_one(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())
            ->post(route('vendors.contracts.store', $vendor), ['ai_summary' => 'Verbal SLA, minuted'])
            ->assertSessionHasErrors('scan_token');

        $this->assertSame(0, VendorContract::count());
    }

    /**
     * A scan token that no longer resolves — swept as abandoned, or belonging to somebody
     * else — must produce a stated refusal, never a contract with no document under it.
     */
    public function test_a_stale_or_foreign_scan_token_files_nothing(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $mine = $this->itManager();
        $theirs = $this->itManager();

        // Uploaded by another operator: the token is not a capability.
        $scan = $this->stagedScan($vendor, $theirs, VendorDocumentScan::KIND_CONTRACT);

        $this->actingAs($mine)
            ->post(route('vendors.contracts.store', $vendor), ['scan_token' => $scan->token])
            ->assertSessionHas('error');

        $this->assertSame(0, VendorContract::count());

        // And a token for a BILLING upload cannot be filed as a contract — otherwise an
        // invoice's due date would be stored as a contract's term.
        $billing = $this->stagedScan($vendor, $mine, VendorDocumentScan::KIND_BILLING);

        $this->actingAs($mine)
            ->post(route('vendors.contracts.store', $vendor), ['scan_token' => $billing->token])
            ->assertSessionHas('error');

        $this->assertSame(0, VendorContract::count());
    }

    /**
     * The scan-before-save path came BACK on 2026-08-13, reversing the 2026-08-11 removal
     * of the per-field OCR — but only on the operator's terms: the reading produces a
     * SUMMARY they correct, and the record fields ride along as a by-product.
     *
     * What the old removal was protecting is pinned here, because it is the part that is
     * easy to lose again: the field values must NOT be posted from the Add form. A value
     * the form cannot show is a value nobody checked, and accepting one from the request
     * would let a crafted submit set a contract value that was never on screen.
     */
    public function test_the_add_form_files_the_scan_without_accepting_field_values(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $scan = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_CONTRACT, [
            'title' => 'Read from the document',
            'contract_value' => 500.00,
        ]);

        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), [
            'scan_token' => $scan->token,
            // All ignored: the record is built from the stored scan, never from the request.
            'title' => 'Typed by hand',
            'contract_value' => '999999.00',
            'status' => 'terminated',
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::first();
        $this->assertSame('Read from the document', $contract->title);
        $this->assertSame('500.00', (string) $contract->contract_value);
        $this->assertSame('active', $contract->status);
    }

    /**
     * The Add form must not carry a field-entry form. Pinned on the rendered markup because
     * this is the operator-facing half of the decision: the summary is what they review,
     * and the figures are met on Edit.
     */
    public function test_the_add_form_asks_for_the_document_and_the_summary_only(): void
    {
        $vendor = $this->vendor();

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        // The Add modal is everything up to the first Edit modal (there are none here, so
        // the whole page) — assert on the inputs it would have to carry.
        $this->assertStringContainsString('name="ai_summary"', $html);
        $this->assertStringContainsString('name="companies_involved"', $html);
        $this->assertStringContainsString('name="scan_token"', $html);
        $this->assertStringNotContainsString('name="contract_value"', $html);
        $this->assertStringNotContainsString('name="start_date"', $html);
        $this->assertStringNotContainsString('name="notice_period_days"', $html);
    }

    /**
     * Type / Period / Value came off the contracts LISTING on 2026-08-11 and off every FORM
     * on 2026-08-13 — the scan reads them and the summary is what a reader wants.
     *
     * They are emphatically still STORED, and this pins that: the assistant pairs these
     * recorded fields with the document text (`recordedFields()`), which is the only reason
     * "does this invoice match the contract rate?" is answerable and how a mis-read value
     * is caught. A later reading of "we removed those fields" that deleted the columns
     * would gut the assistant with nothing on screen to show for it.
     */
    public function test_the_contract_terms_survive_being_dropped_from_the_listing(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $scan = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_CONTRACT, [
            'title' => 'Laptop Rental Agreement 2026',
            'contract_type' => 'rental',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contract_value' => 493.00,
            'billing_cycle' => 'monthly',
            'notice_period_days' => 30,
        ]);

        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), [
            'scan_token' => $scan->token,
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::first();
        $this->assertSame('rental', $contract->contract_type);
        $this->assertSame('493.00', (string) $contract->contract_value);
        $this->assertSame('monthly', $contract->billing_cycle);
        $this->assertSame(30, $contract->notice_period_days);
        $this->assertSame('2026-12-31', $contract->end_date->toDateString());

        $html = $this->actingAs($actor)
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        // Gone as columns…
        $this->assertStringNotContainsString('<th>Type</th>', $html);
        $this->assertStringNotContainsString('<th>Period</th>', $html);
        $this->assertStringNotContainsString('<th class="text-end">Value</th>', $html);

        // …and gone as inputs too, on every form on the page…
        $this->assertStringNotContainsString('name="contract_value"', $html);
        $this->assertStringNotContainsString('name="end_date"', $html);
        $this->assertStringNotContainsString('name="notice_period_days"', $html);

        // …but the expiry signal still reaches the listing through the derived Status badge,
        // which is what the Period column was load-bearing for and what "Status determined
        // by the start and end date" means in practice.
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
        $vendor = $this->vendor(['sst_categories' => ['professional']]);

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
        // Escaped: the SST category labels carry the group letters and an ampersand, so the
        // raw verdict string never appears verbatim in rendered HTML.
        $this->assertStringContainsString(e($doc->sstFlag()), $html);

        // …and gone as inputs too: no form on this page types a figure any more.
        $this->assertStringNotContainsString('name="subtotal"', $html);
        $this->assertStringNotContainsString('name="sst_amount"', $html);
        $this->assertStringNotContainsString('name="total"', $html);
        $this->assertStringNotContainsString('name="due_date"', $html);
        $this->assertStringNotContainsString('name="doc_number"', $html);

        // …while every figure is still STORED, which is what feeds those two warnings and
        // the assistant's recorded-fields comparison.
        $this->assertSame('1080.00', (string) $doc->fresh()->total);
        $this->assertSame('80.00', (string) $doc->fresh()->sst_amount);
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
    /**
     * Whether a billing document is a quotation or an invoice is decided by the READING —
     * the Add form does not ask, because the document says so on its face — and so are its
     * number, dates and figures.
     */
    public function test_a_quotation_is_filed_exactly_as_it_was_read(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $scan = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_BILLING, [
            'doc_type' => 'quotation',
            'doc_number' => 'QT-2026-001',
            'doc_date' => '2026-03-01',
            'subtotal' => 900.00,
            'sst_amount' => 72.00,
            'total' => 972.00,
            'currency' => 'MYR',
        ]);

        $this->actingAs($actor)->post(route('vendors.billing.store', $vendor), [
            'scan_token' => $scan->token,
        ])->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'billing']));

        $doc = VendorBillingDocument::first();
        $this->assertSame('quotation', $doc->doc_type);
        $this->assertSame('QT-2026-001', $doc->doc_number);
        $this->assertSame('2026-03-01', $doc->doc_date->toDateString());
        $this->assertSame('972.00', (string) $doc->total);
        $this->assertSame('received', $doc->status);
        $this->assertStringStartsWith('vendor_billing/'.$vendor->id.'/', $doc->file_path);
    }

    /**
     * The Edit form sets the SUMMARY and the parties, and nothing else — as of 2026-08-13
     * not even the lifecycle status.
     *
     * Status used to be the one exception here, on the grounds that no reading of the
     * document could produce it. It is now derived from the payment slip filed against the
     * invoice, so accepting one from this request would be a second answer to a question the
     * evidence already answers — and the only one able to assert a bill was settled with no
     * document behind it. Posting 'paid' must therefore change nothing on a register whose
     * entire value is provenance.
     */
    public function test_the_summary_is_the_only_thing_edit_still_sets(): void
    {
        $vendor = $this->vendor();
        $doc = VendorBillingDocument::create([
            'vendor_id' => $vendor->id, 'doc_type' => 'invoice', 'status' => 'received',
            'doc_number' => 'INV-500', 'total' => 250.00, 'currency' => 'MYR',
        ]);

        $this->actingAs($this->itManager())
            ->put(route('vendors.billing.update', [$vendor, $doc]), [
                'ai_summary' => 'Settled in full.',
                // Ignored — no form displays any of these any more.
                'status' => 'paid',
                'doc_number' => 'RENAMED',
                'total' => '999999.00',
                'due_date' => '1999-01-01',
            ])
            ->assertSessionHasNoErrors();

        $doc->refresh();
        $this->assertSame('Settled in full.', $doc->ai_summary);
        $this->assertSame('INV-500', $doc->doc_number);
        $this->assertSame('250.00', (string) $doc->total);
        $this->assertNull($doc->due_date);

        // The retired column is untouched, and — the part that matters — the row still
        // reads Pending, because no payment slip was filed.
        $this->assertSame('received', $doc->status);
        $this->assertFalse($doc->isPaid());
        $this->assertSame('Pending', $doc->paymentState()['label']);
    }

    /**
     * A contract belonging to a different vendor would silently file the document under a
     * relationship it has nothing to do with.
     */
    /**
     * `vendor_contract_id` has no control on any form as of 2026-08-13 — the forms ask for
     * nothing but the summary and the parties.
     *
     * The column and the relation stay (legacy rows carry it, and the assistant reads
     * "filed against contract" out of `recordedFields()`), so what has to be pinned is that
     * a hand-posted id changes nothing. Silently accepting one would file a document under
     * a relationship it has nothing to do with — and, worse, one belonging to another
     * vendor — through a field no screen displays.
     */
    public function test_a_posted_contract_link_is_ignored_now_that_no_form_offers_one(): void
    {
        $vendor = $this->vendor();
        $other = $this->vendor(['name' => 'Other Vendor']);
        $foreign = VendorContract::create([
            'vendor_id' => $other->id, 'title' => 'Not ours', 'status' => 'active',
        ]);
        $doc = VendorBillingDocument::create([
            'vendor_id' => $vendor->id, 'doc_type' => 'invoice', 'status' => 'received',
        ]);

        $this->actingAs($this->itManager())
            ->from(route('vendors.show', $vendor))
            ->put(route('vendors.billing.update', [$vendor, $doc]), [
                'vendor_contract_id' => $foreign->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($doc->fresh()->vendor_contract_id);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'billing']))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="vendor_contract_id"', $html);
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
    /**
     * `currency` is NOT NULL with a DB default, and an explicit null DEFEATS a column
     * default — the write dies on an integrity violation instead of falling back. Nobody
     * types a currency any more, so the risk moved: a document whose currency the scan
     * could not read arrives with the key simply absent, and the save must still land.
     *
     * Same for `auto_renew`, which the reading only reports when the document actually says
     * so — an absent auto-renew clause is not a clause saying it does not renew.
     */
    public function test_a_reading_with_no_currency_still_saves(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $billing = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_BILLING, [
            'doc_type' => 'invoice',
            'doc_number' => 'INV-NO-CCY',
            'total' => 250.00,
        ]);

        $this->actingAs($actor)->post(route('vendors.billing.store', $vendor), [
            'scan_token' => $billing->token,
        ])->assertSessionHasNoErrors();

        $doc = VendorBillingDocument::where('doc_number', 'INV-NO-CCY')->first();
        $this->assertNotNull($doc);
        $this->assertSame(VendorBillingDocument::DEFAULT_CURRENCY, $doc->currency);

        $contract = $this->stagedScan($vendor, $actor, VendorDocumentScan::KIND_CONTRACT, [
            'title' => 'No currency contract',
        ]);

        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), [
            'scan_token' => $contract->token,
        ])->assertSessionHasNoErrors();

        $saved = VendorContract::where('title', 'No currency contract')->first();
        $this->assertNotNull($saved);
        $this->assertSame(VendorContract::DEFAULT_CURRENCY, $saved->currency);
        $this->assertFalse($saved->auto_renew);
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
