<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Mail\EwasteAwardMail;
use App\Mail\EwasteCyclePostponedMail;
use App\Mail\EwasteInspectionReminderMail;
use App\Mail\EwasteManagementApprovalMail;
use App\Mail\EwasteQuotationApprovalMail;
use App\Mail\EwasteRfqMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\Company;
use App\Models\DisposedAsset;
use App\Models\EwasteCompanyApprover;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\DecommissionNotification;
use App\Services\EwasteInspectionReminderService;
use App\Services\EwasteSweepService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The refined e-waste decommissioning flow, phase by phase.
 *
 *   1. Mark Not Good — with a mandatory reason
 *   2. Inspection — a separate, later act
 *   3. Quarterly reminders
 *   4. Sweep gate + one cycle per company
 *   5. Multi-vendor quotations + Finance/management approval
 *   6. Collection, final report, archive
 *
 * EwasteCycleTest covers the pre-refinement cycle mechanics that still stand.
 */
class EwasteDecommissionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Section D/E of the asset form is only rendered and validated for this set. */
    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function asset(array $attrs = []): AssetInventory
    {
        return AssetInventory::factory()->create(array_merge([
            'asset_category' => 'IT Equipment',   // the factory leaves this null; the form requires it
            'asset_condition' => 'good',
            'status' => 'available',
            'ownership_type' => 'company',
        ], $attrs));
    }

    /** The minimum an asset-form submit needs to pass validation. */
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

    // ── Phase 1 — Mark Not Good, with a mandatory reason ──────────────────────

    public function test_writing_an_asset_off_without_a_reason_is_refused(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'asset_condition' => 'not_good',
                'status' => 'unavailable',
            ]))
            ->assertSessionHasErrors('decommission_reason');

        // Nothing may reach the Decommissioning queue unexplained: the RFQ, the Finance
        // approval and the final report all quote this reason and cannot invent it later.
        $this->assertDatabaseMissing('dispose_assets', ['asset_inventory_id' => $asset->id]);
        $this->assertSame('good', $asset->fresh()->asset_condition);
    }

    public function test_writing_an_asset_off_with_a_reason_stages_it_carrying_that_reason(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'asset_condition' => 'not_good',
                'status' => 'unavailable',
                'decommission_reason' => 'Motherboard failure, beyond economical repair',
                'ewaste_completeness' => 'complete',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dispose_assets', [
            'asset_inventory_id' => $asset->id,
            'decommission_type' => 'e_waste',
            'reason' => 'Motherboard failure, beyond economical repair',
        ]);
    }

    public function test_a_rental_return_still_needs_no_reason(): void
    {
        // "Contract end" is the usual case and the return AARF carries the narrative with
        // the collector's signature, so requiring one here would be paperwork for its own sake.
        $asset = $this->asset(['ownership_type' => 'rental']);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'ownership_type' => 'rental',
                'asset_condition' => 'returned',
                'status' => 'unavailable',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dispose_assets', [
            'asset_inventory_id' => $asset->id,
            'decommission_type' => 'vendor_return',
        ]);
    }

    public function test_the_reason_survives_onto_the_queue_row_when_an_asset_is_re_saved(): void
    {
        // Opening a legacy Not Good asset and saving is how rows staged before the rule
        // existed acquire a reason — the form pre-fills what is stored and now demands one.
        $asset = $this->asset(['asset_condition' => 'not_good', 'status' => 'unavailable']);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'asset_condition' => 'not_good',
                'status' => 'unavailable',
                'decommission_reason' => 'End of life — 8 years old',
                'ewaste_completeness' => 'complete',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'End of life — 8 years old',
            DisposedAsset::where('asset_inventory_id', $asset->id)->value('reason')
        );
    }

    // ── Phase 2 — Inspection, a separate later act ────────────────────────────

    private function company(string $name = 'Claritas Asia Sdn Bhd'): Company
    {
        return Company::firstOrCreate(['name' => $name]);
    }

    /** A queued e-waste asset, staged but not yet inspected. */
    private function queued(array $attrs = []): DisposedAsset
    {
        $asset = $this->asset(['asset_condition' => 'not_good', 'status' => 'unavailable']);

        return DisposedAsset::create(array_merge([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'reason' => 'Motherboard failure',
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ], $attrs));
    }

    public function test_a_freshly_queued_asset_is_not_inspected(): void
    {
        // The whole gate rests on this: before 2026-08-13 staging defaulted completeness to
        // 'complete', so an asset nobody had opened was indistinguishable from one checked
        // and found intact, and there was nothing for the quarterly cycle to refuse.
        $row = $this->queued();

        $this->assertFalse($row->isInspected());
        $this->assertNull($row->ewaste_completeness);
        $this->assertFalse($row->isReadyForCycle());
    }

    public function test_an_inspection_records_the_verdict_the_owner_and_who_looked(): void
    {
        $this->company();
        $row = $this->queued();
        $it = $this->itManager();

        $this->actingAs($it)
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'complete',
                'company' => 'Claritas Asia Sdn Bhd',
            ])
            ->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertTrue($row->isInspected());
        $this->assertSame('complete', $row->ewaste_completeness);
        $this->assertSame('Claritas Asia Sdn Bhd', $row->company);
        $this->assertSame($it->id, $row->inspected_by);
        $this->assertTrue($row->isReadyForCycle());
    }

    public function test_an_incomplete_verdict_cannot_be_recorded_without_naming_the_parts(): void
    {
        $this->company();
        $row = $this->queued();

        $this->actingAs($this->itManager())
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'incomplete',
                'ewaste_parts_removed' => '',
                'company' => 'Claritas Asia Sdn Bhd',
            ])
            ->assertSessionHasErrors('ewaste_parts_removed');

        // "Something is missing, but not what" cannot be priced by the vendor.
        $this->assertFalse($row->fresh()->isInspected());
    }

    public function test_switching_a_verdict_back_to_complete_drops_the_parts_list(): void
    {
        $this->company();
        $row = $this->queued();
        $it = $this->itManager();

        $this->actingAs($it)->post(route('decommission.inspect', $row), [
            'ewaste_completeness' => 'incomplete',
            'ewaste_parts_removed' => 'Battery, RAM',
            'company' => 'Claritas Asia Sdn Bhd',
        ])->assertSessionHasNoErrors();

        $this->actingAs($it)->post(route('decommission.inspect', $row), [
            'ewaste_completeness' => 'complete',
            'company' => 'Claritas Asia Sdn Bhd',
        ])->assertSessionHasNoErrors();

        // A machine declared intact must not still list parts taken off it.
        $this->assertNull($row->fresh()->ewaste_parts_removed);
    }

    public function test_the_owning_company_must_be_a_registered_one(): void
    {
        $this->company();
        $row = $this->queued();

        $this->actingAs($this->itManager())
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'complete',
                'company' => 'Claritas Asia Sdn. Bhd.',   // near-miss of the registered name
            ])
            ->assertSessionHasErrors('company');

        // The company decides which management approver may authorise the disposal, so a
        // fuzzy match is not a good enough basis to record one.
        $this->assertFalse($row->fresh()->isInspected());
    }

    public function test_an_asset_already_in_a_cycle_can_no_longer_be_re_inspected(): void
    {
        $this->company();
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
        ]);
        $row = $this->queued([
            'ewaste_completeness' => 'complete', 'inspected_at' => now(),
            'decommission_batch_id' => $batch->id,
        ]);

        $this->actingAs($this->itManager())
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'incomplete',
                'ewaste_parts_removed' => 'Battery',
                'company' => 'Claritas Asia Sdn Bhd',
            ])
            ->assertSessionHas('error');

        // The vendor has already been asked to quote against what was recorded.
        $this->assertSame('complete', $row->fresh()->ewaste_completeness);
    }

    public function test_a_returned_rental_asset_is_not_inspected_here(): void
    {
        $this->company();
        $row = $this->queued(['decommission_type' => 'vendor_return', 'asset_condition' => 'returned']);

        $this->actingAs($this->itManager())
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'complete',
                'company' => 'Claritas Asia Sdn Bhd',
            ])
            ->assertSessionHas('error');

        $this->assertFalse($row->fresh()->isInspected());
    }

    public function test_a_role_without_the_decommission_gate_cannot_inspect(): void
    {
        $this->company();
        $row = $this->queued();

        $this->actingAs(User::factory()->create(['role' => 'it_intern']))
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'complete',
                'company' => 'Claritas Asia Sdn Bhd',
            ])
            ->assertForbidden();
    }

    public function test_an_it_executive_may_inspect_even_though_they_cannot_mark_not_good(): void
    {
        // canEditAllAssetSections() excludes it_executive, so they cannot set the condition —
        // but canManageDecommission() includes them, and inspecting is their day-to-day work.
        $this->company();
        $row = $this->queued();

        $this->actingAs(User::factory()->create(['role' => 'it_executive']))
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'complete',
                'company' => 'Claritas Asia Sdn Bhd',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($row->fresh()->isInspected());
    }

    public function test_a_legacy_row_with_no_reason_must_supply_one_at_inspection(): void
    {
        $this->company();
        $row = $this->queued(['reason' => null]);

        $this->actingAs($this->itManager())
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'complete',
                'company' => 'Claritas Asia Sdn Bhd',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->itManager())
            ->post(route('decommission.inspect', $row), [
                'ewaste_completeness' => 'complete',
                'company' => 'Claritas Asia Sdn Bhd',
                'reason' => 'End of life',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('End of life', $row->fresh()->reason);
    }

    public function test_an_ordinary_asset_edit_does_not_wipe_a_completed_inspection(): void
    {
        $this->company();
        $row = $this->queued();
        $asset = $row->asset;

        $this->actingAs($this->itManager())->post(route('decommission.inspect', $row), [
            'ewaste_completeness' => 'incomplete',
            'ewaste_parts_removed' => 'Battery, RAM',
            'company' => 'Claritas Asia Sdn Bhd',
        ])->assertSessionHasNoErrors();

        // Re-save the asset with it still Not Good — a remarks/photo edit must not undo the
        // inspection and quietly send the quarter back to square one.
        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'asset_condition' => 'not_good',
                'status' => 'unavailable',
                'decommission_reason' => 'Motherboard failure',
                'remarks' => 'Stored in the server room pending collection',
            ]))
            ->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertTrue($row->isInspected());
        $this->assertSame('incomplete', $row->ewaste_completeness);
        $this->assertSame('Battery, RAM', $row->ewaste_parts_removed);
    }

    public function test_restaging_an_inspected_asset_as_a_vendor_return_clears_its_inspection(): void
    {
        $this->company();
        $row = $this->queued();
        $asset = $row->asset;
        $asset->update(['ownership_type' => 'rental']);

        $this->actingAs($this->itManager())->post(route('decommission.inspect', $row), [
            'ewaste_completeness' => 'incomplete',
            'ewaste_parts_removed' => 'Battery',
            'company' => 'Claritas Asia Sdn Bhd',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->itManager())
            ->put(route('assets.update', $asset), $this->formPayload($asset, [
                'ownership_type' => 'rental',
                'asset_condition' => 'returned',
                'status' => 'unavailable',
            ]))
            ->assertSessionHasNoErrors();

        // A rental going back to its owner carrying "Inspected — Incomplete" would assert a
        // disposal inspection that no longer applies to it.
        $row->refresh();
        $this->assertFalse($row->isInspected());
        $this->assertNull($row->ewaste_completeness);
        $this->assertNull($row->ewaste_parts_removed);
    }

    // ── Phase 3 — Run-up reminders ────────────────────────────────────────────

    /** Mark a queued row as fully inspected, so it stops being outstanding. */
    private function inspected(DisposedAsset $row): DisposedAsset
    {
        $row->update([
            'ewaste_completeness' => 'complete',
            'company' => 'Claritas Asia Sdn Bhd',
            'inspected_at' => now(),
        ]);

        return $row;
    }

    public function test_the_collection_date_is_the_next_quarter_start_and_today_counts(): void
    {
        // "On or after", not "after": on the collection day itself the answer is today, or the
        // day-of reminder would be computed against a date three months away.
        $this->assertSame('2026-07-01', EwasteInspectionReminderService::nextSweepDate(Carbon::parse('2026-06-15'))->toDateString());
        $this->assertSame('2026-07-01', EwasteInspectionReminderService::nextSweepDate(Carbon::parse('2026-07-01'))->toDateString());
        $this->assertSame('2026-10-01', EwasteInspectionReminderService::nextSweepDate(Carbon::parse('2026-07-02'))->toDateString());
        // Rolls into the next year rather than running out of quarters.
        $this->assertSame('2027-01-01', EwasteInspectionReminderService::nextSweepDate(Carbon::parse('2026-11-20'))->toDateString());
    }

    public function test_the_five_marks_fall_where_the_flow_says(): void
    {
        $marks = EwasteInspectionReminderService::markDates(Carbon::parse('2026-07-01'));

        // A calendar month, not 30 days — so the notice lands on the same day of the month.
        $this->assertSame('2026-06-01', $marks['month']->toDateString());
        $this->assertSame('2026-06-16', $marks['d15']->toDateString());
        $this->assertSame('2026-06-26', $marks['d5']->toDateString());
        $this->assertSame('2026-06-28', $marks['d3']->toDateString());
        $this->assertSame('2026-07-01', $marks['day']->toDateString());
    }

    public function test_only_the_five_marks_are_reminder_days(): void
    {
        foreach (['2026-06-01' => 'month', '2026-06-16' => 'd15', '2026-06-26' => 'd5',
            '2026-06-28' => 'd3', '2026-07-01' => 'day'] as $date => $expected) {
            $this->assertSame($expected, EwasteInspectionReminderService::markFor(Carbon::parse($date)), "for {$date}");
        }

        foreach (['2026-06-02', '2026-06-15', '2026-06-27', '2026-06-30', '2026-07-02'] as $date) {
            $this->assertNull(EwasteInspectionReminderService::markFor(Carbon::parse($date)), "for {$date}");
        }
    }

    public function test_a_reminder_names_the_outstanding_assets_and_goes_to_it(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        $row = $this->queued();

        $result = EwasteInspectionReminderService::run(Carbon::parse('2026-06-16'));

        $this->assertTrue($result['sent']);
        $this->assertSame('d15', $result['mark']);
        $this->assertSame(1, $result['count']);
        Mail::assertSent(EwasteInspectionReminderMail::class, function ($mail) use ($row) {
            return $mail->hasTo('it.manager@claritas.com')
                && $mail->audience === 'it'
                && $mail->rows->contains('id', $row->id);
        });
    }

    public function test_nothing_is_sent_when_every_queued_asset_is_inspected(): void
    {
        // A reminder that arrives whether or not there is anything to do is one people learn
        // to delete — and this one has to survive being read four times a quarter.
        Mail::fake();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        $this->inspected($this->queued());

        $result = EwasteInspectionReminderService::run(Carbon::parse('2026-06-16'));

        $this->assertFalse($result['sent']);
        $this->assertSame(0, $result['count']);
        Mail::assertNothingSent();
    }

    public function test_an_inspected_asset_with_no_confirmed_owner_still_counts_as_outstanding(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        $row = $this->queued();
        $row->update(['ewaste_completeness' => 'complete', 'inspected_at' => now()]);   // no company

        // Without an owner nobody is authorised to approve the disposal, so the cycle is just
        // as blocked as it would be by a machine nobody opened.
        $this->assertSame(1, EwasteInspectionReminderService::run(Carbon::parse('2026-06-16'))['count']);
    }

    public function test_finance_is_told_a_month_out_and_not_at_every_mark(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        User::factory()->create(['role' => 'finance_manager', 'work_email' => 'fin.manager@claritas.com']);
        $this->queued();

        EwasteInspectionReminderService::run(Carbon::parse('2026-06-01'));   // 1 month out
        Mail::assertSent(EwasteInspectionReminderMail::class, fn ($m) => $m->audience === 'finance' && $m->hasTo('fin.manager@claritas.com'));

        Mail::fake();
        EwasteInspectionReminderService::run(Carbon::parse('2026-06-28'));   // 3 days out
        // Finance cannot act on an inspection backlog; three more mails about it is noise.
        Mail::assertSent(EwasteInspectionReminderMail::class, fn ($m) => $m->audience === 'it');
        Mail::assertNotSent(EwasteInspectionReminderMail::class, fn ($m) => $m->audience === 'finance');
    }

    public function test_a_non_mark_day_sends_nothing_even_with_assets_outstanding(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        $this->queued();

        $result = EwasteInspectionReminderService::run(Carbon::parse('2026-06-17'));

        $this->assertFalse($result['sent']);
        $this->assertNull($result['mark']);
        Mail::assertNothingSent();
    }

    public function test_the_day_of_reminder_states_the_postponement(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        $this->queued();

        EwasteInspectionReminderService::run(Carbon::parse('2026-07-01'));

        Mail::assertSent(EwasteInspectionReminderMail::class, function ($mail) {
            $rendered = $mail->render();

            return $mail->mark === 'day'
                && str_contains($mail->envelope()->subject, 'Collection Is Today')
                && str_contains($rendered, 'postponed to the next quarter');
        });
    }

    public function test_the_bell_reaches_the_executives_who_do_the_inspecting(): void
    {
        NotificationFacade::fake();
        Mail::fake();
        $manager = User::factory()->create(['role' => 'it_manager']);
        $executive = User::factory()->create(['role' => 'it_executive']);
        $intern = User::factory()->create(['role' => 'it_intern']);
        $this->queued();

        EwasteInspectionReminderService::run(Carbon::parse('2026-06-16'));

        // it_executive holds canManageDecommission() precisely so they can clear this queue —
        // EwasteSweepService::notifyIt() stops at it_manager, which would miss them.
        NotificationFacade::assertSentTo([$manager, $executive], DecommissionNotification::class);
        NotificationFacade::assertNotSentTo($intern, DecommissionNotification::class);
    }

    public function test_the_command_self_gates_on_the_date(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        $this->queued();

        $this->artisan('ewaste:remind-inspection', ['--date' => '2026-06-17'])
            ->expectsOutputToContain('Not an inspection-reminder day')
            ->assertExitCode(0);
        Mail::assertNothingSent();

        $this->artisan('ewaste:remind-inspection', ['--date' => '2026-06-16'])
            ->assertExitCode(0);
        Mail::assertSent(EwasteInspectionReminderMail::class);
    }

    // ── Phase 4 — The gate, and one cycle per company ─────────────────────────

    private function ewasteVendor(): Vendor
    {
        return Vendor::create([
            'name' => 'RecycleCo', 'vendor_types' => ['ewaste'],
            'pic_email' => 'ops@recycleco.com', 'is_active' => true,
        ]);
    }

    public function test_one_uninspected_asset_postpones_the_whole_quarter(): void
    {
        Mail::fake();
        $this->ewasteVendor();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);

        $ready = $this->inspected($this->queued());
        $this->inspected($this->queued());
        $notReady = $this->queued();   // never inspected

        $result = EwasteSweepService::sweep();

        $this->assertTrue($result['blocked']);
        $this->assertSame(0, AssetDecommissionBatch::count(), 'No cycle may be created at all.');

        // Not even the ready assets go — a part-swept quarter would leave the rest in a queue
        // whose reminders have just reset.
        $this->assertNull($ready->fresh()->decommission_batch_id);
        $this->assertNull($notReady->fresh()->decommission_batch_id);
        // No vendor may be asked to quote for a collection that is not happening.
        Mail::assertNotSent(EwasteRfqMail::class);
        Mail::assertSent(EwasteCyclePostponedMail::class);
    }

    public function test_an_inspected_asset_with_no_owner_also_postpones_it(): void
    {
        Mail::fake();
        $this->ewasteVendor();
        $row = $this->queued();
        $row->update(['ewaste_completeness' => 'complete', 'inspected_at' => now()]);   // no company

        $this->assertTrue(EwasteSweepService::sweep()['blocked']);
        $this->assertSame(0, AssetDecommissionBatch::count());
    }

    public function test_the_postponement_is_told_to_it_and_to_finance(): void
    {
        Mail::fake();
        NotificationFacade::fake();
        $this->ewasteVendor();
        User::factory()->create(['role' => 'it_manager', 'work_email' => 'it.manager@claritas.com']);
        User::factory()->create(['role' => 'finance_manager', 'work_email' => 'fin.manager@claritas.com']);
        $this->queued();

        EwasteSweepService::sweep();

        // Finance were told a month ago that a quotation was coming; without this they simply
        // never hear that the cycle did not happen.
        Mail::assertSent(EwasteCyclePostponedMail::class, fn ($m) => $m->audience === 'it' && $m->hasTo('it.manager@claritas.com'));
        Mail::assertSent(EwasteCyclePostponedMail::class, fn ($m) => $m->audience === 'finance' && $m->hasTo('fin.manager@claritas.com'));
        NotificationFacade::assertSentTimes(DecommissionNotification::class, 1);
    }

    public function test_a_ready_queue_splits_into_one_cycle_per_company(): void
    {
        Mail::fake();
        $this->ewasteVendor();

        $claritasA = $this->inspected($this->queued());
        $claritasB = $this->inspected($this->queued());
        $enlinea = $this->queued();
        $enlinea->update([
            'ewaste_completeness' => 'complete', 'inspected_at' => now(),
            'company' => 'Enlinea Sdn Bhd',
        ]);

        $result = EwasteSweepService::sweep();

        $this->assertFalse($result['blocked']);
        $this->assertCount(2, $result['batches']);

        $byCompany = $result['batches']->keyBy('company');
        $this->assertSame(2, $byCompany['Claritas Asia Sdn Bhd']->items()->count());
        $this->assertSame(1, $byCompany['Enlinea Sdn Bhd']->items()->count());

        // Each asset lands in its OWN company's cycle — a mixed batch would have nobody able
        // to sign it and would name the wrong entity on the vendor's paperwork.
        $this->assertSame($byCompany['Claritas Asia Sdn Bhd']->id, $claritasA->fresh()->decommission_batch_id);
        $this->assertSame($byCompany['Claritas Asia Sdn Bhd']->id, $claritasB->fresh()->decommission_batch_id);
        $this->assertSame($byCompany['Enlinea Sdn Bhd']->id, $enlinea->fresh()->decommission_batch_id);
    }

    public function test_the_batch_reference_names_the_company(): void
    {
        Mail::fake();
        $this->ewasteVendor();
        $this->inspected($this->queued());
        $enlinea = $this->queued();
        $enlinea->update([
            'ewaste_completeness' => 'complete', 'inspected_at' => now(), 'company' => 'Enlinea Sdn Bhd',
        ]);

        $refs = EwasteSweepService::sweep()['batches']->pluck('batch_number');

        // A reference alone should say which entity's assets it covers.
        $this->assertTrue($refs->contains(fn ($r) => str_ends_with($r, '-CLA')), "got: {$refs->implode(', ')}");
        $this->assertTrue($refs->contains(fn ($r) => str_ends_with($r, '-ENL')), "got: {$refs->implode(', ')}");
    }

    public function test_the_company_token_drops_legal_form_words(): void
    {
        // Without this every Malaysian company would tokenise to the same SDN.
        $this->assertSame('CLA', AssetDecommissionBatch::companyToken('Claritas Asia Sdn Bhd'));
        $this->assertSame('ENL', AssetDecommissionBatch::companyToken('Enlinea Sdn. Bhd.'));
        $this->assertSame('NUR', AssetDecommissionBatch::companyToken('Nuren Group'));
        $this->assertNull(AssetDecommissionBatch::companyToken(''));
        $this->assertNull(AssetDecommissionBatch::companyToken('Sdn Bhd'));
    }

    public function test_the_rfq_and_the_report_are_issued_in_the_owning_companys_name(): void
    {
        Mail::fake();
        $vendor = $this->ewasteVendor();
        $batch = $this->cycle('Enlinea Sdn Bhd')->load('items.asset');

        $rfq = (new EwasteRfqMail($batch, $vendor))->render();
        $report = view('decommission.report-pdf', ['batch' => $batch])->render();

        // The RFQ tells a vendor whose assets these are and who will pay them, and the report
        // is filed against one entity — both used to print config('decommission.org_name'),
        // the group's fixed name, so every entity's paperwork claimed to be Claritas'. The
        // cycle has been per-company since Phase 4; the name must follow it.
        $this->assertStringContainsString('Enlinea Sdn Bhd', $rfq);
        $this->assertStringContainsString('Enlinea Sdn Bhd', $report);
        $this->assertStringNotContainsString(config('decommission.org_name'), $rfq);
        $this->assertStringNotContainsString(config('decommission.org_name'), $report);
    }

    public function test_a_company_less_legacy_cycle_still_names_somebody(): void
    {
        // Cycles created before Phase 4 carry no company. Falling back to the configured group
        // name is the only honest option left — a blank letterhead on a signed report, or an
        // RFQ asking a vendor to pay "", is worse than naming the operator of the portal.
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q2-1', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
        ]);

        $this->assertSame(config('decommission.org_name'), $batch->issuingCompany());
    }

    public function test_run_sweep_now_bypasses_the_date_but_never_the_inspection_gate(): void
    {
        Mail::fake();
        $this->ewasteVendor();
        $this->queued();   // uninspected

        // This is the one button an operator reaches for when the cycle did not run — letting
        // it through here would make the gate decorative.
        $this->actingAs($this->itManager())
            ->post(route('ewaste.sweep'))
            ->assertSessionHas('error');

        $this->assertSame(0, AssetDecommissionBatch::count());
    }

    public function test_run_sweep_now_works_once_the_queue_is_ready(): void
    {
        Mail::fake();
        $this->ewasteVendor();
        $this->inspected($this->queued());

        $this->actingAs($this->itManager())
            ->post(route('ewaste.sweep'))
            ->assertSessionHas('success');

        $this->assertSame(1, AssetDecommissionBatch::count());
        $this->assertSame('Claritas Asia Sdn Bhd', AssetDecommissionBatch::first()->company);
    }

    public function test_an_empty_queue_is_not_reported_as_postponed(): void
    {
        Mail::fake();

        $result = EwasteSweepService::sweep();

        // Nothing to do is not the same as blocked, and must not email anybody.
        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['batches']->isEmpty());
        Mail::assertNothingSent();
    }

    // ── Phase 5 — Multi-vendor quotations and the two decisions ───────────────

    private function vendor(string $name, array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => $name, 'vendor_types' => ['ewaste'],
            'pic_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
            'is_active' => true,
        ], $attrs));
    }

    /** A cycle with its assets already swept in, ready to receive quotations. */
    private function cycle(string $company = 'Claritas Asia Sdn Bhd'): AssetDecommissionBatch
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3-'.strtoupper(substr($company, 0, 3)),
            'type' => 'e_waste', 'company' => $company, 'status' => 'awaiting_quotation',
        ]);
        $row = $this->inspected($this->queued());
        $row->update(['decommission_batch_id' => $batch->id, 'company' => $company]);

        return $batch;
    }

    /**
     * A real PDF, not UploadedFile::fake()->create() — the project's `valid_file_content` rule
     * checks magic bytes against the declared extension, and an empty fake file fails it.
     */
    private function pdf(string $name = 'quote.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            Pdf::loadHTML('<html><body><h1>'.e($name).'</h1></body></html>')->output()
        );
    }

    private function fileQuotation(AssetDecommissionBatch $batch, Vendor $vendor, float $amount): void
    {
        Storage::fake('local');
        $this->actingAs($this->itManager())
            ->post(route('ewaste.quotation', $batch), [
                'vendor_id' => $vendor->id,
                'quotation_file' => $this->pdf(),
                'quotation_amount' => $amount,
            ])->assertSessionHasNoErrors();
    }

    private function approver(string $company, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'employee'], $attrs));
        EwasteCompanyApprover::create(['company' => $company, 'user_id' => $user->id]);

        return $user;
    }

    public function test_the_rfq_goes_to_every_active_ewaste_vendor(): void
    {
        Mail::fake();
        $this->vendor('RecycleCo');
        $this->vendor('ScrapWorks');
        $this->vendor('OldTimer', ['is_active' => false]);          // retired
        $this->vendor('Movers', ['vendor_types' => ['rental']]);    // not an e-waste vendor
        $this->inspected($this->queued());

        EwasteSweepService::sweep();

        // Comparing offers is impossible if only one company is ever asked.
        Mail::assertSent(EwasteRfqMail::class, 2);
        Mail::assertSent(EwasteRfqMail::class, fn ($m) => $m->hasTo('recycleco@example.com'));
        Mail::assertSent(EwasteRfqMail::class, fn ($m) => $m->hasTo('scrapworks@example.com'));
        Mail::assertNotSent(EwasteRfqMail::class, fn ($m) => $m->hasTo('oldtimer@example.com'));
    }

    public function test_each_vendor_keeps_its_own_revision_chain(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $a = $this->vendor('RecycleCo');
        $b = $this->vendor('ScrapWorks');

        $this->fileQuotation($batch, $a, 500);
        $this->fileQuotation($batch, $b, 700);
        $this->fileQuotation($batch, $a, 650);   // A re-quotes

        // B's first offer is revision 1, not revision 3 — a re-quote answers that vendor's own
        // earlier offer and has nothing to do with anyone else's.
        $this->assertSame([1, 2], $batch->quotations()->where('vendor_id', $a->id)->pluck('revision')->all());
        $this->assertSame([1], $batch->quotations()->where('vendor_id', $b->id)->pluck('revision')->all());
    }

    /**
     * The cycle page's quotation timeline (_quotation-steps.blade.php) used to walk every
     * quotation in the whole cycle as one flat, batch-wide list ordered only by the bare
     * `revision` column — so two DIFFERENT vendors each on their own revision 1 read as one
     * vendor re-quoting the other's still-live offer, and the second one filed was stamped
     * "Superseded" over an offer nobody had touched. Fixed by grouping the timeline per
     * vendor before deciding what is current.
     */
    public function test_the_cycle_page_shows_each_vendors_own_offer_as_current_not_superseded_by_another(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $a = $this->vendor('RecycleCo');
        $b = $this->vendor('ScrapWorks');
        $this->fileQuotation($batch, $a, 500);
        $this->fileQuotation($batch, $b, 700);

        $response = $this->actingAs($this->itManager())
            ->get(route('decommission.show', $batch))->assertOk();

        // Neither vendor's only offer has been replaced by anything — "Superseded" must not
        // appear anywhere on the page.
        $response->assertDontSee('Superseded', false);
        // Both vendors are named on the timeline now that there is more than one to tell apart.
        $response->assertSee('RecycleCo', false);
        $response->assertSee('ScrapWorks', false);
        // Neither offer is a "revision" of the other — each vendor has exactly one.
        $response->assertDontSee('Revision 1 of 2', false);
    }

    /**
     * When one of the two vendors re-quotes, ONLY their own earlier offer is superseded — the
     * other vendor's still-live, unrelated offer must never be caught by the same label.
     */
    public function test_a_requote_from_one_vendor_never_marks_a_different_vendors_offer_superseded(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $a = $this->vendor('RecycleCo');
        $b = $this->vendor('ScrapWorks');
        $this->fileQuotation($batch, $a, 500);
        $this->fileQuotation($batch, $b, 700);
        $this->fileQuotation($batch, $a, 650); // A re-quotes; B never has

        $response = $this->actingAs($this->itManager())
            ->get(route('decommission.show', $batch))->assertOk();

        // A's history: revision 1 superseded, revision 2 current.
        $response->assertSee('Revision 1 of 2', false);
        $response->assertSee('Revision 2 of 2', false);
        $response->assertSee('Superseded', false);

        // B's single offer must still read as current, not swept up by A's re-quote.
        $html = $response->getContent();
        $scrapWorksPos = strpos($html, 'ScrapWorks');
        $this->assertNotFalse($scrapWorksPos);
        $nearbySlice = substr($html, $scrapWorksPos, 400);
        $this->assertStringNotContainsString('Superseded', $nearbySlice);
    }

    public function test_the_comparison_ranks_the_highest_offer_first(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $low = $this->vendor('LowBall');
        $high = $this->vendor('TopDollar');
        $this->fileQuotation($batch, $low, 300);
        $this->fileQuotation($batch, $high, 900);

        // The vendor pays US, so the best offer is the highest — the opposite of a purchase.
        $best = $batch->fresh()->bestOffer();
        $this->assertSame($high->id, $best->vendor_id);
        $this->assertSame($high->id, $batch->fresh()->quotationsForComparison()->first()->vendor_id);
    }

    public function test_only_each_vendors_current_offer_is_compared(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $a = $this->vendor('RecycleCo');
        $this->fileQuotation($batch, $a, 500);
        $this->fileQuotation($batch, $a, 650);

        // A superseded offer is history, not a second competitor.
        $comparison = $batch->fresh()->quotationsForComparison();
        $this->assertCount(1, $comparison);
        $this->assertSame(2, $comparison->first()->revision);
    }

    public function test_a_quotation_must_say_which_vendor_sent_it(): void
    {
        Mail::fake();
        Storage::fake('local');
        $batch = $this->cycle();

        $this->actingAs($this->itManager())
            ->post(route('ewaste.quotation', $batch), [
                'quotation_file' => $this->pdf(),
            ])
            ->assertSessionHasErrors('vendor_id');
    }

    public function test_the_comparison_cannot_be_submitted_with_a_missing_amount(): void
    {
        Mail::fake();
        Storage::fake('local');
        $batch = $this->cycle();
        $a = $this->vendor('RecycleCo');

        // No amount typed and OCR is off in tests, so the figure is absent.
        $this->actingAs($this->itManager())->post(route('ewaste.quotation', $batch), [
            'vendor_id' => $a->id,
            'quotation_file' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $quotation = $batch->fresh()->quotations()->first();

        $this->actingAs($this->itManager())
            ->post(route('ewaste.submit', $batch), ['recommended_quotation_id' => $quotation->id])
            ->assertSessionHas('error');

        // Without figures there is nothing to rank the offers ON.
        $this->assertSame('quotation_uploaded', $batch->fresh()->status);
    }

    public function test_uploading_a_quotation_no_longer_asks_anyone_to_decide(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        User::factory()->create(['role' => 'finance_manager', 'work_email' => 'fin@claritas.com']);

        $this->fileQuotation($batch, $this->vendor('RecycleCo'), 500);

        // Reviewing a field of one, repeatedly, is exactly what asking several vendors avoids.
        Mail::assertNotSent(EwasteQuotationApprovalMail::class);
        $this->assertNull($batch->fresh()->finance_status);
    }

    public function test_submitting_asks_finance_and_management_together(): void
    {
        Mail::fake();
        NotificationFacade::fake();
        $batch = $this->cycle();
        User::factory()->create(['role' => 'finance_manager', 'work_email' => 'fin@claritas.com']);
        $kelvin = $this->approver('Claritas Asia Sdn Bhd', ['work_email' => 'kelvin@claritas.com']);
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($batch, $vendor, 500);
        $quotation = $batch->fresh()->quotations()->first();

        $this->actingAs($this->itManager())
            ->post(route('ewaste.submit', $batch), [
                'recommended_quotation_id' => $quotation->id,
                'recommendation_note' => 'Only replier, fair price',
            ])->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame('pending_approval', $batch->status);
        $this->assertSame('pending', $batch->finance_status);
        $this->assertSame('pending', $batch->management_status);
        Mail::assertSent(EwasteQuotationApprovalMail::class);
        Mail::assertSent(EwasteManagementApprovalMail::class, fn ($m) => $m->hasTo('kelvin@claritas.com'));
        NotificationFacade::assertSentTo($kelvin, DecommissionNotification::class);
    }

    public function test_finance_remarks_alone_do_not_advance_the_cycle(): void
    {
        Mail::fake();
        $batch = $this->submitted();
        $finance = User::factory()->create(['role' => 'finance_manager']);

        $this->actingAs($finance)->post(route('finance.ewaste.remark', $batch), ['remarks' => 'Looks fine to me'])->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame('noted', $batch->finance_status);
        // Still pending: Finance's remarks are advisory only — only management's decision
        // releases assets to a vendor.
        $this->assertSame('pending_approval', $batch->status);
        $this->assertFalse($batch->isApproved());
    }

    public function test_a_receipt_cannot_be_uploaded_on_finance_remarks_alone(): void
    {
        Mail::fake();
        Storage::fake('local');
        $batch = $this->submitted();
        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->post(route('finance.ewaste.remark', $batch), ['remarks' => 'Looks fine to me']);

        $this->actingAs($this->itManager())
            ->post(route('ewaste.receipt', $batch), [
                'receipt_file' => $this->pdf('receipt.pdf'),
            ])
            ->assertSessionHas('error');

        $this->assertNull($batch->fresh()->receipt_path);
    }

    public function test_management_approval_advances_the_cycle_regardless_of_finance_remarks(): void
    {
        Mail::fake();
        $batch = $this->submitted();
        $kelvin = $this->approver('Claritas Asia Sdn Bhd');

        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->post(route('finance.ewaste.remark', $batch), ['remarks' => 'Seems low for this volume']);

        $this->actingAs($kelvin)->post(route('management.ewaste.approve', $batch))->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame('noted', $batch->finance_status, "Finance's remarks stay on record.");
        $this->assertSame('Seems low for this volume', $batch->finance_remarks);
        $this->assertSame('approved', $batch->management_status);
        $this->assertTrue($batch->isApproved(), "Management's decision is the only one that authorises the disposal.");
    }

    public function test_management_can_select_a_different_vendor_than_it_recommended(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $low = $this->vendor('LowBall');
        $high = $this->vendor('TopDollar');
        $this->fileQuotation($batch, $low, 300);
        $this->fileQuotation($batch, $high, 900);

        $batch->refresh();
        $recommended = $batch->quotationsForComparison()->first();      // the 900 offer
        $other = $batch->quotationsForComparison()->last();             // the 300 offer

        $this->actingAs($this->itManager())->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $recommended->id,
        ])->assertSessionHasNoErrors();

        $kelvin = $this->approver('Claritas Asia Sdn Bhd');
        $this->actingAs($kelvin)->post(route('management.ewaste.approve', $batch), [
            'selected_quotation_id' => $other->id,
            'remarks' => 'They collect from both sites',
        ])->assertSessionHasNoErrors();

        $batch->refresh();
        // What we recommended and what was authorised are different facts, and both are kept.
        $this->assertSame($recommended->id, $batch->recommended_quotation_id);
        $this->assertSame($other->id, $batch->selected_quotation_id);
        $this->assertSame($low->id, $batch->fresh()->vendor_id, 'The cycle now points at the winner.');
    }

    public function test_the_winning_vendor_is_notified_and_the_losers_are_not(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $low = $this->vendor('LowBall');
        $high = $this->vendor('TopDollar');
        $this->fileQuotation($batch, $low, 300);
        $this->fileQuotation($batch, $high, 900);
        $batch->refresh();
        $winner = $batch->quotationsForComparison()->first();

        $this->actingAs($this->itManager())->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $winner->id,
        ]);
        $this->actingAs($this->approver('Claritas Asia Sdn Bhd'))
            ->post(route('management.ewaste.approve', $batch))->assertSessionHasNoErrors();

        // A losing vendor made an offer we did not take up — there is nothing they must do.
        Mail::assertSent(EwasteAwardMail::class, fn ($m) => $m->hasTo('topdollar@example.com'));
        Mail::assertNotSent(EwasteAwardMail::class, fn ($m) => $m->hasTo('lowball@example.com'));
    }

    public function test_only_that_companys_approver_may_decide(): void
    {
        Mail::fake();
        $batch = $this->submitted();               // Claritas cycle
        $petrina = $this->approver('Enlinea Sdn Bhd');

        $this->actingAs($petrina)
            ->post(route('management.ewaste.approve', $batch))
            ->assertForbidden();

        $this->assertSame('pending', $batch->fresh()->management_status);
    }

    public function test_finance_cannot_cast_the_management_decision(): void
    {
        Mail::fake();
        $batch = $this->submitted();

        // Being in Finance does not make you management — that is the whole point of the split.
        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->post(route('management.ewaste.approve', $batch))
            ->assertForbidden();
    }

    public function test_an_approver_of_two_companies_may_decide_for_both(): void
    {
        Mail::fake();
        $kelvin = User::factory()->create(['role' => 'employee']);
        EwasteCompanyApprover::create(['company' => 'Claritas Asia Sdn Bhd', 'user_id' => $kelvin->id]);
        EwasteCompanyApprover::create(['company' => 'Enlinea Sdn Bhd', 'user_id' => $kelvin->id]);

        // The case the whole mapping exists for: one person, two entities.
        $this->assertTrue($kelvin->canApproveEwasteAsManagement('Claritas Asia Sdn Bhd'));
        $this->assertTrue($kelvin->canApproveEwasteAsManagement('Enlinea Sdn Bhd'));
        $this->assertFalse($kelvin->canApproveEwasteAsManagement('Nuren Group'));
    }

    public function test_the_first_of_several_approvers_settles_it(): void
    {
        Mail::fake();
        $batch = $this->submitted();
        $kelvin = $this->approver('Claritas Asia Sdn Bhd');
        $petrina = $this->approver('Claritas Asia Sdn Bhd');

        $this->actingAs($kelvin)->post(route('management.ewaste.approve', $batch))->assertSessionHasNoErrors();

        // Waiting for every named approver would stall a cycle behind whoever is on leave.
        $this->assertSame($kelvin->id, $batch->fresh()->management_reviewed_by);

        $this->actingAs($petrina)
            ->post(route('management.ewaste.reject', $batch), ['remarks' => 'Changed my mind'])
            ->assertSessionHas('error');
        $this->assertSame('approved', $batch->fresh()->management_status);
    }

    public function test_a_management_rejection_needs_a_reason(): void
    {
        Mail::fake();
        $batch = $this->submitted();
        $kelvin = $this->approver('Claritas Asia Sdn Bhd');

        $this->actingAs($kelvin)
            ->post(route('management.ewaste.reject', $batch))
            ->assertSessionHasErrors('remarks');

        $this->actingAs($kelvin)
            ->post(route('management.ewaste.reject', $batch), ['remarks' => 'Keep them for spares'])
            ->assertSessionHasNoErrors();

        $batch->refresh();
        $this->assertSame('rejected', $batch->management_status);
        $this->assertSame('rejected', $batch->status);
    }

    public function test_a_rejected_cycle_can_collect_fresh_quotations(): void
    {
        Mail::fake();
        $batch = $this->submitted();
        $this->actingAs($this->approver('Claritas Asia Sdn Bhd'))
            ->post(route('management.ewaste.reject', $batch), ['remarks' => 'Get better offers']);

        $this->fileQuotation($batch->fresh(), $this->vendor('NewCo'), 1200);

        $this->assertSame(2, $batch->fresh()->quotations()->count());
    }

    public function test_correcting_a_losing_offer_does_not_rewrite_the_reported_amount(): void
    {
        Mail::fake();
        $batch = $this->cycle();
        $low = $this->vendor('LowBall');
        $high = $this->vendor('TopDollar');
        $this->fileQuotation($batch, $low, 300);
        $this->fileQuotation($batch, $high, 900);
        $batch->refresh();
        $winner = $batch->quotationsForComparison()->first();
        $loser = $batch->quotationsForComparison()->last();

        $this->actingAs($this->itManager())->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $winner->id,
        ]);

        $this->actingAs($this->itManager())->post(route('ewaste.amount', $batch), [
            'field' => 'quotation', 'quotation_id' => $loser->id, 'amount' => 350,
        ])->assertSessionHasNoErrors();

        // The cached figure is what the report states the SELECTED vendor pays us.
        $this->assertSame('350.00', $loser->fresh()->amount);
        $this->assertSame('900.00', $batch->fresh()->quotation_amount);
    }

    public function test_only_a_superadmin_may_configure_approvers(): void
    {
        $this->actingAs($this->itManager())->get(route('superadmin.ewaste-approvers'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->get(route('superadmin.ewaste-approvers'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))
            ->get(route('superadmin.ewaste-approvers'))->assertOk();
    }

    public function test_a_company_with_no_named_approver_falls_back_to_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);

        // A cycle nobody can approve would hold assets in the queue indefinitely.
        $this->assertTrue($admin->canApproveEwasteAsManagement('Unnamed Sdn Bhd'));
        $this->assertTrue(EwasteCompanyApprover::configuredFor('Unnamed Sdn Bhd')->isEmpty());
    }

    // ── Phase 6 — The final report and the vendor archive ─────────────────────

    /** A decided cycle: two vendors quoted, management approved the cheaper one. */
    private function decided(): array
    {
        Storage::fake('local');
        $batch = $this->cycle();
        $low = $this->vendor('LowBall');
        $high = $this->vendor('TopDollar');
        $this->fileQuotation($batch, $low, 300);
        $this->fileQuotation($batch, $high, 900);
        $batch->refresh();

        $recommended = $batch->quotationsForComparison()->first();   // TopDollar, 900
        $other = $batch->quotationsForComparison()->last();          // LowBall, 300

        $this->actingAs($this->itManager())->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $recommended->id,
            'recommendation_note' => 'Highest offer',
        ]);
        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->post(route('finance.ewaste.remark', $batch), ['remarks' => 'Prefer the local firm']);
        $this->actingAs($this->approver('Claritas Asia Sdn Bhd', ['name' => 'Kelvin Approver']))
            ->post(route('management.ewaste.approve', $batch), [
                'selected_quotation_id' => $other->id,
                'remarks' => 'They collect from both sites',
            ]);

        return [$batch->fresh(), $low, $high];
    }

    public function test_the_report_prints_both_sign_offs_and_names_management_as_the_authority(): void
    {
        Mail::fake();
        [$batch] = $this->decided();

        $html = view('decommission.report-pdf', ['batch' => $batch->load(['items.asset', 'quotations.vendor'])])->render();

        // Management authorised it; Finance's remarks are recorded beside it, advisory only.
        // A report showing only the Finance stamp would name the wrong authority on the page
        // an audit reads.
        $this->assertStringContainsString('Authorisation', $html);
        $this->assertStringContainsString('Approved by Claritas Asia Sdn Bhd management', $html);
        $this->assertStringContainsString('Kelvin Approver', $html);
        $this->assertStringContainsString('Finance Remarks', $html);
        $this->assertStringContainsString('Prefer the local firm', $html);
    }

    /**
     * A cycle decided under the pre-2026-08-16 rule, where Finance's position doubled as an
     * approve/reject verdict — built by writing the legacy columns directly (recordFinanceRemark()
     * can no longer produce a 'rejected' status; that is the whole point of the change). The
     * report must still print the override sentence for a cycle that genuinely carries it.
     */
    public function test_a_legacy_finance_objection_still_prints_the_override_note(): void
    {
        Mail::fake();
        $batch = $this->submitted();
        $reviewer = User::factory()->create(['role' => 'finance_manager']);
        $batch->quotationUnderReview()->update([
            'finance_status' => 'rejected', 'finance_reviewed_by' => $reviewer->id,
            'finance_reviewed_at' => now(), 'finance_remarks' => 'Too cheap for the volume',
        ]);
        $batch->update([
            'finance_status' => 'rejected', 'finance_reviewed_by' => $reviewer->id,
            'finance_reviewed_at' => now(), 'finance_remarks' => 'Too cheap for the volume',
        ]);
        $kelvin = $this->approver('Claritas Asia Sdn Bhd', ['name' => 'Kelvin Approver']);
        $this->actingAs($kelvin)->post(route('management.ewaste.approve', $batch))->assertSessionHasNoErrors();

        $html = view('decommission.report-pdf', ['batch' => $batch->fresh()->load(['items.asset', 'quotations.vendor'])])->render();

        // The single most audit-relevant thing the report can contain: it must be stated, not
        // left to be inferred from two stamps that disagree.
        $this->assertStringContainsString('Finance objected (legacy)', $html);
        $this->assertStringContainsString('authorised by management notwithstanding this objection', $html);
    }

    public function test_the_report_names_the_accepted_offer_and_the_override(): void
    {
        Mail::fake();
        [$batch, $low, $high] = $this->decided();

        $html = view('decommission.report-pdf', ['batch' => $batch->load(['items.asset', 'quotations.vendor'])])->render();

        $this->assertStringContainsString('Accepted offer:', $html);
        $this->assertStringContainsString($low->name, $html);
        // "We recommended A, management chose B" is invisible from the table alone.
        $this->assertStringContainsString('selected a different vendor from the one IT recommended', $html);
        $this->assertStringContainsString($high->name, $html);
    }

    public function test_the_report_lists_every_vendors_offer_not_only_the_winner(): void
    {
        Mail::fake();
        [$batch, $low, $high] = $this->decided();

        $html = view('decommission.report-pdf', ['batch' => $batch->load(['items.asset', 'quotations.vendor'])])->render();

        // The losing offer is what evidences that the accepted price was chosen on comparison.
        $this->assertStringContainsString('Quotations Received', $html);
        $this->assertStringContainsString('300.00', $html);
        $this->assertStringContainsString('900.00', $html);
        $this->assertStringContainsString('Not selected', $html);
    }

    public function test_the_appendix_reproduces_the_losing_offers_and_marks_the_accepted_one(): void
    {
        Mail::fake();
        [$batch, $low, $high] = $this->decided();

        $appendix = \App\Services\DecommissionReportRenderer::appendix($batch);
        $labels = collect($appendix)->pluck('label')->implode(' | ');

        // Every offer is reproduced; the accepted one keeps the `quotation` slot that the
        // batch's cache columns and the existing callers read by name.
        $this->assertCount(2, $appendix);
        $this->assertArrayHasKey('quotation', $appendix);
        $this->assertStringContainsString('ACCEPTED', $labels);
        $this->assertStringContainsString('not accepted', $labels);
        $this->assertStringContainsString($low->name, $labels);
        $this->assertStringContainsString($high->name, $labels);
    }

    public function test_a_single_quotation_cycle_still_reads_exactly_as_before(): void
    {
        Mail::fake();
        $batch = $this->submitted();

        $appendix = \App\Services\DecommissionReportRenderer::appendix($batch->fresh());

        // No vendor names, no revision numbering, no accepted/not-accepted marker on a cycle
        // where there is only ever one document to look at.
        $this->assertSame(['quotation'], array_keys($appendix));
        $this->assertSame('Quotation', $appendix['quotation']['label']);
    }

    public function test_the_awarded_vendor_gets_the_cycle_on_their_profile(): void
    {
        Mail::fake();
        [$batch, $low, $high] = $this->decided();

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', ['vendor' => $low->id, 'tab' => 'report']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('E-Waste Collections', $html);
        $this->assertStringContainsString($batch->batch_number, $html);
    }

    public function test_a_vendor_who_quoted_and_lost_is_not_credited_with_the_collection(): void
    {
        Mail::fake();
        [$batch, $low, $high] = $this->decided();

        $viewer = $this->itManager();
        // Burn one request first: the approval's success flash NAMES the reference, and the
        // layout renders it as a banner on the next page — so the assertion below would be
        // measuring the banner rather than the panel. Same trap as RentalAssetReturnTest.
        $this->actingAs($viewer)->get(route('vendors.show', ['vendor' => $high->id]));

        $html = $this->actingAs($viewer)
            ->get(route('vendors.show', ['vendor' => $high->id, 'tab' => 'report']))
            ->assertOk()->getContent();

        // They collected nothing — a collections entry here would read as though they had. The
        // losing offer still appears in the cycle's own report, as evidence of the comparison.
        //
        // Asserted on the SECTION, not on the batch reference. Since 2026-08-14 the reference
        // legitimately appears on this vendor's Contracts tab — the quotation they sent is filed
        // there automatically — and every tab pane renders into the DOM whichever is active, so
        // an assertion on the bare reference would now fail on the very record they SHOULD have.
        // What must be absent is the credit, which is what the heading represents.
        $this->assertStringNotContainsString('E-Waste Collections', $html);

        // And the quotation they did send is on their record, filed against the cycle.
        $this->assertStringContainsString($batch->batch_number, $html);
    }

    public function test_a_rental_vendor_carries_no_e_waste_section(): void
    {
        $vendor = $this->vendor('Aurora Digital', ['vendor_types' => ['rental']]);
        // A pending rental asset is what opens the Report tab for them at all.
        AssetInventory::create([
            'asset_tag' => 'AST-7001', 'asset_name' => 'Field Laptop',
            'asset_category' => 'it_equipment', 'asset_type' => 'Laptop',
            'status' => 'available', 'asset_condition' => 'good',
            'ownership_type' => 'rental', 'vendor_id' => $vendor->id,
            'company_supplied_to' => 'Claritas Asia Sdn Bhd',
        ]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', ['vendor' => $vendor->id, 'tab' => 'report']))
            ->assertOk()->getContent();

        // They are never awarded a disposal, so the block could only ever sit there empty —
        // reading as a vendor whose collections had gone missing rather than one that runs none.
        $this->assertStringContainsString('Assets Accepted', $html);
        $this->assertStringNotContainsString('E-Waste Collections', $html);
    }

    public function test_a_collection_awarded_to_an_untagged_vendor_is_still_shown(): void
    {
        Mail::fake();
        [$batch, $low] = $this->decided();

        // The tag is editable at any time. Keying the section purely off it would bury a
        // collection this vendor really did carry out, on the only page that lists it per
        // vendor — a data problem to see, not to hide.
        $low->update(['vendor_types' => ['rental']]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', ['vendor' => $low->id, 'tab' => 'report']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('E-Waste Collections', $html);
        $this->assertStringContainsString($batch->batch_number, $html);
    }

    public function test_an_e_waste_vendor_carries_no_aarf_register(): void
    {
        Mail::fake();
        [, $low] = $this->decided();   // tagged e-waste only, and awarded the collection

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', ['vendor' => $low->id, 'tab' => 'report']))
            ->assertOk()->getContent();

        // We rent nothing from them, so the two acknowledgement sections could only sit there
        // empty — reading as forms that had gone missing rather than a vendor that signs none.
        $this->assertStringContainsString('E-Waste Collections', $html);
        $this->assertStringNotContainsString('Rental Asset Acknowledgement (AARF)', $html);
        $this->assertStringNotContainsString('Assets Accepted', $html);
    }

    public function test_an_unsigned_form_still_shows_on_an_e_waste_only_vendors_profile(): void
    {
        $vendor = $this->vendor('Recycle Plus');   // tagged e-waste only
        AssetInventory::create([
            'asset_tag' => 'AST-7002', 'asset_name' => 'Field Laptop',
            'asset_category' => 'it_equipment', 'asset_type' => 'Laptop',
            'status' => 'available', 'asset_condition' => 'good',
            'ownership_type' => 'rental', 'vendor_id' => $vendor->id,
            'company_supplied_to' => 'Claritas Asia Sdn Bhd',
        ]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', ['vendor' => $vendor->id, 'tab' => 'report']))
            ->assertOk()->getContent();

        // The tag is editable at any time. Rental business against an e-waste-tagged vendor is
        // a data problem to see, not to bury on the only page that files their forms.
        $this->assertStringContainsString('Rental Asset Acknowledgement (AARF)', $html);
        $this->assertStringContainsString('awaiting acknowledgement', $html);
    }

    /** A cycle with one priced quotation, submitted and awaiting both decisions. */
    private function submitted(): AssetDecommissionBatch
    {
        $batch = $this->cycle();
        $this->fileQuotation($batch, $this->vendor('RecycleCo'), 500);
        $quotation = $batch->fresh()->quotations()->first();

        $this->actingAs($this->itManager())
            ->post(route('ewaste.submit', $batch), ['recommended_quotation_id' => $quotation->id]);

        return $batch->fresh();
    }
}
