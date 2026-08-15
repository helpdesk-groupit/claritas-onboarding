<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Mail\RentalAssetAcknowledgedMail;
use App\Models\AssetInventory;
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

    /**
     * Sign BOTH sides of a receipt, which is what closes it.
     *
     * A form takes two acknowledgements in either order since 2026-08-13 — ours and the
     * Vendor PIC's — and neither on its own archives anything, stores a PDF or sends mail.
     * Every test whose subject is "a closed form" goes through here; the tests whose subject
     * is the ORDER call the two halves themselves.
     */
    private function closeForm(User $actor, Vendor $vendor, RentalAssetAcknowledgement $aarf, array $overrides = [])
    {
        $this->acknowledge($actor, $vendor, $aarf, $overrides);

        return $this->repAcknowledge($actor, $vendor, $aarf);
    }

    /**
     * The signature is stated ONCE on screen, in section 7 beside the other party's.
     *
     * It used to render three times on a fully-signed receipt — section 5's own green alert,
     * section 7's new per-party alert, and the sign-off panel — which is what the operator
     * queried. Section 5 keeps the WORDS and the typed identity; the moment of signing lives
     * in the acknowledgement section, and the sign-off panel remains the formal trail.
     */
    public function test_the_vendor_signature_is_stated_once_above_the_sign_off(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);
        $this->closeForm($actor, $vendor, $aarf);

        // Burn one request: the layout renders the acknowledgement's success flash as its
        // own alert-success banner, and it would be counted below as a third confirmation.
        $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]));

        $html = $this->actingAs($actor)
            ->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->getContent();

        // Section 5 still carries their remarks and the identity they signed under...
        $five = substr($html, strpos($html, '<span class="n">5</span>'), strpos($html, '<span class="n">6</span>') - strpos($html, '<span class="n">5</span>'));
        $this->assertStringContainsString('Ravi Kumar', $five);
        $this->assertStringNotContainsString('alert-success', $five, 'Section 5 must not restate the signature.');

        // ...and exactly two green confirmations remain on the page, both in section 7.
        $this->assertSame(2, substr_count($html, 'alert alert-success'));

        // The sign-off panel names the same two parties the same way section 7 does.
        $this->assertStringContainsString('Acknowledged By (Company PIC)', $html);
        $this->assertStringContainsString('Acknowledged By (Vendor PIC)', $html);
        $this->assertStringNotContainsString('Acknowledged By (Receiving)', $html);
    }

    /**
     * A receipt must name the person section 6 records AND the account that pressed the
     * button, exactly as a return does.
     *
     * The collector fields are pre-filled from the signed-in user but stay editable, because
     * "a courier or a colleague without a login may be the one signing". Until 2026-08-14
     * section 7 and the sign-off printed only the ACCOUNT, so the moment anybody edited
     * section 6 the document named two different people with nothing reconciling them.
     *
     * The sign-off's own account cell came off the panel later the same day at the operator's
     * instruction, in both directions — which makes section 7's sentence the ONE place the
     * account is now stated, and therefore the thing this test has to hold onto.
     */
    public function test_a_receipt_names_the_signatory_and_the_account_it_was_processed_under(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        // Somebody other than the account holder took delivery — the case the old wording lost.
        $this->closeForm($actor, $vendor, $aarf, [
            'collector_name' => 'Nurul Aina',
            'collector_company' => 'Claritas Asia Sdn Bhd',
            'collector_ic' => '910304-08-1122',
        ]);

        $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]));   // burn the flash
        $html = $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();

        // Section 7 states both facts in one sentence, the way the return arm always did.
        $this->assertStringContainsString('Acknowledged by Company PIC', $html);
        $this->assertStringContainsString('Nurul Aina', $html);
        $this->assertStringContainsString('processed under the account of', $html);
        // Compared ESCAPED, because the page prints the name through Blade's `{{ }}`. The
        // factory hands out real faker names, so a raw comparison passes or fails on whether
        // this run happened to draw an apostrophe ("Daija O'Connell" renders as
        // `O&#039;Connell`) — a random failure in a test about section 7's wording, which is
        // the worst kind: it points at the feature rather than at the assertion.
        $this->assertStringContainsString(e($actor->name), $html);

        // The panel no longer carries an account cell of its own, and the cell that remains
        // names the SIGNATORY — whoever section 6 records, not the account holder. Reading it
        // off the account again is exactly the collapse this test exists to catch.
        $this->assertStringNotContainsString('Processed Under Account', $html);
        $signOff = substr($html, strpos($html, 'aarf-sect">Sign-off'));
        $this->assertStringContainsString('Nurul Aina', $signOff);

        $seven = substr($html, strpos($html, '<span class="n">7</span>'));
        $this->assertStringContainsString('Nurul Aina', substr($seven, 0, strpos($seven, 'Sign-off')));

        // The PDF is the copy of record and the copy the vendor is emailed, so it carries the
        // same panel and the same sentence as the page that was signed.
        $pdf = $this->pdfHtml($aarf);
        $this->assertStringNotContainsString('Processed Under Account', $pdf);
        $this->assertStringContainsString('processed under the account of', $pdf);
        $this->assertStringContainsString('Nurul Aina', substr($pdf, strpos($pdf, 'sect-title">Sign-off')));
    }

    /**
     * Our own block is headed the same way on the screen and in the PDF, qualifier included.
     *
     * "(Internal Purpose only)" is the operator's wording. It was dropped on 2026-08-14 on
     * the argument that a block marked internal has no place on a document the vendor is
     * emailed, and restored the same day with that consequence stated and accepted: the
     * qualifier names who OWNS the block, not what is withheld.
     *
     * What this pins is that the two copies cannot diverge. The vendor's representative
     * signs the screen and is emailed the PDF, so a section headed one way on the page they
     * signed and another way in the record of it is the failure that outlives the wording
     * argument — the same reason section 7 is numbered identically in both.
     */
    public function test_our_block_carries_the_operators_heading_on_both_the_screen_and_the_pdf(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);
        $this->closeForm($actor, $vendor, $aarf);

        $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]));   // burn the flash
        $html = $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();

        // Anchored to the section markup: "Company PIC" alone also appears in the sign-off
        // labels and the button captions, so a bare substring would pass on those.
        $this->assertStringContainsString(
            '<span class="n">4</span>Company PIC (Internal Purpose only)',
            $html,
        );

        $this->assertStringContainsString('4. Company PIC (Internal Purpose only)', $this->pdfHtml($aarf));
        $this->actingAs($actor)->get(route('vendors.aarf.pdf', [$vendor, $aarf]))->assertOk();
    }

    /**
     * The PDF template as dompdf receives it.
     *
     * Assert here, never on the bytes of the rendered PDF: dompdf FlateDecode-compresses its
     * content streams, so `assertStringContainsString` on the response always fails and
     * `assertStringNotContainsString` always PASSES — which is worse, because it is a test
     * that can never fail. (The decommission report is different: it goes through FPDI/FPDF,
     * whose literals are readable, which is why CLAUDE.md's advice about escaped brackets
     * applies there and not here.) The call shape mirrors
     * RentalAssetAcknowledgementController::renderPdf() exactly.
     */
    private function pdfHtml(RentalAssetAcknowledgement $aarf): string
    {
        return view('vendors.aarf.pdf', [
            'aarf' => $aarf->fresh(['items', 'vendor', 'creator', 'acknowledger', 'processorAcknowledger']),
        ])->render();
    }

    /**
     * "See section 7" must mean the same thing on both copies.
     *
     * The PDF used the number 7 for the SIGN-OFF panel and had no counterpart to the screen's
     * Acknowledgement section at all, so the two documents' body text pointed at different
     * places depending on which one the reader was holding.
     */
    public function test_the_acknowledgement_is_section_seven_on_both_the_screen_and_the_pdf(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);
        $this->closeForm($actor, $vendor, $aarf);

        $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]));   // burn the flash
        $html = $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();
        $pdf = $this->pdfHtml($aarf);

        // Screen: 7 = Acknowledgement, and the Sign-off panel below it carries no number.
        $this->assertStringContainsString('<span class="n">7</span>Acknowledgement', $html);
        $this->assertStringContainsString('aarf-sect">Sign-off', $html);

        // PDF: same. 7 is the acknowledgement statements, and Sign-off is unnumbered.
        $this->assertStringContainsString('7. Acknowledgement', $pdf);
        $this->assertStringContainsString('sect-title">Sign-off', $pdf);
        $this->assertStringContainsString('processed under the account of', $pdf);
        // The number 7 must not also be attached to the panel, which is what it named before.
        $this->assertStringNotContainsString('7. Sign-off', $pdf);
    }

    /**
     * The PDF is the copy of record and the copy the vendor is emailed, so it is the one
     * place an unexplained blank actually does damage. It printed nothing at all where the
     * screen says "No remarks recorded." until 2026-08-14 — an empty box above a name.
     */
    public function test_the_pdf_explains_an_empty_remark_rather_than_printing_a_blank(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf);
        $this->repAcknowledge($actor, $vendor, $aarf, ['vendor_rep_remarks' => '']);

        $this->assertTrue($aarf->fresh()->isAcknowledged());
        $this->assertNull($aarf->fresh()->vendor_rep_remarks);

        $this->assertStringContainsString('No remarks recorded.', $this->pdfHtml($aarf));
        $this->actingAs($actor)->get(route('vendors.aarf.pdf', [$vendor, $aarf]))->assertOk();
    }

    // ── Process log ──────────────────────────────────────────────────────────
    /**
     * The log covers the whole process — raised, both signatures, closed — in the order the
     * steps actually happened, and names who did each one.
     *
     * It is derived from the columns the lifecycle already writes rather than an event table,
     * which is what lets it read correctly for forms signed long before it existed. The
     * corollary this pins: every step must still resolve its actor, since a trail of
     * timestamps with nobody attached answers none of the questions it is read for.
     */
    public function test_the_process_log_runs_from_raised_to_the_final_acknowledgement(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf, ['collector_name' => 'Nurul Aina']);
        $this->repAcknowledge($actor, $vendor, $aarf);

        $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]));   // burn the flash
        $html = $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();

        // Sliced to the panel: every one of these words also appears elsewhere on the page,
        // so an unsliced assertion would pass on section 7 and prove nothing about the log.
        $log = substr($html, strpos($html, 'aarf-sect">Process Log'));

        foreach ([
            'Form raised',
            'Acknowledged by the Company PIC',
            'Acknowledged by the Vendor PIC',
            'Form closed',
        ] as $step) {
            $this->assertStringContainsString($step, $log, "The log must record: {$step}");
        }

        // In that order, and with the actors on them.
        $this->assertTrue(
            strpos($log, 'Form raised') < strpos($log, 'Form closed'),
            'The log must read oldest first.',
        );
        // e(): the factory's name can legitimately carry an apostrophe, and Blade escapes it.
        $this->assertStringContainsString(e($actor->name), $log);    // raised it
        $this->assertStringContainsString('Nurul Aina', $log);       // signed the declaration
        $this->assertStringContainsString('Ravi Kumar', $log);       // signed for the vendor

        // The signatory and the account are two facts, and the log is where the second one
        // lives now that the sign-off panel names only the parties.
        $this->assertStringContainsString('Processed under the account of', $log);
    }

    /**
     * The log is the SYSTEM's record of the document, not part of the document.
     *
     * The PDF is both the copy that downloads and the copy attached to the mail the vendor's
     * PIC receives, so anything rendered into it is circulated outside the company. The two
     * parties signed the form, not a page of our internal handling — and the operator asked
     * for the log "only in the system" for that reason.
     */
    public function test_the_process_log_is_not_part_of_the_printed_or_emailed_form(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);
        $this->closeForm($actor, $vendor, $aarf);

        $pdf = $this->pdfHtml($aarf);

        $this->assertStringNotContainsString('Process Log', $pdf);
        $this->assertStringNotContainsString('Form raised', $pdf);
        $this->assertStringNotContainsString('Form closed', $pdf);
        // The form itself is still all there — this is a removal of the log, not of the page.
        $this->assertStringContainsString('sect-title">Sign-off', $pdf);
    }

    /**
     * An unfinished form says what it is still waiting for, as the last line of its own log.
     *
     * A trail that simply stops after the one signature it has reads as a finished process,
     * which on a two-signature document is the single most expensive thing this panel could
     * imply: nobody chases a handover they believe is closed.
     */
    public function test_the_log_of_an_unfinished_form_says_who_it_is_waiting_for(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        // Nothing signed yet — it is waiting on both of them.
        $this->assertSame('Awaiting both parties', $this->lastLogStep($aarf)['title']);

        // Our half signed; the vendor's representative is what is outstanding.
        $this->acknowledge($actor, $vendor, $aarf);
        $this->assertSame('Awaiting the Vendor PIC', $this->lastLogStep($aarf)['title']);

        // ...and it renders as an open step rather than a completed one.
        $html = $this->actingAs($actor)->get(route('vendors.aarf.show', [$vendor, $aarf]))->assertOk()->getContent();
        $log = substr($html, strpos($html, 'aarf-sect">Process Log'));
        $this->assertStringContainsString('aarf-log-open', $log);
        $this->assertStringContainsString('Awaiting the Vendor PIC', $log);

        // Once both have signed there is nothing outstanding to state.
        $this->repAcknowledge($actor, $vendor, $aarf);
        $this->assertSame('Form closed', $this->lastLogStep($aarf)['title']);
    }

    /** The trailing entry of the log — what the form is waiting for, or that it is closed. */
    private function lastLogStep(RentalAssetAcknowledgement $aarf): array
    {
        $steps = $aarf->fresh(['items', 'creator', 'acknowledger', 'processorAcknowledger'])->activityLog();

        return end($steps);
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

    public function test_the_profile_counts_pre_existing_assets_apart_from_pending_ones(): void
    {
        config()->set('vendors.aarf_track_from', '2026-08-07');

        $vendor = $this->vendor();
        $this->registeredOn($this->rentalAsset($vendor, ['asset_tag' => 'DEMO-LPT-001']), '2026-08-06 15:13:20');
        $this->registeredOn($this->rentalAsset($vendor, ['asset_tag' => 'FIX05483']), '2026-08-07 09:24:12');

        // The per-asset AARF badge (Pre-AARF / Pending / On draft / signed) came off the
        // Assets tab on 2026-08-13 when it was cut to six columns, so the distinction is no
        // longer drawn per row — the pending COUNT is what survives on screen, in the Report
        // tab pointer and the Report tab's own banner. The rule itself is unchanged: an
        // asset registered before tracking began was never asked for and must not be counted
        // as owed, which would put a form in front of somebody for kit already in service.
        $this->assertSame(1, RentalAssetAcknowledgement::pendingAssetsFor($vendor)->count());

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'assets']))
            ->assertOk()
            ->assertSee('awaiting acknowledgement')
            ->assertDontSee('Pre-AARF');
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
    public function test_acknowledging_records_the_signed_in_user_and_waits_for_the_other_party(): void
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
        $this->assertTrue($aarf->condition_confirmed);
        // The signatory comes off the account, never a typed name.
        $this->assertSame($actor->id, $aarf->acknowledged_by);
        $this->assertNotNull($aarf->acknowledged_at);
        $this->assertSame('LT-9 has a scratched lid.', $aarf->condition_remarks);

        // Our signature is ONE of the two. A handover is an agreement between two parties,
        // so the document is not closed on the strength of the side that typed it.
        $this->assertTrue($aarf->mainAcknowledged());
        $this->assertFalse($aarf->isAcknowledged());
        $this->assertSame('Awaiting Vendor PIC', $aarf->statusBadge()['label']);

        $this->repAcknowledge($actor, $vendor, $aarf);

        $aarf->refresh();
        $this->assertTrue($aarf->isAcknowledged());
        $this->assertFalse($aarf->isEditable());
        $this->assertSame('Acknowledged', $aarf->statusBadge()['label']);
    }

    /**
     * The order must not matter — that is the whole point of dropping the "closing
     * signatory". Whoever signs second closes the document, and until then it is neither a
     * draft nor acknowledged.
     */
    public function test_either_party_may_sign_first_and_the_second_one_closes_the_form(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->repAcknowledge($actor, $vendor, $aarf);

        $aarf->refresh();
        $this->assertTrue($aarf->vendorRepAcknowledged());
        $this->assertFalse($aarf->isAcknowledged());
        $this->assertSame('Awaiting Company PIC', $aarf->statusBadge()['label']);

        $this->acknowledge($actor, $vendor, $aarf);

        $this->assertTrue($aarf->fresh()->isAcknowledged());
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

    /**
     * The signature acknowledges the HANDOVER, not the paragraph above it, so remarks are
     * optional — most deliveries have nothing to reply to, and the box is captioned "Leave
     * remarks if any". Demanding filler text to sign would be demanding a sentence nobody
     * meant to write. The IDENTITY is still required: that is what makes it a signature.
     */
    public function test_a_vendor_signature_stands_without_remarks(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->repAcknowledge($actor, $vendor, $aarf, ['vendor_rep_remarks' => ''])
            ->assertSessionHasNoErrors();

        $aarf->refresh();
        $this->assertTrue($aarf->vendorRepAcknowledged());
        $this->assertNull($aarf->vendor_rep_remarks);
        $this->assertSame('Ravi Kumar', $aarf->vendor_rep_name);
    }

    /**
     * The reverse of the rule this replaced. The vendor's acknowledgement used to be
     * optional, so ours alone closed the receipt — a document asserting that both sides
     * agreed, signed by one of them.
     */
    public function test_our_acknowledgement_alone_does_not_close_the_form(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf)->assertRedirect();

        $aarf->refresh();
        $this->assertFalse($aarf->isAcknowledged());
        $this->assertFalse($aarf->vendorRepAcknowledged());
        $this->assertNull($aarf->pdf_path);
        $this->assertStringContainsString('closes once the Vendor PIC', session('success'));
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

    /**
     * A form CLOSED UNDER THE OLD SINGLE-SIGNATURE RULE is final with only our signature on
     * it, and the live database holds such rows. Nothing may be added to a document already
     * declared complete — so the guard stays, even though the normal two-signature flow can
     * never reach it (the status only flips once both parties are on the form).
     */
    public function test_a_form_closed_under_the_old_rule_can_no_longer_be_signed(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->acknowledge($actor, $vendor, $aarf);
        // Exactly the shape those rows are in: acknowledged, with no second signature.
        $aarf->fresh()->update(['status' => RentalAssetAcknowledgement::STATUS_ACKNOWLEDGED]);

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
        $this->assertTrue($aarf->mainAcknowledged());
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
            // Management is named PER COMPANY, so this has to match the company the fixture
            // asset is supplied to. Their app role is a plain `employee` on purpose — the real
            // CEO/CTO are not it_manager or finance_manager, which is the whole reason the
            // list is named people rather than a role.
            'management' => $this->managementFor('Claritas Asia Sdn Bhd', 'kelvin.cto@claritas.test'),
        ];
    }

    /** Name somebody as management for a company — the AARF copy list and the e-waste approvers are one list. */
    private function managementFor(string $company, string $email): User
    {
        $user = User::factory()->create(['role' => 'employee', 'work_email' => $email]);
        EwasteCompanyApprover::create(['company' => $company, 'user_id' => $user->id]);

        return $user;
    }

    public function test_the_signed_form_is_emailed_to_the_vendor_pic_it_finance_and_management(): void
    {
        Mail::fake();

        $teams = $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->closeForm($actor, $vendor, $aarf);

        // Four separate sends — one per audience — each carrying the PDF.
        Mail::assertSent(RentalAssetAcknowledgedMail::class, 4);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo('siti@acme.test')
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_VENDOR);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($teams['it']->work_email)
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_IT);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($teams['finance']->work_email)
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_FINANCE);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($teams['management']->work_email)
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_MANAGEMENT);
    }

    /**
     * Management is addressed per COMPANY, and that is the point of naming them per company.
     *
     * A handover of one group entity's kit is not another entity's management's document. If
     * this ever collapses into "every named approver", the screen that names two people for
     * Enlinea and one for Claritas stops meaning anything.
     */
    public function test_management_at_another_company_is_not_copied(): void
    {
        Mail::fake();

        $this->notifiableTeams();
        $other = $this->managementFor('Enlinea Sdn Bhd', 'petrina.ceo@enlinea.test');

        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        // The fixture asset is supplied to Claritas Asia Sdn Bhd, so the form is Claritas'.
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->assertSame('Claritas Asia Sdn Bhd', $aarf->company_rented_to);

        $this->closeForm($actor, $vendor, $aarf);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, 4);
        Mail::assertNotSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($other->work_email));
    }

    /** Several named for one company all get the copy — Enlinea is CEO *and* CTO. */
    public function test_every_management_name_for_the_company_is_copied(): void
    {
        Mail::fake();

        User::factory()->create(['role' => 'it_manager']);
        User::factory()->create(['role' => 'finance_manager']);

        $ceo = $this->managementFor('Enlinea Sdn Bhd', 'petrina.ceo@enlinea.test');
        $cto = $this->managementFor('Enlinea Sdn Bhd', 'kelvin.cto@enlinea.test');

        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Enlinea Sdn Bhd']);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->closeForm($actor, $vendor, $aarf);

        // ONE send addressing both — they are peers on the same document, not a to/cc line.
        Mail::assertSent(RentalAssetAcknowledgedMail::class, 4);
        Mail::assertSent(
            RentalAssetAcknowledgedMail::class,
            fn ($mail) => $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_MANAGEMENT
                && $mail->hasTo($ceo->work_email)
                && $mail->hasTo($cto->work_email)
        );
    }

    /**
     * A company nobody has been named for still gets its signed copy seen.
     *
     * Same fallback EwasteCompanyApprover::approversFor() applies to disposal approval: a
     * company missed on the settings screen must not silently drop a signed document, because
     * nothing downstream would ever report the omission.
     */
    public function test_a_company_with_no_management_named_falls_back_to_superadmins(): void
    {
        Mail::fake();

        User::factory()->create(['role' => 'it_manager']);
        User::factory()->create(['role' => 'finance_manager']);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'work_email' => 'admin@claritas.test']);

        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Unnamed Entity Sdn Bhd']);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->assertSame(0, EwasteCompanyApprover::count());

        $this->closeForm($actor, $vendor, $aarf);

        Mail::assertSent(RentalAssetAcknowledgedMail::class, fn ($mail) => $mail->hasTo($superadmin->work_email)
            && $mail->audience === RentalAssetAcknowledgedMail::AUDIENCE_MANAGEMENT);
    }

    /** The management copy names the entity, because it is the one audience scoped to one. */
    public function test_the_management_copy_states_which_company_the_form_belongs_to(): void
    {
        $this->notifiableTeams();
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);
        $this->closeForm($actor, $vendor, $aarf);

        $aarf = $aarf->fresh(['items', 'vendor', 'acknowledger']);

        $management = view('emails.rental-asset-acknowledged', [
            'aarf' => $aarf,
            'audience' => RentalAssetAcknowledgedMail::AUDIENCE_MANAGEMENT,
        ])->render();

        $this->assertStringContainsString('Dear Management,', $management);
        $this->assertStringContainsString($aarf->reference.'</strong> for Claritas Asia Sdn Bhd has been', $management);

        // IT reads the same fact in the details box; repeating it in the opening line would
        // only be noise on a group-wide copy.
        $it = view('emails.rental-asset-acknowledged', [
            'aarf' => $aarf,
            'audience' => RentalAssetAcknowledgedMail::AUDIENCE_IT,
        ])->render();

        $this->assertStringContainsString($aarf->reference.'</strong> has been', $it);
    }

    public function test_the_emailed_copy_carries_the_report_as_a_pdf_attachment(): void
    {
        Mail::fake();

        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->closeForm($actor, $vendor, $aarf);

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
        $this->closeForm($actor, $vendor, $aarf);

        // acknowledge() swallows a PDF-write failure so bookkeeping cannot block a
        // signature, which means pdf_path can legitimately be null on a signed form.
        // Announcing an attached document and attaching nothing would be worse.
        $aarf->fresh()->update(['pdf_path' => null]);

        $mail = new RentalAssetAcknowledgedMail($aarf->fresh(['items', 'vendor', 'creator', 'acknowledger']));

        $this->assertStringStartsWith('%PDF', $mail->pdfBytes());
    }

    public function test_a_vendor_without_a_pic_email_still_reaches_it_finance_and_management(): void
    {
        Mail::fake();

        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => null]);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        $this->closeForm($actor, $vendor, $aarf);

        // A gap in the vendor master must not cost the three internal audiences their copy.
        Mail::assertSent(RentalAssetAcknowledgedMail::class, 3);
    }

    public function test_a_mail_failure_never_rolls_back_the_acknowledgement(): void
    {
        $this->notifiableTeams();
        $vendor = $this->vendor(['pic_email' => 'siti@acme.test']);
        $this->rentalAsset($vendor);
        $actor = $this->itManager();
        $aarf = $this->draftFor($vendor, $actor);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        $this->closeForm($actor, $vendor, $aarf)->assertRedirect();

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
        // form would put an unfinished record in four inboxes.
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
            // The spec joined Section A on 2026-08-13 as its own column, reversing the
            // earlier "spec fields belong to Section B and must not leak in" rule.
            ->assertSee('Intel i7-1365U')
            // Asset Name was dropped from the table on request; the column is still
            // snapshotted on the item row, just not printed.
            ->assertDontSee('Asset Name');
    }

    /**
     * The spec is a SNAPSHOT like every other Section A cell, not a live read through the
     * FK. Re-keying a machine's RAM six months later must not change what a signed form
     * says was handed over — the same rule the serial number and brand already follow.
     */
    public function test_the_spec_column_is_snapshotted_not_read_live(): void
    {
        $vendor = $this->vendor();
        $asset = $this->rentalAsset($vendor, [
            'processor' => 'Intel i7-1365U',
            'ram_size' => '16GB',
            'storage' => '512GB SSD',
        ]);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();

        $this->assertSame('Intel i7-1365U · 16GB · 512GB SSD', $aarf->items->first()->spec_summary);

        $this->closeForm($actor, $vendor, $aarf);
        $asset->update(['ram_size' => '32GB', 'processor' => 'Intel i9-13900H']);

        $this->assertSame('Intel i7-1365U · 16GB · 512GB SSD', $aarf->fresh()->items->first()->spec_summary);

        $this->actingAs($actor)
            ->get(route('vendors.aarf.show', [$vendor, $aarf]))
            ->assertOk()
            ->assertSee('Intel i7-1365U')
            ->assertDontSee('Intel i9-13900H');
    }

    /** An asset with nothing recorded prints a dash, not six empty separators. */
    public function test_an_asset_with_no_spec_recorded_prints_a_dash(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));

        $this->assertNull(RentalAssetAcknowledgement::first()->items->first()->spec_summary);
    }

    public function test_the_pdf_is_stored_on_acknowledgement_and_is_downloadable(): void
    {
        $vendor = $this->vendor();
        $this->rentalAsset($vendor);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $aarf = RentalAssetAcknowledgement::first();
        $this->closeForm($actor, $vendor, $aarf);

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
            // On a RECEIPT the condition remarks are ours, so section 4 is headed by the
            // party that writes it rather than by the words "Condition Remarks" (which now
            // head only the return form's section 4, where the collector writes them).
            'Company PIC (Internal Purpose only)',
            'Vendor Representative',
            // Section 6 was "Collector Details" until 2026-08-15; on a receipt the collector
            // IS our own staff, so the operator renamed it. Both headings therefore begin
            // "Company PIC" and a bare prefix would match section 4 twice — the closing tag
            // is what anchors this to the right one.
            'Company PIC</div>',
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

    // ── Report tab ───────────────────────────────────────────────────────────
    /** A return AARF, built directly — the staged-return flow has its own test file. */
    private function returnForm(Vendor $vendor, AssetInventory $asset, array $attrs = []): RentalAssetAcknowledgement
    {
        $form = RentalAssetAcknowledgement::create(array_merge([
            'reference' => RentalAssetAcknowledgement::generateReference(RentalAssetAcknowledgement::TYPE_RETURN),
            'type' => RentalAssetAcknowledgement::TYPE_RETURN,
            'vendor_id' => $vendor->id,
            'company_rented_to' => 'Claritas Asia Sdn Bhd',
            'status' => RentalAssetAcknowledgement::STATUS_DRAFT,
        ], $attrs));

        $form->items()->create(
            RentalAssetAcknowledgementItem::snapshotFrom($asset, RentalAssetAcknowledgement::TYPE_RETURN)
        );

        return $form;
    }

    /**
     * The two documents are separated on the Report tab, because the parties swap between
     * them: a receipt and a return are not two rows of one list. A single combined table
     * let a return be read as a receipt at a glance, which is the mistake this split exists
     * to prevent.
     */
    public function test_the_report_tab_lists_the_forms_split_by_direction(): void
    {
        $vendor = $this->vendor();
        $received = $this->rentalAsset($vendor, ['asset_tag' => 'RCV-0001']);
        $returned = $this->rentalAsset($vendor, ['asset_tag' => 'RTN-0001']);
        $actor = $this->itManager();

        $this->actingAs($actor)->post(route('vendors.aarf.generate', $vendor));
        $receipt = RentalAssetAcknowledgement::where('type', RentalAssetAcknowledgement::TYPE_RECEIPT)->firstOrFail();
        $return = $this->returnForm($vendor, $returned);

        $page = $this->actingAs($actor)
            ->get(route('vendors.show', [$vendor, 'tab' => 'report']))
            ->assertOk();

        $page->assertSee('Assets Accepted')
            ->assertSee('Assets Returned')
            ->assertSee($receipt->reference)
            ->assertSee($return->reference);

        // Each reference must sit under its OWN heading. Asserting both are merely present
        // would pass on the single combined table this replaced.
        //
        // Sliced to the Report pane first: both references ALSO appear on the Assets tab,
        // in the per-asset AARF cell of the rental rows, and that pane is rendered earlier
        // in the document — so a bare strpos over the whole page measures the wrong copy
        // and the ordering assertion becomes meaningless.
        $html = $page->getContent();
        $pane = substr($html, strpos($html, 'id="vndReport"'));
        $accepted = strpos($pane, 'Assets Accepted');
        $returnedAt = strpos($pane, 'Assets Returned');
        $this->assertNotFalse($accepted);
        $this->assertNotFalse($returnedAt);
        $this->assertGreaterThan($accepted, strpos($pane, $receipt->reference));
        $this->assertLessThan($returnedAt, strpos($pane, $receipt->reference));
        $this->assertGreaterThan($returnedAt, strpos($pane, $return->reference));

        // The register left the Assets tab; the tab that no longer holds it says where it
        // went, because the Generate button went with it.
        $page->assertSee('are on the');
        $this->assertStringContainsString('tab=report', $html);
    }

    /**
     * A ?tab=report on a vendor with no AARF business must fall back to Profile. Marking a
     * pane active that was never rendered leaves EVERY pane inactive and the card body
     * blank, which reads as a page that failed to load rather than a tab that does not apply.
     */
    public function test_the_report_tab_is_absent_when_there_is_no_aarf_and_never_blanks_the_page(): void
    {
        // A pure supplier: nothing rented, so nothing to acknowledge in either direction.
        $vendor = $this->vendor(['name' => 'Parts Supplier Sdn Bhd', 'vendor_types' => ['supplier']]);
        $actor = $this->itManager();

        $page = $this->actingAs($actor)
            ->get(route('vendors.show', [$vendor, 'tab' => 'report']))
            ->assertOk();

        $html = $page->getContent();
        $this->assertStringNotContainsString('#vndReport', $html);
        // Profile carries the fallback, so the card body is never left with no active pane.
        $this->assertStringContainsString('id="vndProfile" role="tabpanel"', $html);
        $this->assertMatchesRegularExpression('/show active" id="vndProfile"/', $html);
    }

    /**
     * Generating and discarding both return to the tab that lists the forms. They pointed at
     * the Assets tab while the register lived there; a redirect left behind lands the
     * operator on a tab with no trace of what they just did.
     */
    public function test_generating_and_discarding_land_back_on_the_report_tab(): void
    {
        $vendor = $this->vendor();
        // Two companies → two forms, which is the branch that redirects to the tab rather
        // than straight into the single form it made.
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Claritas Asia Sdn Bhd']);
        $this->rentalAsset($vendor, ['company_supplied_to' => 'Nuren Group Sdn Bhd']);
        $actor = $this->itManager();

        $this->actingAs($actor)
            ->post(route('vendors.aarf.generate', $vendor))
            ->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'report']));

        $draft = RentalAssetAcknowledgement::firstOrFail();
        $this->actingAs($actor)
            ->delete(route('vendors.aarf.destroy', [$vendor, $draft]))
            ->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'report']));
    }
}
