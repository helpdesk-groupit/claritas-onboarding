<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\RentalAssetAcknowledgement;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Who may drive the IT side of decommissioning — one gate, User::canManageDecommission().
 *
 * it_executive was added 2026-07-30: the day-to-day IT operator, not just the manager, runs
 * collection batches and works the e-waste cycle. it_intern stays out. Both roles can still
 * READ the Decommissioning tab (canViewAssets), so the tests below distinguish "can see the
 * staged list" from "can act on it" — that separation is the whole point of the gate.
 */
class ItDecommissionAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
    }

    /**
     * A returned rental staged in the Decommissioning queue, already linked to the vendor it
     * goes back to — the vendor is detected from the asset, not chosen at batch time.
     */
    private function stagedReturn(): array
    {
        $vendor = Vendor::create([
            'name' => 'Rental Co', 'vendor_types' => ['rental'], 'is_active' => true,
            'pic_name' => 'Vendor PIC', 'pic_email' => 'pic@rental.test',
        ]);

        $asset = AssetInventory::factory()->create([
            'asset_tag' => 'RENTAL-001',
            'asset_condition' => 'returned',
            'ownership_type' => 'rental',
            'vendor_id' => $vendor->id,
            'company_supplied_to' => 'Claritas Asia',
        ]);

        $row = DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'returned',
            'decommission_type' => 'vendor_return', 'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        return [$row, $vendor];
    }

    // ── IT Executive: allowed ────────────────────────────────────────────────
    public function test_it_executive_can_create_a_collection_batch(): void
    {
        [$row, $vendor] = $this->stagedReturn();

        $this->actingAs(User::factory()->create(['role' => 'it_executive']))
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]])
            ->assertRedirect();

        $aarf = RentalAssetAcknowledgement::where('type', 'return')->first();
        $this->assertNotNull($aarf, 'An it_executive must be able to raise a rental return form.');
        $this->assertSame($vendor->id, $aarf->vendor_id);
    }

    /** The Create Collection Batch button + sweep form must actually render for them. */
    public function test_it_executive_sees_the_decommissioning_action_bar(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'it_executive']))
            ->get(route('assets.index'))
            ->assertOk()
            ->assertSee('id="createBatchBtn"', false)
            ->assertSee(route('ewaste.sweep'), false);
    }

    public function test_it_executive_can_run_the_ewaste_sweep_and_open_a_batch(): void
    {
        $executive = User::factory()->create(['role' => 'it_executive']);

        // The sweep is authorized regardless of whether it finds anything to gather; either
        // outcome is a redirect, never a 403.
        $this->actingAs($executive)->post(route('ewaste.sweep'))->assertRedirect();

        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste', 'status' => 'awaiting_quotation',
            'vendor_id' => Vendor::create(['name' => 'E-waste Co', 'vendor_types' => ['ewaste'], 'is_active' => true])->id,
        ]);

        $this->actingAs($executive)->get(route('decommission.show', $batch))->assertOk();
    }

    // ── IT Intern: still blocked ─────────────────────────────────────────────
    public function test_it_intern_cannot_act_on_any_decommission_endpoint(): void
    {
        [$row] = $this->stagedReturn();
        $intern = User::factory()->create(['role' => 'it_intern']);

        $this->actingAs($intern)
            ->post(route('decommission.returns.generate'), ['dispose_ids' => [$row->id]])
            ->assertForbidden();
        $this->assertSame(0, RentalAssetAcknowledgement::count());

        $this->actingAs($intern)->post(route('ewaste.sweep'))->assertForbidden();
    }

    /**
     * An intern may still READ the staged list (canViewAssets) — they just get no controls.
     * Hiding the tab would tell them the assets don't exist; showing the button would 403.
     */
    public function test_it_intern_reads_the_tab_but_gets_no_controls(): void
    {
        $this->stagedReturn();

        // Assert on the CONTROLS, not the phrase "Create Collection Batch" — that string also
        // appears in a JS comment in the page's shared script block, which every role receives
        // (the block self-guards on the button existing, so it is inert for a viewer).
        $this->actingAs(User::factory()->create(['role' => 'it_intern']))
            ->get(route('assets.index'))
            ->assertOk()
            ->assertSee('Decommissioning Assets')
            ->assertSee('RENTAL-001')
            ->assertSee('This list is read-only for your role.')
            ->assertDontSee('id="createBatchBtn"', false)
            ->assertDontSee(route('ewaste.sweep'), false);
    }

    // ── The boundaries that did NOT move ─────────────────────────────────────
    /**
     * Vendor Management was it_manager+admin when this suite was written (2026-07-30) and
     * it_executive was asserted OUT. That boundary moved on 2026-08-06, when the table
     * became the company-wide vendor master: the asset listing already linked to
     * /vendors from a block gated on canManageDecommission() — which includes
     * it_executive — so the role was being shown a link that 403'd. The assertion is
     * inverted rather than deleted so the reversal stays visible here.
     * Interns are the boundary that genuinely did not move; see the next test.
     */
    public function test_it_executive_can_now_reach_vendor_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'it_executive']))
            ->get(route('vendors.index'))
            ->assertOk();
    }

    /** Interns drive no part of either flow and see no vendor commercial data. */
    public function test_it_intern_still_cannot_reach_vendor_management(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'it_intern']))
            ->get(route('vendors.index'))
            ->assertForbidden();
    }

    /** Finance holds the only remarks step; driving the IT flow does not grant it. */
    public function test_it_executive_cannot_leave_finance_remarks_on_an_ewaste_quotation(): void
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q4', 'type' => 'e_waste',
            'status' => 'quotation_uploaded', 'finance_status' => 'pending',
            'vendor_id' => Vendor::create(['name' => 'Quoting Vendor', 'vendor_types' => ['ewaste'], 'is_active' => true])->id,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'it_executive']))
            ->post(route('finance.ewaste.remark', $batch), ['remarks' => 'ok'])
            ->assertForbidden();

        $this->assertSame('pending', $batch->fresh()->finance_status);
    }
}
