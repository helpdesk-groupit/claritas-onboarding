<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Mail\RentalAssetAcknowledgedMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\EwasteCompanyApprover;
use App\Models\RentalAssetAcknowledgement;
use App\Models\RentalAssetAcknowledgementItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Returning rental assets to their vendor.
 *
 * Replaces DecommissionVendorReturnTest, which pinned the old shape: an
 * AssetDecommissionBatch (RET-…) whose collector opened a tokenized page from an email and
 * confirmed their name and IC anonymously. The flow is now:
 *
 *   condition = Returned  →  Decommissioning queue
 *   →  "Create Collection Batch"  →  one RETURN AARF per (vendor, company rented to),
 *      with the vendor DETECTED from each asset
 *   →  the collector verifies and signs IN-APP on our device
 *   →  assets archived, signed PDF emailed to vendor PIC + IT + Finance + the management
 *      named for that company, filed on the vendor profile.
 *
 * The two assertions carried over verbatim from the old suite are the ones that were about
 * the assets rather than the paperwork: acknowledging archives them, and a finished piece of
 * work leaves the "open" panel.
 */
class RentalAssetReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        // The cutoff exists so switching the RECEIPT side on did not demand a form for every
        // asset ever rented. It has no bearing on returns — eligibility there is the
        // operator's own act of marking the asset Returned — and nulling it here keeps that
        // explicit rather than accidental.
        config(['vendors.aarf_track_from' => null]);
    }

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    /**
     * Give every leg of the distribution an addressee.
     *
     * Management is named PER COMPANY, so it has to match stagedReturn()'s
     * company_supplied_to — a name against another entity is not a recipient of this form.
     */
    private function notifiableTeams(string $company = 'Claritas Asia'): array
    {
        $management = User::factory()->create(['role' => 'employee', 'work_email' => 'kelvin.cto@claritas.test']);
        EwasteCompanyApprover::create(['company' => $company, 'user_id' => $management->id]);

        return [
            'finance' => User::factory()->create(['role' => 'finance_manager']),
            'management' => $management,
        ];
    }

    private function vendor(string $name = 'TechLease', array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => $name,
            'vendor_types' => ['rental'],
            'pic_name' => 'Vendor PIC',
            'pic_email' => 'pic@'.strtolower(str_replace(' ', '', $name)).'.test',
            'is_active' => true,
        ], $attrs));
    }

    /**
     * A rental asset marked Returned and staged in the Decommissioning queue.
     *
     * Built explicitly rather than through the factory: AssetInventoryFactory sets no
     * ownership_type, no vendor_id and no company_supplied_to, all three of which decide
     * which form an asset lands on.
     */
    private function stagedReturn(?Vendor $vendor = null, array $assetAttrs = []): array
    {
        $asset = AssetInventory::factory()->create(array_merge([
            'asset_condition' => 'returned',
            'status' => 'unavailable',
            'ownership_type' => 'rental',
            'vendor_id' => $vendor?->id,
            'company_supplied_to' => 'Claritas Asia',
        ], $assetAttrs));

        $row = DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'returned',
            'decommission_type' => 'vendor_return', 'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        return [$asset, $row];
    }

    private function signaturePayload(array $overrides = []): array
    {
        return array_merge([
            'condition_confirmed' => '1',
            'collector_name' => 'Ali Bin Ahmad',
            'collector_ic' => '900101-14-5555',
            'collector_phone' => '012-3456789',
            'collector_company' => 'TechLease',
        ], $overrides);
    }

    /** Stage one returned asset and raise its draft return AARF. */
    private function draftReturn(Vendor $vendor, User $it): array
    {
        [$asset, $row] = $this->stagedReturn($vendor);
        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);

        return [RentalAssetAcknowledgement::where('type', 'return')->firstOrFail(), $asset];
    }

    /**
     * Sign BOTH sides of a return, which is what closes it and archives its assets.
     *
     * A form takes two acknowledgements in either order since 2026-08-13 — the vendor's
     * collector and our Company PIC — and neither on its own archives anything, stores a PDF
     * or sends mail. Every test whose subject is "a signed return" goes through here; the
     * tests whose subject is the ORDER call the two halves themselves.
     */
    private function closeReturn(User $it, Vendor $vendor, RentalAssetAcknowledgement $aarf, array $overrides = [])
    {
        $this->actingAs($it)->post(
            route('vendors.aarf.acknowledge', [$vendor, $aarf]),
            $this->signaturePayload($overrides)
        );

        return $this->actingAs($it)->post(
            route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]),
            ['processor_remarks' => 'Checked against the delivery order.']
        );
    }

    // ── Phase 2: generation from the queue ───────────────────────────────────

    public function test_creating_a_collection_batch_raises_a_return_aarf_with_the_detected_vendor(): void
    {
        Mail::fake();
        $vendor = $this->vendor();
        [$asset, $row] = $this->stagedReturn($vendor);

        $this->actingAs($this->itManager())
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]])
            ->assertRedirect();

        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();
        $this->assertNotNull($aarf, 'Ticking a returned rental asset must raise a return AARF.');
        $this->assertSame($vendor->id, $aarf->vendor_id, 'The vendor is read off the asset, not chosen.');
        $this->assertSame('Claritas Asia', $aarf->company_rented_to);
        $this->assertSame('draft', $aarf->status);
        $this->assertStringStartsWith('RTA-'.now()->year.'-', $aarf->reference);
        $this->assertSame(1, $aarf->items()->count());
        $this->assertSame($asset->id, $aarf->items()->first()->asset_inventory_id);
        $this->assertSame('return', $aarf->items()->first()->direction);

        // No decommissioning batch is created — a rental return is not a disposal.
        $this->assertDatabaseCount('asset_decommission_batches', 0);
        // And nothing is emailed yet: the document is unsigned.
        Mail::assertNothingSent();
    }

    /**
     * The old modal asked IT to pick ONE vendor for the whole selection, so ticking two
     * vendors' assets together filed them all under one of them — and mailed the signed copy
     * to the wrong PIC. The selection now splits.
     */
    public function test_a_mixed_vendor_selection_produces_one_form_per_vendor(): void
    {
        $a = $this->vendor('Alpha Rentals');
        $b = $this->vendor('Beta Leasing');
        [, $rowA] = $this->stagedReturn($a);
        [, $rowB] = $this->stagedReturn($b);

        $this->actingAs($this->itManager())
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$rowA->id, $rowB->id]])
            ->assertRedirect();

        $forms = RentalAssetAcknowledgement::where('type', 'return')->get();
        $this->assertCount(2, $forms);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $forms->pluck('vendor_id')->all(),
            'Each vendor must get its own form, never one form filed under the first vendor seen.'
        );
        $forms->each(fn ($f) => $this->assertSame(1, $f->items()->count()));
    }

    /** One document names one legal entity, so the company splits the form too. */
    public function test_one_vendor_with_two_companies_produces_two_forms(): void
    {
        $vendor = $this->vendor();
        [, $rowA] = $this->stagedReturn($vendor, ['company_supplied_to' => 'Claritas Asia']);
        [, $rowB] = $this->stagedReturn($vendor, ['company_supplied_to' => 'Enlinea']);

        $this->actingAs($this->itManager())
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$rowA->id, $rowB->id]]);

        $this->assertEqualsCanonicalizing(
            ['Claritas Asia', 'Enlinea'],
            RentalAssetAcknowledgement::where('type', 'return')->pluck('company_rented_to')->all()
        );
    }

    /**
     * An asset the operator ticked and never heard about again is one that stays on the books
     * believing it was returned. Both reasons for exclusion must be named back to them.
     */
    public function test_an_asset_with_no_linked_vendor_is_reported_not_dropped(): void
    {
        $vendor = $this->vendor();
        [, $good] = $this->stagedReturn($vendor, ['asset_tag' => 'HAS-VENDOR']);
        [, $orphan] = $this->stagedReturn(null, ['asset_tag' => 'NO-VENDOR']);
        [, $owned] = $this->stagedReturn($vendor, ['asset_tag' => 'COMPANY-OWNED', 'ownership_type' => 'company']);

        $res = $this->actingAs($this->itManager())
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$good->id, $orphan->id, $owned->id]])
            ->assertRedirect();

        $this->assertSame(1, RentalAssetAcknowledgement::where('type', 'return')->count());

        $warning = session('warning');
        $this->assertNotNull($warning, 'Excluded assets must be reported, not silently dropped.');
        $this->assertStringContainsString('NO-VENDOR', $warning);
        $this->assertStringContainsString('COMPANY-OWNED', $warning);
        $this->assertStringNotContainsString('HAS-VENDOR', $warning);

        // A company-owned asset must never be filed as a rental return to the supplier we
        // bought it from — that would invent a rental that never existed.
        $this->assertSame(1, RentalAssetAcknowledgement::first()->items()->count());
    }

    public function test_nothing_resolvable_creates_no_form_at_all(): void
    {
        [, $orphan] = $this->stagedReturn(null, ['asset_tag' => 'NO-VENDOR']);

        $this->actingAs($this->itManager())
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$orphan->id]])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, RentalAssetAcknowledgement::count());
    }

    /** A second click must not raise a duplicate form for assets already on one. */
    public function test_an_asset_already_on_a_return_form_is_not_added_to_a_second(): void
    {
        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor, ['asset_tag' => 'ALREADY-ON']);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]])
            ->assertSessionHas('error');

        $this->assertSame(1, RentalAssetAcknowledgement::where('type', 'return')->count());
    }

    /**
     * The unique index used to be UNIQUE(asset_inventory_id) across ALL forms, which would
     * have barred every asset that was ever receipted from ever being returned — and the
     * assets on a return form are precisely the ones already on a receipt form.
     */
    public function test_an_asset_already_receipted_can_still_be_returned(): void
    {
        $vendor = $this->vendor();
        [$asset, $row] = $this->stagedReturn($vendor);

        $receipt = RentalAssetAcknowledgement::create([
            'reference' => RentalAssetAcknowledgement::generateReference(RentalAssetAcknowledgement::TYPE_RECEIPT),
            'type' => RentalAssetAcknowledgement::TYPE_RECEIPT,
            'vendor_id' => $vendor->id, 'status' => 'acknowledged', 'condition_confirmed' => true,
        ]);
        $receipt->items()->create(RentalAssetAcknowledgementItem::snapshotFrom(
            $asset, RentalAssetAcknowledgement::TYPE_RECEIPT
        ));

        $this->actingAs($this->itManager())
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]])
            ->assertRedirect();

        $this->assertSame(1, RentalAssetAcknowledgement::where('type', 'return')->count());
        $this->assertSame(2, RentalAssetAcknowledgementItem::where('asset_inventory_id', $asset->id)->count());
    }

    /** A receipted asset must not go back to "Pending" on the vendor profile. */
    public function test_the_receipt_pending_list_still_ignores_receipted_assets(): void
    {
        $vendor = $this->vendor();
        [$asset] = $this->stagedReturn($vendor);

        $this->assertTrue(RentalAssetAcknowledgement::pendingAssetsFor($vendor)->contains('id', $asset->id));

        $receipt = RentalAssetAcknowledgement::create([
            'reference' => 'RRA-2026-0001', 'type' => RentalAssetAcknowledgement::TYPE_RECEIPT,
            'vendor_id' => $vendor->id, 'status' => 'acknowledged',
        ]);
        $receipt->items()->create(RentalAssetAcknowledgementItem::snapshotFrom(
            $asset, RentalAssetAcknowledgement::TYPE_RECEIPT
        ));

        $this->assertFalse(RentalAssetAcknowledgement::pendingAssetsFor($vendor->fresh())->contains('id', $asset->id));
    }

    // ── Phase 3: in-app acknowledgement ──────────────────────────────────────

    public function test_the_collector_signs_in_app_and_the_assets_are_archived(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [$asset, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->actingAs($it)
            ->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload([
                'condition_remarks' => 'LT-1 lid scratched.',
            ]))
            ->assertRedirect();

        $aarf->refresh();
        // The collector is the VENDOR'S courier and the declaration is theirs: the moment
        // belongs to them. The account is recorded alongside as the desk it was processed
        // at, never instead of them.
        $this->assertSame('Ali Bin Ahmad', $aarf->collector_name);
        $this->assertSame($it->id, $aarf->acknowledged_by);
        $this->assertSame('LT-1 lid scratched.', $aarf->condition_remarks);

        // ONE of the two signatures. The assets are still ours until we have signed too —
        // archiving on the collector's word alone would take a machine off the books
        // without anybody on our side having agreed it went back.
        $this->assertFalse($aarf->isAcknowledged());
        $this->assertNull($asset->fresh()->decommissioned_at);

        $this->actingAs($it)->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), [
            'processor_remarks' => 'Pre-existing; photographed.',
        ])->assertRedirect();

        $aarf->refresh();
        $this->assertSame('acknowledged', $aarf->status);
        $this->assertNotNull($aarf->acknowledged_at);

        // Phase 4 — the assets leave the active inventory AND the decommissioning queue.
        $this->assertNotNull($asset->fresh()->decommissioned_at);
        $this->assertFalse(AssetInventory::active()->pluck('id')->contains($asset->id));
    }

    /**
     * The order must not matter. Our Company PIC signing first is an ordinary case — the
     * kit is checked and the paperwork prepared before the courier turns up — and it must
     * not archive the assets before the courier has actually acknowledged taking them.
     */
    public function test_our_company_pic_may_sign_first_and_nothing_is_archived_until_the_collector_does(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        $it = $this->itManager();
        $this->notifiableTeams();   // so all four legs have an addressee
        [$aarf, $asset] = $this->draftReturn($vendor, $it);

        $this->actingAs($it)->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), [
            'processor_remarks' => 'Checked against the delivery order.',
        ])->assertRedirect();

        $aarf->refresh();
        $this->assertNotNull($aarf->processor_acknowledged_at);
        $this->assertFalse($aarf->isAcknowledged());
        $this->assertSame('Awaiting Collector', $aarf->statusBadge()['label']);
        $this->assertNull($asset->fresh()->decommissioned_at);
        Mail::assertNothingSent();

        $this->actingAs($it)
            ->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload())
            ->assertRedirect();

        $this->assertTrue($aarf->fresh()->isAcknowledged());
        $this->assertNotNull($asset->fresh()->decommissioned_at);
        Mail::assertSent(RentalAssetAcknowledgedMail::class, 4);
    }

    public function test_the_confirmation_tick_is_mandatory_and_nothing_is_archived_without_it(): void
    {
        $vendor = $this->vendor();
        [$asset, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->actingAs($it)
            ->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload(['condition_confirmed' => '']))
            ->assertSessionHasErrors('condition_confirmed');

        $this->assertSame('draft', $aarf->fresh()->status);
        $this->assertNull($asset->fresh()->decommissioned_at);
    }

    /** The collector's identity is the signature; an unnamed one signs nothing. */
    public function test_the_collector_must_be_named(): void
    {
        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->actingAs($it)
            ->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload([
                'collector_name' => '', 'collector_ic' => '',
            ]))
            ->assertSessionHasErrors(['collector_name', 'collector_ic']);
    }

    /**
     * The return form pre-fills only the collector's COMPANY. Pre-filling the name and IC
     * from the signed-in employee — which the receipt form does, because there the collector
     * really is us — would put our staff's identity under the vendor's declaration.
     */
    public function test_the_return_form_never_prefills_our_own_identity_as_the_collector(): void
    {
        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $html = $this->actingAs($it)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/name="collector_name"[^>]*value=""/',
            $html,
            "The collector's name must start empty on a return — it is the vendor's courier."
        );
        $this->assertMatchesRegularExpression(
            '/name="collector_ic"[^>]*value=""/',
            $html,
            "The collector's IC must start empty on a return."
        );
        // The company IS suggested — a courier normally does come from the vendor.
        $this->assertStringContainsString('value="'.$vendor->name.'"', $html);
    }

    /**
     * A return has two signatories, but the vendor's person among them is the COLLECTOR,
     * who signs the main acknowledgement. Routing them through the rep block as well would
     * record one person twice under two roles on one document.
     */
    public function test_a_return_has_no_separate_vendor_representative_signature(): void
    {
        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->actingAs($it)->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->assertSee('Company PIC')
            ->assertSee('Acknowledged by Company PIC')
            ->assertDontSee('Acknowledged by Vendor PIC');

        $this->actingAs($it)
            ->post(route('vendors.aarf.vendor-acknowledge', [$vendor, $aarf]), [
                'vendor_rep_remarks' => 'Trying to sign a return as a rep.',
                'vendor_rep_name' => 'Someone', 'vendor_rep_ic' => '123',
            ])
            ->assertRedirect();

        $this->assertNull($aarf->fresh()->vendor_rep_acknowledged_at);
    }

    // ── Phase 3b: the two signatures on a return ─────────────────────────────
    //
    // A return is the mirror of a receipt, not a simpler version of it. The second party
    // signs their reply first, then the closing signatory locks the document:
    //
    //   Receipt — vendor's rep replies (typed identity), our staff close it.
    //   Return  — our processor replies (account reference), the collector closes it.

    public function test_our_processor_signs_their_reply_and_the_form_stays_open(): void
    {
        $vendor = $this->vendor();
        $it = $this->itManager();
        [$aarf] = $this->draftReturn($vendor, $it);

        $this->actingAs($it)
            ->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), [
                'condition_remarks' => 'LT-1 lid scratched.',
                'processor_remarks' => 'Scratch predates the rental; photographed.',
            ])
            ->assertRedirect();

        $aarf->refresh();
        $this->assertSame('Scratch predates the rental; photographed.', $aarf->processor_remarks);
        // Our processor is logged in, so the account reference plus the moment IS the
        // signature — nothing is typed, unlike the vendor rep on a receipt.
        $this->assertSame($it->id, $aarf->processor_acknowledged_by);
        $this->assertNotNull($aarf->processor_acknowledged_at);
        // The collector's note is persisted with the answer: a signed reply whose subject
        // was never saved refers to nothing.
        $this->assertSame('LT-1 lid scratched.', $aarf->condition_remarks);
        // Replying does NOT close the document — only the collector can do that.
        $this->assertSame('draft', $aarf->status);
        $this->assertNull($aarf->acknowledged_at);
    }

    /** The mirror of test_our_acknowledgement_cannot_write_words_above_the_vendors_name. */
    public function test_the_collectors_acknowledgement_cannot_write_words_above_our_processors_name(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        $it = $this->itManager();
        [$aarf] = $this->draftReturn($vendor, $it);

        $this->actingAs($it)
            ->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload([
                'processor_remarks' => 'We accept no liability for the damage.',
            ]))
            ->assertRedirect();

        $aarf->refresh();
        $this->assertTrue($aarf->mainAcknowledged());
        $this->assertNull(
            $aarf->processor_remarks,
            "The collector's submit must not author the other party's statement."
        );
        $this->assertNull($aarf->processor_acknowledged_at);
    }

    public function test_once_our_processor_has_signed_the_collectors_note_is_locked(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        $it = $this->itManager();
        [$aarf] = $this->draftReturn($vendor, $it);

        $this->actingAs($it)->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), [
            'condition_remarks' => 'LT-1 lid scratched.',
            'processor_remarks' => 'Pre-existing; photographed.',
        ]);

        // The field is gone from the page and the lock is explained, so the operator is not
        // silently typing into something the server will discard.
        $this->actingAs($it)->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->assertDontSee('name="condition_remarks"', false)
            ->assertSee('has signed a reply to this note');

        // And the server refuses the edit even when the field is posted by hand.
        $this->actingAs($it)->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload([
            'condition_remarks' => 'Actually everything was fine.',
        ]))->assertRedirect();

        $this->assertSame('LT-1 lid scratched.', $aarf->fresh()->condition_remarks);
    }

    public function test_our_processors_signature_preserves_the_collectors_unsaved_work(): void
    {
        $vendor = $this->vendor();
        $it = $this->itManager();
        [$aarf] = $this->draftReturn($vendor, $it);

        $this->actingAs($it)
            ->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), [
                'processor_remarks' => 'Noted, charger was not part of the rental.',
                'condition_remarks' => 'Charger missing.',
                'condition_confirmed' => '1',
                'collector_name' => 'Ali Bin Ahmad',
                'collector_ic' => '900101-14-5555',
                'collector_company' => 'TechLease',
            ])
            ->assertRedirect(route('vendors.aarf.show', [$vendor, $aarf]));

        // None of the collector's declaration is STORED by our submit — signing for them is
        // the whole thing this split exists to prevent.
        $aarf->refresh();
        $this->assertNull($aarf->collector_name);
        $this->assertFalse((bool) $aarf->condition_confirmed);

        // But it must survive the round-trip, or they would have to type it all again.
        $html = $this->actingAs($it)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();
        $this->assertStringContainsString('value="Ali Bin Ahmad"', $html);
        $this->assertStringContainsString('value="900101-14-5555"', $html);
    }

    /** Each direction's second-party action refuses the other's direction. */
    public function test_a_receipt_has_no_processor_signature_block(): void
    {
        $vendor = $this->vendor();
        $it = $this->itManager();

        $receipt = RentalAssetAcknowledgement::create([
            'reference' => 'RRA-2026-0001',
            'type' => RentalAssetAcknowledgement::TYPE_RECEIPT,
            'vendor_id' => $vendor->id,
            'status' => 'draft',
        ]);

        $this->actingAs($it)
            ->post(route('vendors.aarf.processor-acknowledge', [$vendor, $receipt]), [
                'processor_remarks' => 'Trying to sign a receipt as the processor.',
            ])
            ->assertRedirect();

        $receipt->refresh();
        $this->assertNull($receipt->processor_acknowledged_at);
        $this->assertNull($receipt->processor_remarks);
    }

    /**
     * A return CLOSED UNDER THE OLD SINGLE-SIGNATURE RULE is final with only the collector's
     * signature on it, and the live database holds such rows. Nothing may be added to a
     * document already declared complete — so the guard stays, even though the normal
     * two-signature flow can never reach it (the status only flips once both are on it).
     */
    public function test_a_return_closed_under_the_old_rule_can_no_longer_be_signed(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        $it = $this->itManager();
        [$aarf] = $this->draftReturn($vendor, $it);

        $this->actingAs($it)->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload());
        // Exactly the shape those rows are in: acknowledged, with no second signature.
        $aarf->fresh()->update(['status' => RentalAssetAcknowledgement::STATUS_ACKNOWLEDGED]);

        $this->actingAs($it)
            ->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), [
                'processor_remarks' => 'Answering a declaration that is already closed.',
            ])
            ->assertRedirect();

        $this->assertNull($aarf->fresh()->processor_acknowledged_at);
    }

    /**
     * The signature acknowledges the HANDOVER, not the paragraph above it, so remarks are
     * optional — most returns have nothing to reply to, and the box is captioned "Leave
     * remarks if any". This reverses "a signature with nothing above it records nothing",
     * which was written when this block was only a reply to a damage note.
     */
    public function test_our_signature_stands_without_remarks(): void
    {
        $vendor = $this->vendor();
        $it = $this->itManager();
        [$aarf] = $this->draftReturn($vendor, $it);

        $this->actingAs($it)
            ->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), ['processor_remarks' => ''])
            ->assertSessionHasNoErrors();

        $aarf->refresh();
        $this->assertNotNull($aarf->processor_acknowledged_at);
        $this->assertSame($it->id, $aarf->processor_acknowledged_by);
        $this->assertNull($aarf->processor_remarks);
    }

    /**
     * The form must state BOTH facts: who acknowledged (the vendor's collector) and whose
     * account it was processed under. Printing only the account — which is what the
     * single-signature return did — credits our staff with the vendor's declaration.
     *
     * Since 2026-08-14 they are stated in two different places rather than two cells: the
     * sign-off panel names the SIGNATORY, and section 7's sentence carries the account. The
     * panel's own account cell came off at the operator's instruction, in both directions —
     * so the sentence is now the only thing standing between this document and the collapse
     * described above, which is why it is asserted here and not merely assumed.
     */
    public function test_the_signed_return_records_the_collector_and_the_account_it_was_processed_under(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        $it = $this->itManager();
        [$aarf] = $this->draftReturn($vendor, $it);

        $this->closeReturn($it, $vendor, $aarf);

        $this->actingAs($it)->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->assertSee('Acknowledged By ('.$vendor->name.')')
            ->assertSee('Ali Bin Ahmad')
            ->assertDontSee('Processed Under Account')
            ->assertSee('processed under the account of')
            ->assertSee($it->name);
    }

    /**
     * The process log reads in the order the steps HAPPENED, not the order the form stores
     * them in — and on a return it has one more thing to account for than a receipt does.
     *
     * Both signatures are order-free, so a log built in column order would state that the
     * collector signed first on every form our own PIC prepared ahead of the courier turning
     * up. And closing a return takes the assets out of service: that is the consequence
     * somebody reads this panel to confirm, so it is recorded as part of the closing step
     * rather than left to be inferred from the inventory.
     */
    public function test_the_return_log_reads_in_the_order_the_signatures_were_given(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        $it = $this->itManager();
        User::factory()->create(['role' => 'finance_manager']);   // so all three legs have an addressee
        [$aarf] = $this->draftReturn($vendor, $it);

        // Our side first — the ordinary case, and the one a column-ordered log gets wrong.
        $this->actingAs($it)->post(route('vendors.aarf.processor-acknowledge', [$vendor, $aarf]), [
            'processor_remarks' => 'Checked against the delivery order.',
        ])->assertRedirect();

        // The two signatures land in the same second otherwise, and a datetime column cannot
        // tell them apart — which would make this test pass on the build order it is here to
        // rule out.
        $this->travel(2)->minutes();
        $this->actingAs($it)
            ->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload())
            ->assertRedirect();
        $this->travelBack();

        $this->actingAs($it)->get(route('vendors.aarf.show', [$vendor, $aarf]));   // burn the flash
        $html = $this->actingAs($it)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();
        $log = substr($html, strpos($html, 'aarf-sect">Process Log'));

        $ours = strpos($log, 'Acknowledged by the Company PIC');
        $theirs = strpos($log, 'Acknowledged by the Collector');
        $this->assertNotFalse($ours, 'The log must record our signature.');
        $this->assertNotFalse($theirs, "The log must record the collector's signature.");
        $this->assertTrue($ours < $theirs, 'The log must read in the order the parties signed.');

        // The collector is named as the signatory, and the closing step accounts for the kit.
        $this->assertStringContainsString('Ali Bin Ahmad', $log);
        $this->assertStringContainsString('Form closed', $log);
        $this->assertStringContainsString('1 asset archived out of the inventory', $log);
    }

    public function test_a_signed_return_cannot_be_signed_again(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();
        $this->notifiableTeams();   // so all four legs have an addressee

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->closeReturn($it, $vendor, $aarf);
        $firstAt = $aarf->fresh()->acknowledged_at;

        $this->actingAs($it)->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), $this->signaturePayload([
            'collector_name' => 'Someone Else',
        ]))->assertSessionHas('error');

        $this->assertEquals($firstAt, $aarf->fresh()->acknowledged_at);
        $this->assertSame('Ali Bin Ahmad', $aarf->fresh()->collector_name);
        Mail::assertSent(RentalAssetAcknowledgedMail::class, 4);   // not eight
    }

    // ── Phase 4: distribution, archive, and leaving the lists ────────────────

    public function test_the_signed_return_reaches_the_vendor_pic_it_finance_and_management(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();
        // Give the Finance and Management legs a real addressee each.
        $teams = $this->notifiableTeams();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->closeReturn($it, $vendor, $aarf);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, 4);
        Mail::assertSent(
            RentalAssetAcknowledgedMail::class,
            fn ($mail) => $mail->hasTo($vendor->pic_email)
                && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_VENDOR
        );

        // A return is the one direction that takes assets off the books, which is exactly the
        // event management is being copied on.
        Mail::assertSent(
            RentalAssetAcknowledgedMail::class,
            fn ($mail) => $mail->hasTo($teams['management']->work_email)
                && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_MANAGEMENT
        );
    }

    /**
     * The management copy follows the company on the FORM, not the account that signed it.
     *
     * Our processor is an IT manager who may sit at any group company — routing the copy by
     * their employer would send Enlinea's handover to whoever happens to be on shift's
     * management.
     */
    public function test_the_management_copy_follows_the_company_on_the_form(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor, ['company_supplied_to' => 'Enlinea Sdn Bhd']);
        $it = $this->itManager();
        User::factory()->create(['role' => 'finance_manager']);

        $enlinea = User::factory()->create(['role' => 'employee', 'work_email' => 'petrina.ceo@enlinea.test']);
        EwasteCompanyApprover::create(['company' => 'Enlinea Sdn Bhd', 'user_id' => $enlinea->id]);

        $claritas = User::factory()->create(['role' => 'employee', 'work_email' => 'kelvin.cto@claritas.test']);
        EwasteCompanyApprover::create(['company' => 'Claritas Asia', 'user_id' => $claritas->id]);

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();
        $this->assertSame('Enlinea Sdn Bhd', $aarf->company_rented_to);

        $this->closeReturn($it, $vendor, $aarf);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($enlinea->work_email)
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_MANAGEMENT);
        Mail::assertNotSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($claritas->work_email));
    }

    public function test_the_signed_pdf_is_stored_and_downloadable(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();
        $this->closeReturn($it, $vendor, $aarf);

        $aarf->refresh();
        $this->assertNotNull($aarf->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($aarf->pdf_path));
        $this->assertGreaterThan(2000, strlen(Storage::disk('local')->get($aarf->pdf_path)));

        $this->actingAs($it)->get(route('vendors.aarf.pdf', [$vendor, $aarf]))->assertOk();
    }

    /** Archived under Vendor Management — the vendor profile's Assets tab. */
    public function test_the_signed_return_is_archived_on_the_vendor_profile(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();
        $this->closeReturn($it, $vendor, $aarf);

        $this->actingAs($it)->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee($aarf->reference)
            ->assertSee('Return of rental asset');
    }

    /**
     * Carried over from DecommissionVendorReturnTest: a finished piece of work must leave the
     * 'open' panel. It used to be keyed on a status list, which never released a finished
     * vendor return; now an unsigned return form is the open item and a signed one is gone.
     */
    public function test_the_open_panel_lists_unsigned_return_forms_and_releases_signed_ones(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [, $openRow] = $this->stagedReturn($vendor, ['asset_tag' => 'STILL-OPEN']);
        [, $doneRow] = $this->stagedReturn($vendor, ['asset_tag' => 'ALL-DONE', 'company_supplied_to' => 'Enlinea']);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), [
            'dispose_ids' => [$openRow->id, $doneRow->id],
        ]);

        $open = RentalAssetAcknowledgement::where('company_rented_to', 'Claritas Asia')->first();
        $done = RentalAssetAcknowledgement::where('company_rented_to', 'Enlinea')->first();
        $this->closeReturn($it, $vendor, $done);
        $this->assertSame('acknowledged', $done->fresh()->status);

        // Burn one request first: the acknowledgement's success flash names the reference it
        // just signed, and it would satisfy assertDontSee() below from the banner rather than
        // from the panel this test is about.
        $this->actingAs($it)->get(route('assets.index', ['tab' => 'damaged']));

        $this->actingAs($it)->get(route('assets.index', ['tab' => 'damaged']))
            ->assertOk()
            ->assertSee($open->reference)
            ->assertDontSee($done->reference);
    }

    /** The queue is the list of what still has to go back; a signed return leaves it. */
    public function test_a_returned_asset_leaves_the_decommissioning_queue_once_signed(): void
    {
        Mail::fake();
        Storage::fake('local');

        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor, ['asset_tag' => 'QUEUE-EXIT']);
        $it = $this->itManager();

        $this->actingAs($it)->get(route('assets.index', ['tab' => 'damaged']))->assertSee('QUEUE-EXIT');

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();
        $this->closeReturn($it, $vendor, $aarf);

        $this->actingAs($it)->get(route('assets.index', ['tab' => 'damaged']))->assertDontSee('QUEUE-EXIT');
    }

    /** Discarding a draft is the only way an asset goes back to being returnable. */
    public function test_discarding_a_draft_return_puts_its_assets_back_in_the_queue(): void
    {
        $vendor = $this->vendor();
        [$asset, $row] = $this->stagedReturn($vendor, ['asset_tag' => 'BACK-AGAIN']);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->actingAs($it)->delete(route('vendors.aarf.destroy', [$vendor, $aarf]))->assertRedirect();

        $this->assertSame(0, RentalAssetAcknowledgementItem::where('asset_inventory_id', $asset->id)->count());
        $this->assertNull($asset->fresh()->decommissioned_at, 'Discarding a DRAFT must not archive anything.');

        // And it can be raised again.
        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $this->assertSame(1, RentalAssetAcknowledgement::where('type', 'return')->count());
    }

    // ── An asset on an unsigned form is spoken for ───────────────────────────

    /**
     * Until returns became AARFs, a vendor-return batch stamped
     * `dispose_assets.decommission_batch_id`, and AssetController guarded two destructive
     * paths on that column. A return AARF stamps nothing, so both guards silently became
     * no-ops for returns. These two tests pin the replacement.
     *
     * The damage this prevents: restore the asset to Good, its queue row is deleted while it
     * stays an item on the form, and signing that form later stamps `decommissioned_at` on
     * an in-service laptop — while the signed PDF states it went back to the vendor.
     */
    public function test_an_asset_on_an_unsigned_return_form_cannot_be_restored_to_good(): void
    {
        $vendor = $this->vendor();
        [$asset, $row] = $this->stagedReturn($vendor, ['asset_tag' => 'SPOKEN-FOR']);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);
        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();

        $this->actingAs($it)
            ->put(route('assets.update', $asset), $this->assetEditPayload($asset, ['asset_condition' => 'good']))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('returned', $asset->fresh()->asset_condition, 'The condition must not have changed.');
        $this->assertNotNull($row->fresh(), 'The queue row must survive — deleting it strands the asset on the form.');
        $this->assertSame(1, $aarf->items()->count());

        // Discarding the form is the stated remedy, and it must actually work.
        $this->actingAs($it)->delete(route('vendors.aarf.destroy', [$vendor, $aarf]));
        $this->actingAs($it)
            ->put(route('assets.update', $asset), $this->assetEditPayload($asset, ['asset_condition' => 'good']))
            ->assertRedirect();
        $this->assertSame('good', $asset->fresh()->asset_condition);
    }

    /** The same asset must not be able to sit on a return form AND an e-waste cycle. */
    public function test_an_asset_on_an_unsigned_return_form_cannot_be_restaged_to_ewaste(): void
    {
        $vendor = $this->vendor();
        [$asset, $row] = $this->stagedReturn($vendor, ['asset_tag' => 'NO-DOUBLE-BOOK']);
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]]);

        $this->actingAs($it)
            ->put(route('assets.update', $asset), $this->assetEditPayload($asset, [
                'asset_condition' => 'not_good',
                'decommission_reason' => 'Screen dead',
                'ewaste_completeness' => 'complete',
            ]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('vendor_return', $row->fresh()->decommission_type,
            'The staging row must not be re-routed to e-waste while the asset is on a return form.');
    }

    /**
     * A minimal but VALID asset-edit payload. The edit form posts every section, and
     * validateAsset() rejects a partial one — so a test that posts only the condition would
     * bounce on validation and prove nothing about the guard being tested.
     */
    private function assetEditPayload(AssetInventory $asset, array $overrides = []): array
    {
        return array_merge([
            'asset_tag' => $asset->asset_tag,
            'asset_name' => $asset->asset_name ?: 'Test Asset',
            'asset_category' => $asset->asset_category ?: 'it_equipment',
            'asset_type' => $asset->asset_type ?: 'laptop',
            'brand' => $asset->brand ?: 'Dell',
            'model' => $asset->model ?: 'Latitude 5440',
            'serial_number' => $asset->serial_number ?: 'SN-TEST-1',
            'ownership_type' => $asset->ownership_type ?: 'rental',
            'status' => $asset->status ?: 'unavailable',
            'asset_condition' => $asset->asset_condition,
            'company_supplied_to' => $asset->company_supplied_to,
            'vendor_id' => $asset->vendor_id,
        ], $overrides);
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_an_intern_cannot_raise_a_return_form(): void
    {
        $vendor = $this->vendor();
        [, $row] = $this->stagedReturn($vendor);

        $this->actingAs(User::factory()->create(['role' => 'it_intern']))
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]])
            ->assertForbidden();

        $this->assertSame(0, RentalAssetAcknowledgement::count());
    }

    // ── What the retired batch flow left behind ──────────────────────────────

    /**
     * The tokenized public page, its resend button and its two mailables are gone. A rental
     * return is signed on our device, and two ways to sign one document is how they end up
     * disagreeing.
     */
    public function test_the_tokenized_collector_routes_no_longer_exist(): void
    {
        foreach (['decommission.ack.view', 'decommission.ack.acknowledge', 'decommission.ack.photo',
            'decommission.resend', 'decommission.finalize', 'decommission.batch.store'] as $name) {
            $this->assertNull(
                app('router')->getRoutes()->getByName($name),
                "Route [{$name}] belongs to the retired vendor-return batch flow and must not be registered."
            );
        }

        // Asserted on the FILE, not class_exists(): a stale composer classmap still points at
        // a deleted class, and the failed include raises a warning rather than returning false.
        $this->assertFileDoesNotExist(app_path('Mail/DecommissionAckRequestMail.php'));
        $this->assertFileDoesNotExist(app_path('Mail/DecommissionAcknowledgedCopyMail.php'));
        $this->assertFileDoesNotExist(resource_path('views/decommission/ack.blade.php'));
    }

    /** E-waste numbering is untouched by the removal of the RET sequence. */
    public function test_ewaste_batch_numbering_still_works(): void
    {
        $y = now()->year;
        $q = now()->quarter;

        $first = AssetDecommissionBatch::generateBatchNumber('e_waste');
        AssetDecommissionBatch::create(['batch_number' => $first, 'type' => 'e_waste', 'status' => 'awaiting_quotation']);
        $second = AssetDecommissionBatch::generateBatchNumber('e_waste');

        $this->assertSame("EWA-{$y}-Q{$q}", $first);
        $this->assertSame($first.'-2', $second);
    }

    /** Carried over verbatim — the scope that hides a soft-archived asset everywhere. */
    public function test_active_scope_excludes_soft_archived_assets(): void
    {
        $active = AssetInventory::factory()->create(['asset_condition' => 'good']);
        $archived = AssetInventory::factory()->create(['asset_condition' => 'good', 'decommissioned_at' => now()]);

        $ids = AssetInventory::active()->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($archived->id));
    }
}
