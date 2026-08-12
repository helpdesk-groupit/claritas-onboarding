<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Mail\RentalAssetAcknowledgedMail;
use App\Models\AssetInventory;
use App\Models\RentalAssetAcknowledgement;
use App\Models\RentalAssetAcknowledgementItem;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AARF — the rental asset receipt/return acknowledgement on the vendor profile.
 *
 * The behaviours pinned here are the ones whose failure is SILENT: an asset acknowledged
 * twice, an asset that quietly never appears on any form, a signed form that rewrites
 * itself when the underlying asset is later edited, and a form reachable through the
 * wrong vendor's URL.
 */
class RentalAssetAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Storage::fake('local');
        // Most tests here are about the form, not the cutoff. Null = track every rental
        // asset regardless of age, so they read without a date in the way; the cutoff has
        // its own tests below which set it explicitly.
        config()->set('vendors.aarf_track_from', null);
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
            'pic_name' => 'Siti Rahman',
            'pic_email' => 'siti@acme.test',
            'is_active' => true,
        ], $attrs));
    }

    private function rentalAsset(Vendor $vendor, array $attrs = []): AssetInventory
    {
        return AssetInventory::create(array_merge([
            'asset_tag' => 'AST-'.fake()->unique()->numberBetween(1000, 9999),
            'asset_name' => 'Field Laptop',
            'asset_category' => 'it_equipment',
            'asset_type' => 'Laptop',
            'brand' => 'Dell',
            'model' => 'Latitude 5450',
            'serial_number' => strtoupper(fake()->unique()->bothify('SN####??')),
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
            'vendor_id' => $vendor->id,
            'company_supplied_to' => 'Claritas Asia Sdn Bhd',
        ], $attrs));
    }

    /** Sign a draft with the minimum valid payload. */
    private function acknowledge(User $actor, Vendor $vendor, RentalAssetAcknowledgement $aarf, array $overrides = [])
    {
        return $this->actingAs($actor)->post(
            route('vendors.aarf.acknowledge', [$vendor, $aarf]),
            array_merge([
                'condition_confirmed' => '1',
                'collector_name' => 'Ahmad Faizal',
                'collector_ic' => '900101-10-5555',
            ], $overrides)
        );
    }

    /** Back-date an asset's registration, which is what the cutoff is compared against. */
    private function registeredOn(AssetInventory $asset, string $when): AssetInventory
    {
        $asset->forceFill(['created_at' => $when])->save();

        return $asset->fresh();
    }

    // ── Start-tracking cutoff ────────────────────────────────────────────────
    public function test_assets_that_predate_the_tracking_date_are_never_pending(): void
    {
        config()->set('vendors.aarf_track_from', '2026-08-07');

        $vendor = $this->vendor();
        $old = $this->registeredOn($this->rentalAsset($vendor, ['asset_tag' => 'DEMO-LPT-001']), '2026-08-06 15:13:20');
        $newA = $this->registeredOn($this->rentalAsset($vendor, ['asset_tag' => 'FIX05483']), '2026-08-07 09:24:12');
        $newB = $this->registeredOn($this->rentalAsset($vendor, ['asset_tag' => 'FIX-PW0C79DJ']), '2026-08-07 09:25:02');

        $pending = RentalAssetAcknowledgement::pendingAssetsFor($vendor);

        // Switching the feature on must not demand acknowledgement for kit that was
        // already sitting here — that buries the ones that genuinely just arrived.
        $this->assertEqualsCanonicalizing([$newA->id, $newB->id], $pending->pluck('id')->all());
        $this->assertFalse($pending->contains('id', $old->id));

        $this->actingAs($this->itManager())->post(route('vendors.aarf.generate', $vendor));

        $aarf = RentalAssetAcknowledgement::first();
        $this->assertSame(2, $aarf->items()->count());
        $this->assertNotContains($old->id, $aarf->items->pluck('asset_inventory_id')->all());
    }

    public function test_an_asset_registered_exactly_on_the_tracking_date_is_tracked(): void
    {
        config()->set('vendors.aarf_track_from', '2026-08-07');

        $vendor = $this->vendor();
        $asset = $this->registeredOn($this->rentalAsset($vendor), '2026-08-07 00:00:00');

        // The boundary is inclusive: the day you switch it on is the first tracked day,
        // so an asset registered that morning is not silently skipped.
        $this->assertTrue(RentalAssetAcknowledgement::pendingAssetsFor($vendor)->contains('id', $asset->id));
        $this->assertFalse(RentalAssetAcknowledgement::isPreExisting($asset));
    }

    public function test_an_unset_or_unreadable_tracking_date_tracks_every_asset(): void
    {
        $vendor = $this->vendor();
        $ancient = $this->registeredOn($this->rentalAsset($vendor), '2020-01-01 00:00:00');

        foreach ([null, '', '   ', 'not a date at all'] as $bad) {
            config()->set('vendors.aarf_track_from', $bad);

            // Failing towards "track everything" is deliberate. A stray value that stopped
            // tracking would produce no form, no badge and no error — nobody would notice.
            $this->assertNull(RentalAssetAcknowledgement::trackFrom(), 'value: '.var_export($bad, true));
            $this->assertTrue(
                RentalAssetAcknowledgement::pendingAssetsFor($vendor)->contains('id', $ancient->id),
                'value: '.var_export($bad, true)
            );
        }
    }

    public function test_the_shipped_tracking_date_default_is_parseable(): void
    {
        // Read the config FILE, not the runtime value — setUp() nulls the latter.
        $shipped = (require config_path('vendors.php'))['aarf_track_from'];
        config()->set('vendors.aarf_track_from', $shipped);

        // A typo here would fall back to tracking everything: safe, but it silently undoes
        // the cutoff the operator asked for and nothing on screen would say so.
        $this->assertNotNull(
            RentalAssetAcknowledgement::trackFrom(),
            'config(vendors.aarf_track_from) ships a value that cannot be parsed as a date: '.var_export($shipped, true)
        );
    }

    public function test_the_profile_marks_pre_existing_assets_apart_from_acknowledged_ones(): void
    {
        config()->set('vendors.aarf_track_from', '2026-08-07');

        $vendor = $this->vendor();
        $this->registeredOn($this->rentalAsset($vendor, ['asset_tag' => 'DEMO-LPT-001']), '2026-08-06 15:13:20');
        $this->registeredOn($this->rentalAsset($vendor, ['asset_tag' => 'FIX05483']), '2026-08-07 09:24:12');

        $page = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk();

        // A pre-existing asset was never asked for. Dressing it as acknowledged would
        // assert a signature that does not exist.
        $page->assertSee('Pre-AARF')
            ->assertSee('Pending')
            ->assertSee('awaiting acknowledgement');

        // The count itself is the thing that regressed, so assert it at the source rather
        // than through banner markup that wraps the number in its own tag.
        $this->assertSame(1, RentalAssetAcknowledgement::pendingAssetsFor($vendor)->count());
    }

    // ── Generation ───────────────────────────────────────────────────────────
    public function test_a_newly_registered_rental_asset_is_pending_and_lands_on_a_generated_aarf(): void
    {
        $vendor = $this->vendor();
        $asset = $this->rentalAsset($vendor);

        // The whole premise of the feature: register a rental asset, it shows up waiting.
        $this->assertTrue(RentalAssetAcknowledgement::pendingAssetsFor($vendor)->contains('id', $asset->id));

        $this->actingAs($this->itManager())
            ->post(route('vendors.aarf.generate', $vendor))
            ->assertRedirect();

        $aarf = RentalAssetAcknowledgement::first();
        $this->assertNotNull($aarf);
        $this->assertSame(RentalAssetAcknowledgement::TYPE_RECEIPT, $aarf->type);
        $this->assertSame('Receipt of rental asset', $aarf->typeLabel());
        $this->assertSame('Claritas Asia Sdn Bhd', $aarf->company_rented_to);
        $this->assertSame(1, $aarf->items()->count());

        // Once it is on a form it stops being pending — otherwise a second click would
        // produce a duplicate form for the same asset.
        $this->assertTrue(RentalAssetAcknowledgement::pendingAssetsFor($vendor)->isEmpty());
    }

    public function test_assets_rented_to_different_companies_generate_one_aarf_each(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Claritas Asia Sdn Bhd']);
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Enlinea Sdn Bhd']);
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Enlinea Sdn Bhd']);

        $this->actingAs($this->itManager())->post(route('vendors.aarf.generate', $vendor));

        // One document names one legal entity — a single form spanning two would be
        // signed by a company that never rented half of it.
        $this->assertSame(2, RentalAssetAcknowledgement::count());
        $this->assertSame(1, RentalAssetAcknowledgement::where('company_rented_to', 'Claritas Asia Sdn Bhd')->first()->items()->count());
        $this->assertSame(2, RentalAssetAcknowledgement::where('company_rented_to', 'Enlinea Sdn Bhd')->first()->items()->count());
    }

    public function test_an_asset_with_no_company_is_still_put_on_a_form_rather_than_dropped(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor, ['company_supplied_to' => null]);

        $this->actingAs($this->itManager())->post(route('vendors.aarf.generate', $vendor));

        // Silently omitting an asset from the form meant to account for it is worse than
        // a form whose company reads "unspecified".
        $aarf = RentalAssetAcknowledgement::first();
        $this->assertNotNull($aarf);
        $this->assertNull($aarf->company_rented_to);
        $this->assertSame(1, $aarf->items()->count());
    }

    public function test_purchased_and_decommissioned_assets_are_never_pending(): void
    {
        $vendor = $this->vendor(['vendor_types' => ['rental', 'purchase']]);
        $this->rentalAsset($vendor, ['ownership_type' => 'company']);       // bought, not rented
        $this->rentalAsset($vendor, ['decommissioned_at' => now()]);        // already gone

        $this->assertTrue(RentalAssetAcknowledgement::pendingAssetsFor($vendor)->isEmpty());

        $this->actingAs($this->itManager())
            ->post(route('vendors.aarf.generate', $vendor))
            ->assertSessionHas('error');

        $this->assertSame(0, RentalAssetAcknowledgement::count());
    }

    public function test_generating_twice_does_not_acknowledge_the_same_asset_again(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $this->acknowledge($actor, $vendor, RentalAssetAcknowledgement::first());

        // The second run has nothing to do — the asset was signed for once, and once is
        // the whole contract of "not yet acknowledged".
        $this->actingAs($actor)
            ->post(route('vendors.aarf.generate', $vendor))
            ->assertSessionHas('error');

        $this->assertSame(1, RentalAssetAcknowledgement::count());
        $this->assertSame(1, RentalAssetAcknowledgementItem::count());
    }

    public function test_only_the_new_assets_appear_on_the_next_aarf(): void
    {
        $vendor = $this->vendor();
        $first = $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $this->acknowledge($actor, $vendor, RentalAssetAcknowledgement::first());

        // A new delivery arrives months later.
        $second = $this->rentalAsset($vendor);
        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));

        $latest = RentalAssetAcknowledgement::latest('id')->first();
        $tags = $latest->items->pluck('asset_inventory_id')->all();

        // The form covers the arrival, not a re-signing of everything ever rented.
        $this->assertSame([$second->id], $tags);
        $this->assertNotContains($first->id, $tags);
    }

    // ── Snapshot ─────────────────────────────────────────────────────────────
    public function test_a_signed_form_does_not_change_when_the_asset_is_later_edited(): void
    {
        $vendor = $this->vendor();
        $asset = $this->rentalAsset($vendor, ['serial_number' => 'SN-ORIGINAL', 'brand' => 'Dell']);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();
        $this->acknowledge($actor, $vendor, $aarf);

        $asset->update(['serial_number' => 'SN-CORRECTED', 'brand' => 'Lenovo']);

        // The document states what was handed over on the day. Re-typing a serial number
        // afterwards must not silently rewrite something somebody signed.
        $item = $aarf->fresh()->items->first();
        $this->assertSame('SN-ORIGINAL', $item->serial_number);
        $this->assertSame('Dell', $item->brand);
    }

    // ── Acknowledgement ──────────────────────────────────────────────────────
    public function test_acknowledging_records_the_signed_in_user_and_locks_the_form(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();

        $this->acknowledge($actor, $vendor, $aarf, [
            'condition_remarks' => 'LT-9 has a scratched lid.',
            'collector_company' => 'Claritas Asia Sdn Bhd',
            'collector_phone' => '012-3456789',
        ])->assertRedirect(route('vendors.aarf.show', [$vendor, $aarf]));

        $aarf->refresh();
        $this->assertTrue($aarf->isAcknowledged());
        $this->assertTrue($aarf->condition_confirmed);
        // The signatory comes off the account, never a typed name.
        $this->assertSame($actor->id, $aarf->acknowledged_by);
        $this->assertNotNull($aarf->acknowledged_at);
        $this->assertSame('LT-9 has a scratched lid.', $aarf->condition_remarks);
        $this->assertFalse($aarf->isEditable());
    }

    // ── The vendor's delivery representative ─────────────────────────────────
    /** Draft an AARF and return it, ready for whichever party signs next. */
    private function draftFor(Vendor $vendor, User $actor): RentalAssetAcknowledgement
    {
        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));

        return RentalAssetAcknowledgement::first();
    }

    private function repAcknowledge(User $actor, Vendor $vendor, RentalAssetAcknowledgement $aarf, array $overrides = [])
    {
        return $this->actingAs($actor)->post(
            route('vendors.aarf.vendor-acknowledge', [$vendor, $aarf]),
            array_merge([
                'vendor_rep_remarks' => 'Crack on LT-9 was present before dispatch; noted and accepted.',
                'vendor_rep_company' => 'Acme Rentals Sdn Bhd',
                'vendor_rep_name' => 'Ravi Kumar',
                'vendor_rep_ic' => '880202-08-1234',
                'vendor_rep_phone' => '019-8887777',
            ], $overrides)
        );
    }

    public function test_the_vendor_representative_signs_their_own_reply(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->assertFalse($aarf->vendorRepAcknowledged());

        $this->repAcknowledge($actor, $vendor, $aarf)
            ->assertRedirect(route('vendors.aarf.show', [$vendor, $aarf]));

        $aarf->refresh();
        $this->assertTrue($aarf->vendorRepAcknowledged());
        $this->assertSame('Ravi Kumar', $aarf->vendor_rep_name);
        $this->assertSame('880202-08-1234', $aarf->vendor_rep_ic);
        $this->assertStringContainsString('present before dispatch', $aarf->vendor_rep_remarks);
        $this->assertNotNull($aarf->vendor_rep_acknowledged_at);

        // Their signature does not close the document — ours does.
        $this->assertFalse($aarf->isAcknowledged());
    }

    public function test_a_vendor_reply_cannot_be_stored_without_the_identity_behind_it(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        // Remarks and identity are validated together, so "a damage reply nobody signed"
        // can never reach the table — an anonymous rebuttal settles nothing.
        $this->repAcknowledge($actor, $vendor, $aarf, ['vendor_rep_name' => '', 'vendor_rep_ic' => ''])
            ->assertSessionHasErrors(['vendor_rep_name', 'vendor_rep_ic']);

        $aarf->refresh();
        $this->assertNull($aarf->vendor_rep_remarks);
        $this->assertFalse($aarf->vendorRepAcknowledged());
    }

    public function test_a_vendor_signature_needs_remarks_above_it(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->repAcknowledge($actor, $vendor, $aarf, ['vendor_rep_remarks' => ''])
            ->assertSessionHasErrors('vendor_rep_remarks');

        $this->assertFalse($aarf->fresh()->vendorRepAcknowledged());
    }

    public function test_the_vendor_reply_is_optional_and_our_acknowledgement_stands_alone(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        // Most handovers have nothing to reply to. A missing vendor reply must not block
        // the receipt from being closed.
        $this->acknowledge($actor, $vendor, $aarf)->assertRedirect();

        $aarf->refresh();
        $this->assertTrue($aarf->isAcknowledged());
        $this->assertFalse($aarf->vendorRepAcknowledged());
        $this->assertNull($aarf->vendor_rep_remarks);
    }

    public function test_the_vendor_representative_cannot_sign_twice(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->repAcknowledge($actor, $vendor, $aarf);
        $firstSignedAt = $aarf->fresh()->vendor_rep_acknowledged_at;

        // A signature is a moment; re-submitting must not move it or rewrite what was
        // signed above it.
        $this->repAcknowledge($actor, $vendor, $aarf, ['vendor_rep_name' => 'Someone Else'])
            ->assertSessionHas('error');
        $this->assertSame('Ravi Kumar', $aarf->fresh()->vendor_rep_name);
        $this->assertEquals($firstSignedAt, $aarf->fresh()->vendor_rep_acknowledged_at);
    }

    public function test_the_vendor_representative_cannot_sign_after_the_form_is_closed(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf);

        $this->repAcknowledge($actor, $vendor, $aarf)->assertSessionHas('error');
        $this->assertFalse($aarf->fresh()->vendorRepAcknowledged());
    }

    public function test_our_acknowledgement_cannot_write_words_above_the_vendors_name(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        // The rep's reply is their signed statement. Smuggling it through our submit would
        // let us author what they are recorded as having said.
        $this->acknowledge($actor, $vendor, $aarf, [
            'vendor_rep_remarks' => 'We accept full liability.',
            'vendor_rep_name' => 'Forged Name',
        ]);

        $aarf->refresh();
        $this->assertTrue($aarf->isAcknowledged());
        $this->assertNull($aarf->vendor_rep_remarks);
        $this->assertNull($aarf->vendor_rep_name);
        $this->assertFalse($aarf->vendorRepAcknowledged());
    }

    public function test_the_signed_vendor_reply_appears_on_the_form_and_the_pdf(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->repAcknowledge($actor, $vendor, $aarf);
        $this->acknowledge($actor, $vendor, $aarf);

        $this->actingAs($actor)
            ->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->assertSee('Ravi Kumar')
            ->assertSee('880202-08-1234')
            ->assertSee('present before dispatch', false);

        $this->actingAs($actor)
            ->get(route('vendors.aarf.pdf', [$vendor, $aarf]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_both_sides_share_one_form_so_neither_can_discard_the_others_input(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $content = $this->actingAs($actor)
            ->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->getContent();

        // Strip <style>/<script> bodies and HTML comments first — the shared layout
        // mentions "<form>" inside a CSS comment, which would count as an open tag.
        $markup = preg_replace('/<style\b[^>]*>.*?<\/style>|<script\b[^>]*>.*?<\/script>|<!--.*?-->/is', '', $content);

        // No nesting anywhere — a nested <form> is invalid HTML and browsers drop the
        // inner one, so its button would submit nothing at all. (The shared layout ships
        // its own logout forms, so the absolute tag count is not ours to assert.)
        preg_match_all('/<form\b|<\/form>/i', $markup, $matches);
        $depth = 0;
        $maxDepth = 0;
        foreach ($matches[0] as $tag) {
            $depth += str_starts_with(strtolower($tag), '</') ? -1 : 1;
            $maxDepth = max($maxDepth, $depth);
        }
        $this->assertSame(0, $depth, 'Unbalanced <form> tags in the AARF page.');
        $this->assertSame(1, $maxDepth, 'A <form> is nested inside another on the AARF page.');

        // The document itself has exactly one form. Two forms meant the rep's submit
        // posted only the rep's fields, so the receiving staff's tick and remarks were
        // silently dropped on the way back.
        $this->assertSame(1, substr_count($content, 'id="aarfAckForm"'));
        $this->assertStringNotContainsString('aarfRepForm', $content);

        // Both sides' controls hang off that one form...
        $this->assertMatchesRegularExpression('/name="condition_confirmed"[^>]*form="aarfAckForm"/', $content);
        $this->assertMatchesRegularExpression('/name="vendor_rep_ic"[^>]*form="aarfAckForm"/', $content);

        // ...and the rep's button only re-targets it. formnovalidate is load-bearing: the
        // receipt's own required fields would otherwise block a submit that is not the
        // rep's responsibility.
        $this->assertMatchesRegularExpression('/formaction="[^"]*vendor-acknowledge"/', $content);
        $this->assertStringContainsString('formnovalidate', $content);
    }

    public function test_the_vendor_signature_preserves_the_receiving_staffs_unsaved_work(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        // Our staff tick the box and write their damage note, then hand the screen to the
        // vendor's courier — WITHOUT having acknowledged yet.
        $this->repAcknowledge($actor, $vendor, $aarf, [
            'condition_confirmed' => '1',
            'condition_remarks' => 'LT-9 arrived with a scratched lid.',
        ])->assertRedirect(route('vendors.aarf.show', [$vendor, $aarf]));

        $aarf->refresh();

        // The note the rep signed against is stored with their reply — a signed answer
        // whose question was never saved refers to nothing.
        $this->assertSame('LT-9 arrived with a scratched lid.', $aarf->condition_remarks);
        $this->assertTrue($aarf->vendorRepAcknowledged());

        // But the tick is OUR declaration, made by acknowledge(). It is not written to a
        // draft, or a printed draft would claim a confirmation nobody gave.
        $this->assertFalse($aarf->condition_confirmed);
        $this->assertFalse($aarf->isAcknowledged());

        // It comes back on screen instead, so nothing typed before the handover is lost.
        $this->actingAs($actor)
            ->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->assertSee('LT-9 arrived with a scratched lid.')
            ->assertSee('checked', false);
    }

    public function test_a_failed_vendor_signature_keeps_the_receiving_staffs_input_too(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        // The rep fumbles their IC. Our side must survive the bounce as well — this is the
        // same round-trip, just the unhappy path.
        $this->repAcknowledge($actor, $vendor, $aarf, [
            'vendor_rep_ic' => '',
            'condition_confirmed' => '1',
            'condition_remarks' => 'LT-9 arrived with a scratched lid.',
        ])
            ->assertSessionHasErrors('vendor_rep_ic')
            ->assertSessionHasInput('condition_remarks', 'LT-9 arrived with a scratched lid.')
            ->assertSessionHasInput('condition_confirmed', '1');

        // Nothing was written: the reply was rejected, so the note it answers is not ours
        // to bank either.
        $this->assertNull($aarf->fresh()->condition_remarks);
    }

    public function test_the_condition_tick_box_is_required(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();

        // The tick box IS the document. Signing without it would produce a form that
        // asserts nothing while looking exactly like one that does.
        $this->actingAs($actor)
            ->post(route('vendors.aarf.acknowledge', [$vendor, $aarf]), [
                'collector_name' => 'Ahmad Faizal',
                'collector_ic' => '900101-10-5555',
            ])
            ->assertSessionHasErrors('condition_confirmed');

        $this->assertFalse($aarf->fresh()->isAcknowledged());
    }

    public function test_an_acknowledged_form_cannot_be_signed_again_or_deleted(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();
        $this->acknowledge($actor, $vendor, $aarf);

        $firstSignedAt = $aarf->fresh()->acknowledged_at;

        $this->acknowledge($actor, $vendor, $aarf, ['collector_name' => 'Someone Else'])
            ->assertSessionHas('error');
        $this->assertSame('Ahmad Faizal', $aarf->fresh()->collector_name);
        $this->assertEquals($firstSignedAt, $aarf->fresh()->acknowledged_at);

        // Signed evidence has no delete path.
        $this->actingAs($actor)
            ->delete(route('vendors.aarf.destroy', [$vendor, $aarf]))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('rental_asset_acknowledgements', ['id' => $aarf->id]);
    }

    public function test_discarding_a_draft_returns_its_assets_to_pending(): void
    {
        $vendor = $this->vendor();
        $asset = $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();
        $this->assertTrue(RentalAssetAcknowledgement::pendingAssetsFor($vendor)->isEmpty());

        $this->actingAs($actor)->delete(route('vendors.aarf.destroy', [$vendor, $aarf]));

        // Deleting the draft is the ONLY way back, so it has to actually work — otherwise
        // a mistaken generate would strand the asset with no form and no way to make one.
        $this->assertDatabaseMissing('rental_asset_acknowledgements', ['id' => $aarf->id]);
        $this->assertTrue(RentalAssetAcknowledgement::pendingAssetsFor($vendor)->contains('id', $asset->id));
    }

    // ── Distribution of the signed copy ──────────────────────────────────────
    /** @return array{it: User, finance: User} */
    private function notifiableTeams(): array
    {
        return [
            'it' => User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.test']),
            'finance' => User::factory()->create(['role' => 'finance_manager', 'work_email' => 'finance.manager@claritas.test']),
        ];
    }

    public function test_the_signed_form_is_emailed_to_the_vendor_pic_it_and_finance(): void
    {
        Mail::fake();

        $teams = $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf);

        // Three separate sends — one per audience — each carrying the PDF.
        Mail::assertSent(RentalAssetAcknowledgedMail::class, 3);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo('siti@acme.test')
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_VENDOR);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($teams['it']->work_email)
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_IT);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($teams['finance']->work_email)
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_FINANCE);
    }

    public function test_the_emailed_copy_carries_the_report_as_a_pdf_attachment(): void
    {
        Mail::fake();

        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, function ($mail) use ($aarf) {
            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);
            $this->assertSame($aarf->reference.'.pdf', $attachments[0]->as);
            $this->assertSame('application/pdf', $attachments[0]->mime);

            return true;
        });
    }

    public function test_the_attachment_still_renders_when_the_stored_pdf_is_missing(): void
    {
        Mail::fake();

        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);
        $this->acknowledge($actor, $vendor, $aarf);

        // acknowledge() swallows a PDF-write failure so bookkeeping cannot block a
        // signature, which means pdf_path can legitimately be null on a signed form.
        // Announcing an attached document and attaching nothing would be worse.
        $aarf->fresh()->update(['pdf_path' => null]);

        $mail = new RentalAssetAcknowledgedMail($aarf->fresh(['items', 'vendor', 'creator', 'acknowledger']));

        $this->assertStringStartsWith('%PDF', $mail->pdfBytes());
    }

    public function test_a_vendor_without_a_pic_email_still_reaches_it_and_finance(): void
    {
        Mail::fake();

        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => null]);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf);

        // A gap in the vendor master must not cost the two internal teams their copy.
        Mail::assertSent(RentalAssetAcknowledgedMail::class, 2);
    }

    public function test_a_mail_failure_never_rolls_back_the_acknowledgement(): void
    {
        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->acknowledge($actor, $vendor, $aarf)->assertRedirect();

        // The signature is the record; a mail outage must never undo one. The operator is
        // told the copy did not go out rather than being left to assume it did.
        $aarf->refresh();
        $this->assertTrue($aarf->isAcknowledged());
        $this->assertSame($actor->id, $aarf->acknowledged_by);
        $this->assertStringContainsString('could not be emailed', session('success'));
    }

    public function test_the_vendor_representatives_signature_does_not_send_the_copy(): void
    {
        Mail::fake();

        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->repAcknowledge($actor, $vendor, $aarf);

        // The document is not finished until WE acknowledge it. Circulating a half-signed
        // form would put an unfinished record in three inboxes.
        Mail::assertNothingSent();
    }

    // ── Numbering, scoping, access ───────────────────────────────────────────
    public function test_references_are_sequential_and_prefixed_by_direction(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Claritas Asia Sdn Bhd']);
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Enlinea Sdn Bhd']);

        $this->actingAs($this->itManager())->post(route('vendors.aarf.generate', $vendor));

        $refs = RentalAssetAcknowledgement::orderBy('id')->pluck('reference')->all();
        $year = now()->year;

        $this->assertSame(["RRA-{$year}-0001", "RRA-{$year}-0002"], $refs);
        // A return is numbered apart, so a reference alone says which way assets moved.
        $this->assertStringStartsWith("RTA-{$year}-", RentalAssetAcknowledgement::generateReference(RentalAssetAcknowledgement::TYPE_RETURN));
    }

    public function test_an_aarf_cannot_be_reached_through_another_vendors_url(): void
    {
        $owner = $this->vendor(['name' => 'Owner Rentals']);
        $other = $this->vendor(['name' => 'Other Rentals']);
        $this->rentalAsset($owner);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $owner));
        $aarf = RentalAssetAcknowledgement::first();

        // Both ids come from the URL. Without assertBelongs() this form would be signed,
        // printed and filed under a vendor it has nothing to do with.
        $this->actingAs($actor)->get(route('vendors.aarf.show', [$other, $aarf]))->assertNotFound();
        $this->acknowledge($actor, $other, $aarf)->assertNotFound();
        $this->actingAs($actor)->delete(route('vendors.aarf.destroy', [$other, $aarf]))->assertNotFound();
    }

    public function test_an_intern_cannot_generate_or_acknowledge(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $intern = User::factory()->create(['role' => 'it_intern']);

        $this->actingAs($intern)->post(route('vendors.aarf.generate', $vendor))->assertForbidden();

        $this->actingAs($this->itManager())->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();

        $this->actingAs($intern)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertForbidden();
        $this->acknowledge($intern, $vendor, $aarf)->assertForbidden();
    }

    public function test_finance_reaches_the_aarf_because_it_reaches_the_vendor_master(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $finance = User::factory()->create(['role' => 'finance_manager']);

        // canViewVendors/canManageVendors are one role set — whoever maintains the vendor
        // master signs its documents too.
        $this->actingAs($finance)->post(route('vendors.aarf.generate', $vendor))->assertRedirect();
        $this->actingAs($finance)
            ->get(route('vendors.aarf.show', [$vendor, RentalAssetAcknowledgement::first()]))
            ->assertOk();
    }

    // ── Rendering ────────────────────────────────────────────────────────────
    public function test_the_vendor_profile_shows_pending_assets_and_the_generated_form(): void
    {
        $vendor = $this->vendor();
        $asset = $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('Generate Receipt AARF')
            ->assertSee('awaiting acknowledgement');

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));

        $this->actingAs($actor)
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee(RentalAssetAcknowledgement::first()->reference)
            ->assertDontSee('Generate Receipt AARF');
    }

    public function test_the_form_page_lists_only_section_a_and_prefills_the_collector(): void
    {
        $vendor = $this->vendor();
        $asset = $this->rentalAsset($vendor, ['processor' => 'Intel i7-1365U']);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();

        $this->actingAs($actor)
            ->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->assertSee($aarf->reference)
            ->assertSee('Receipt of rental asset')
            ->assertSee($asset->asset_tag)
            ->assertSee($asset->serial_number)
            ->assertSee('Claritas Asia Sdn Bhd')
            // Section A only — spec fields belong to Section B and must not leak in.
            ->assertDontSee('Intel i7-1365U')
            // Asset Name was dropped from the table on request; the column is still
            // snapshotted on the item row, just not printed.
            ->assertDontSee('Asset Name');
    }

    public function test_the_pdf_is_stored_on_acknowledgement_and_is_downloadable(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();
        $this->acknowledge($actor, $vendor, $aarf);

        $aarf->refresh();
        $this->assertNotNull($aarf->pdf_path);
        Storage::disk('local')->assertExists($aarf->pdf_path);
        $this->assertStringStartsWith('rental_acknowledgements/', $aarf->pdf_path);

        $this->actingAs($actor)
            ->get(route('vendors.aarf.pdf', [$vendor, $aarf]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_flash_message_is_rendered_exactly_once(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);

        $content = $this->actingAs($this->itManager())
            ->followingRedirects()
            ->post(route('vendors.aarf.generate', $vendor))
            ->assertOk()
            ->getContent();

        // layouts/app.blade.php already renders success/error/info/warning for every page.
        // A view that renders them again shows the operator the same banner twice.
        $this->assertSame(1, substr_count($content, 'review and acknowledge it'));
    }

    public function test_the_form_sections_are_in_the_specified_order(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();

        $content = $this->actingAs($actor)
            ->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->getContent();

        // The order is the operator's spec, not a layout preference — a later edit that
        // moves Collector Details back above the remarks would silently break the form.
        $ordered = [
            'Report Details',          // Report Number + Type + Company Rented To + Vendor, merged
            'List of Assets',
            'Confirmation',
            'Condition Remarks',
            'Vendor Representative',
            'Collector Details',
            'Acknowledgement',
        ];

        $previous = -1;
        foreach ($ordered as $heading) {
            // Anchor to the section markup, not the bare words: "Acknowledgement" also
            // appears in the page title, and a plain strpos would match that instead.
            $at = strpos($content, '</span>'.$heading);
            $this->assertNotFalse($at, "Section missing from the form: {$heading}");
            $this->assertGreaterThan($previous, $at, "Section out of order: {$heading}");
            $previous = $at;
        }
    }

    public function test_a_draft_can_be_printed_before_it_is_signed(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));

        // The form is walked to the handover on paper, so it must print while still a draft.
        $this->actingAs($actor)
            ->get(route('vendors.aarf.pdf', [$vendor, RentalAssetAcknowledgement::first()]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }
}
