<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Mail\EwasteAwaitingReportMail;
use App\Mail\EwasteFinalReportMail;
use App\Mail\EwasteRfqMail;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Models\Vendor;
use App\Services\EwasteSweepService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EwasteCycleTest extends TestCase
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

    /**
     * A queued e-waste asset that is READY for a cycle — inspected, with its owning company
     * confirmed. Since Phase 4 the sweep refuses to run while anything in the queue is not,
     * so these cycle-mechanics tests would otherwise all be testing the gate instead. The
     * gate itself is covered in EwasteDecommissionFlowTest.
     */
    private function stageEwaste(array $attrs = []): AssetInventory
    {
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'status' => 'unavailable']);
        DisposedAsset::create(array_merge([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'disposed_by' => 'IT', 'disposed_at' => now(),
            'ewaste_completeness' => 'complete', 'company' => 'Claritas Asia Sdn Bhd',
            'inspected_at' => now(),
        ], $attrs));

        return $asset;
    }

    public function test_sweep_service_opens_cycle_rfqs_vendor_and_reports_finance(): void
    {
        Mail::fake();
        Vendor::create(['name' => 'RecycleCo', 'vendor_types' => ['ewaste'], 'pic_email' => 'ops@recycleco.com', 'is_active' => true]);
        User::factory()->create(['role' => 'finance_manager']);
        $asset = $this->stageEwaste();

        $result = EwasteSweepService::sweep();

        $batch = $result['batches']->first();
        $this->assertNotNull($batch);
        $this->assertSame('awaiting_quotation', $batch->status);
        $this->assertSame($batch->id, $asset->fresh()->decommission_batch_id);
        Mail::assertSent(EwasteRfqMail::class);
        Mail::assertSent(EwasteAwaitingReportMail::class);
    }

    public function test_finance_report_goes_to_manager_and_ccs_executives_on_work_email_only(): void
    {
        Mail::fake();
        Vendor::create(['name' => 'RecycleCo', 'vendor_types' => ['ewaste'], 'pic_email' => 'ops@recycleco.com', 'is_active' => true]);
        $manager = User::factory()->create(['role' => 'finance_manager', 'work_email' => 'fin.manager@claritas.com']);
        $exec = User::factory()->create(['role' => 'finance_executive', 'work_email' => 'fin.exec@claritas.com']);
        User::factory()->create(['role' => 'finance_executive', 'work_email' => 'fin.exec2@claritas.com', 'is_active' => false]);
        $this->stageEwaste();

        EwasteSweepService::sweep();

        // Exactly one finance email — addressed TO the manager, CC the active executive.
        Mail::assertSent(EwasteAwaitingReportMail::class, 1);
        Mail::assertSent(EwasteAwaitingReportMail::class, function ($mail) {
            return $mail->hasTo('fin.manager@claritas.com')
                && $mail->hasCc('fin.exec@claritas.com')
                && ! $mail->hasCc('fin.exec2@claritas.com');   // inactive executive excluded
        });
    }

    public function test_finance_report_falls_back_to_superadmin_when_no_finance_manager(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'superadmin', 'work_email' => 'admin@claritas.com']);
        $this->stageEwaste();

        EwasteSweepService::sweep();

        Mail::assertSent(EwasteAwaitingReportMail::class, fn ($mail) => $mail->hasTo('admin@claritas.com'));
    }

    public function test_sweep_skips_backlog_already_in_a_cycle(): void
    {
        $this->stageEwaste();
        EwasteSweepService::sweep();                      // first sweep takes it
        $second = EwasteSweepService::sweep();            // nothing new
        $this->assertTrue($second['batches']->isEmpty());
        $this->assertSame(1, AssetDecommissionBatch::count());
    }

    public function test_command_self_gates_off_quarter_but_force_runs(): void
    {
        Carbon::setTestNow('2026-08-15');   // August — not a quarter-start month
        $this->stageEwaste();

        $this->artisan('ewaste:sweep-quarterly')->assertExitCode(0);
        $this->assertSame(0, AssetDecommissionBatch::count());

        $this->artisan('ewaste:sweep-quarterly', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, AssetDecommissionBatch::count());
    }

    public function test_command_fires_on_first_of_quarter_without_force(): void
    {
        Carbon::setTestNow('2026-07-01');   // first day of Q3
        $this->stageEwaste();
        $this->artisan('ewaste:sweep-quarterly')->assertExitCode(0);
        $this->assertSame(1, AssetDecommissionBatch::count());
    }

    /**
     * A cycle whose comparison IT have submitted — both Finance and management have been asked
     * and neither has answered. `quotation_uploaded` is no longer a reviewable state since
     * Phase 5: offers are still being collected then.
     */
    private function quotedBatch(): AssetDecommissionBatch
    {
        return AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'pending_approval',
            'company' => 'Claritas Asia Sdn Bhd',
            'finance_status' => 'pending', 'management_status' => 'pending',
            'quotation_amount' => 350, 'quotation_path' => 'ewaste_quotations/EWA/q.pdf',
            'quotation_uploaded_at' => now(),
        ]);
    }

    public function test_finance_can_approve_pending_quotation(): void
    {
        $fin = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->quotedBatch();

        $this->actingAs($fin)->post(route('finance.ewaste.approve', $batch), ['remarks' => 'ok'])->assertRedirect();

        $batch->refresh();
        $this->assertSame('approved', $batch->finance_status);
        $this->assertSame($fin->id, $batch->finance_reviewed_by);
        // Since Phase 5 Finance record a POSITION — the cycle only moves when management
        // approve, so it stays pending here rather than advancing to a released state.
        $this->assertSame('pending_approval', $batch->status);
        $this->assertFalse($batch->isApproved());
    }

    public function test_approve_guard_blocks_non_pending(): void
    {
        $fin = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->quotedBatch();
        $batch->update(['finance_status' => 'approved', 'status' => 'finance_approved']);

        $this->actingAs($fin)->post(route('finance.ewaste.approve', $batch), [])->assertRedirect();
        // Still approved — a second approve is a no-op guarded by the state check.
        $this->assertSame('approved', $batch->fresh()->finance_status);
    }

    public function test_finance_reject_requires_reason(): void
    {
        $fin = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->quotedBatch();

        $this->actingAs($fin)->post(route('finance.ewaste.reject', $batch), [])->assertSessionHasErrors('remarks');
        $this->assertSame('pending', $batch->fresh()->finance_status);

        $this->actingAs($fin)->post(route('finance.ewaste.reject', $batch), ['remarks' => 'Too low'])->assertRedirect();
        $this->assertSame('rejected', $batch->fresh()->finance_status);
    }

    public function test_it_manager_cannot_approve_quotation(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $this->actingAs($it)->post(route('finance.ewaste.approve', $this->quotedBatch()))->assertForbidden();
    }

    public function test_finance_cannot_run_sweep(): void
    {
        $fin = User::factory()->create(['role' => 'finance_manager']);
        $this->actingAs($fin)->post(route('ewaste.sweep'))->assertForbidden();
    }

    public function test_receipt_upload_blocked_before_finance_approval(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $batch = $this->quotedBatch();   // finance_status pending, not approved
        $this->actingAs($it)->post(route('ewaste.receipt', $batch))->assertRedirect();
        $this->assertNull($batch->fresh()->receipt_uploaded_at);
    }

    public function test_uploading_receipt_auto_completes_the_cycle_without_an_amount(): void
    {
        Mail::fake();
        Storage::fake('local');
        $it = User::factory()->create(['role' => 'it_manager']);
        User::factory()->create(['role' => 'finance_manager']);

        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'finance_approved',
            'finance_status' => 'approved', 'quotation_path' => 'ewaste_quotations/EWA/q.pdf',
        ]);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'decommission_batch_id' => $batch->id]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type,
            'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
            'asset_condition' => 'not_good', 'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        // A real 1x1 PNG so the magic-byte (valid_file_content) + malware checks pass. No amount field.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $receipt = UploadedFile::fake()->createWithContent('receipt.png', $png);

        $this->actingAs($it)->post(route('ewaste.receipt', $batch), ['receipt_file' => $receipt])
            ->assertRedirect(route('reports.decommission'));

        $batch->refresh();
        $this->assertSame('completed', $batch->status);          // auto-completed — no manual button
        $this->assertNotNull($batch->finalized_at);
        $this->assertNotNull($batch->receipt_uploaded_at);
        $this->assertNull($batch->receipt_amount);               // amount removed — read from the document
        $this->assertNotNull($asset->fresh()->decommissioned_at); // assets archived
        Mail::assertSent(EwasteFinalReportMail::class);           // final report sent to Finance
    }

    public function test_complete_cycle_archives_assets_and_reports_finance(): void
    {
        Mail::fake();
        Storage::fake('local');
        $it = User::factory()->create(['role' => 'it_manager']);
        User::factory()->create(['role' => 'finance_manager']);

        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'collected',
            'finance_status' => 'approved', 'quotation_amount' => 350, 'receipt_amount' => 350,
            'receipt_path' => 'ewaste_receipts/EWA/r.pdf',
        ]);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'decommission_batch_id' => $batch->id]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type,
            'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
            'asset_condition' => 'not_good', 'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        $this->actingAs($it)->post(route('ewaste.complete', $batch))->assertRedirect(route('reports.decommission'));

        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertNotNull($batch->finalized_at);
        $this->assertNotNull($asset->fresh()->decommissioned_at);
        Mail::assertSent(EwasteFinalReportMail::class);
    }

    public function test_report_states_ewaste_completeness_in_asset_details(): void
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
        ]);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'decommission_batch_id' => $batch->id]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type,
            'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
            'asset_condition' => 'not_good', 'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'ewaste_completeness' => 'incomplete', 'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        // The asset-details section (attached to the finance report + vendor RFQ) must state it.
        $html = view('decommission.report-pdf', ['batch' => $batch->fresh(['vendor', 'items.asset'])])->render();

        $this->assertStringContainsString('Completeness', $html);
        $this->assertStringContainsString('some parts removed', $html);
    }

    public function test_report_lists_the_specific_parts_removed_from_an_incomplete_asset(): void
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
        ]);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'decommission_batch_id' => $batch->id]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type,
            'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
            'asset_condition' => 'not_good', 'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'ewaste_completeness' => 'incomplete', 'ewaste_parts_removed' => 'Battery, RAM, Hard disk',
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        $html = view('decommission.report-pdf', ['batch' => $batch->fresh(['vendor', 'items.asset'])])->render();

        $this->assertStringContainsString('parts removed: Battery, RAM, Hard disk', $html);
    }

    /**
     * The report carries the human-written `notes`, never the machine-appended `remarks`
     * audit log — that log ran to dozens of assign/return lines and buried the document.
     */
    public function test_report_shows_notes_and_never_the_remarks_audit_log(): void
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
        ]);
        $asset = AssetInventory::factory()->create([
            'asset_condition' => 'not_good',
            'decommission_batch_id' => $batch->id,
            'notes' => 'Screen delaminated after coffee spill.',
        ]);
        $asset->appendRemark('Asset assigned to Someone by IT Team.');
        $asset->appendRemark('Asset returned by Someone, processed by IT Team.');

        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type,
            'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
            'asset_condition' => 'not_good', 'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
            // Staging snapshots the asset's remarks — the report must still not print them.
            'remarks' => $asset->fresh()->remarks,
        ]);

        $html = view('decommission.report-pdf', ['batch' => $batch->fresh(['vendor', 'items.asset'])])->render();

        $this->assertStringContainsString('Screen delaminated after coffee spill.', $html);
        $this->assertStringNotContainsString('Remarks', $html);
        $this->assertStringNotContainsString('processed by IT Team', $html);
        // No timestamped audit-log line survived anywhere in the document.
        $this->assertDoesNotMatchRegularExpression('/\[\d{2}\/\d{2}\/\d{4}, \d{2}:\d{2} [AP]M\]/', $html);
    }

    /** Asset photos are embedded when the files exist, so the report shows the condition. */
    public function test_report_embeds_asset_photos_when_present(): void
    {
        Storage::fake('public');
        // Real PNG bytes — the report only embeds paths whose detected MIME is image/*.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Storage::disk('public')->put('asset_photos/tag/one.png', $png);

        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
        ]);
        $asset = AssetInventory::factory()->create([
            'asset_condition' => 'not_good',
            'decommission_batch_id' => $batch->id,
            'asset_photos' => ['asset_photos/tag/one.png'],
        ]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type,
            'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
            'asset_condition' => 'not_good', 'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        $html = view('decommission.report-pdf', ['batch' => $batch->fresh(['vendor', 'items.asset'])])->render();

        $this->assertStringContainsString('Asset Photos (1)', $html);
        $this->assertStringContainsString('<img class="photo" src="data:image/', $html);
        $this->assertStringNotContainsString('could not be embedded', $html);
    }

    /** A photo recorded but missing from disk is stated, not silently dropped. */
    public function test_report_says_so_when_a_recorded_photo_is_missing_from_disk(): void
    {
        Storage::fake('public');

        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
        ]);
        $asset = AssetInventory::factory()->create([
            'asset_condition' => 'not_good',
            'decommission_batch_id' => $batch->id,
            'asset_photos' => ['asset_photos/tag/gone.jpg'],
        ]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag, 'asset_type' => $asset->asset_type,
            'brand' => $asset->brand, 'model' => $asset->model, 'serial_number' => $asset->serial_number,
            'asset_condition' => 'not_good', 'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        $html = view('decommission.report-pdf', ['batch' => $batch->fresh(['vendor', 'items.asset'])])->render();

        $this->assertStringContainsString('1 photo(s) on record could not be embedded.', $html);
    }

    /** Base valid payload for PUT /assets/{asset} (only required fields). */
    private function assetUpdatePayload(AssetInventory $asset, array $overrides = []): array
    {
        return array_merge([
            'asset_tag' => $asset->asset_tag,
            'asset_category' => 'IT Equipment',
            'asset_type' => 'laptop',
            'brand' => 'Dell',
            'model' => 'Latitude',
            'serial_number' => 'SN-'.$asset->id,
            'ownership_type' => 'company',
            'status' => 'available',
            'asset_condition' => 'good',
        ], $overrides);
    }

    public function test_good_save_is_not_blocked_by_a_stale_incomplete_completeness(): void
    {
        // Reproduces the reviewer's scenario: the user toggled Not Good → Incomplete, then
        // switched back to Good; the hidden completeness select still submits "incomplete".
        $it = User::factory()->create(['role' => 'it_manager']);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'good']);

        $this->actingAs($it)->put(route('assets.update', $asset), $this->assetUpdatePayload($asset, [
            'asset_condition' => 'good',
            'ewaste_completeness' => 'incomplete',   // stale value from the hidden select
            'ewaste_parts_removed' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('good', $asset->fresh()->asset_condition);
        $this->assertSame(0, DisposedAsset::where('asset_inventory_id', $asset->id)->count());
    }

    /**
     * Superseded 2026-08-13 by the refined flow: completeness and the parts list moved off
     * this form onto the inspection (Phase 2), so the form must now IGNORE them rather than
     * validate them. The rule they used to enforce lives in EwasteDecommissionFlowTest.
     */
    public function test_the_asset_form_no_longer_sets_completeness_and_leaves_the_row_uninspected(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'good']);

        $this->actingAs($it)->put(route('assets.update', $asset), $this->assetUpdatePayload($asset, [
            'asset_condition' => 'not_good',
            'decommission_reason' => 'Water damage',
            // Posted by hand — no screen offers these any more, so they must not take effect.
            'ewaste_completeness' => 'incomplete',
            'ewaste_parts_removed' => 'Battery, RAM',
        ]))->assertSessionHasNoErrors();

        $staging = DisposedAsset::where('asset_inventory_id', $asset->id)->first();
        $this->assertNotNull($staging);
        $this->assertNull($staging->ewaste_completeness, 'Completeness may only be set by an inspection.');
        $this->assertNull($staging->ewaste_parts_removed);
        $this->assertFalse($staging->isInspected(), 'Marking an asset Not Good is not an inspection.');
    }
}
