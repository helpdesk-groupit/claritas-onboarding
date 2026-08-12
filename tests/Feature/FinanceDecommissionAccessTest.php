<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Accounting → Assets → status "Disposed" is the ONLY place Finance touches e-waste:
 * quotations are approved/rejected there, and finished cycles' reports are listed there.
 * No sidebar entries, no Assets sub-tabs, nothing under the Reports tab.
 */
class FinanceDecommissionAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
    }

    private function disposedUrl(): string
    {
        return route('accounting.fixed-assets.index', ['status' => 'disposed']);
    }

    private function batchWithAsset(string $number, string $type, bool $finalized): AssetDecommissionBatch
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => $number,
            'type' => $type,
            'status' => $finalized ? ($type === 'e_waste' ? 'completed' : 'acknowledged') : 'draft',
            'finalized_at' => $finalized ? now() : null,
            'vendor_id' => Vendor::create(['name' => 'Vendor '.$number, 'vendor_types' => ['ewaste'], 'is_active' => true])->id,
        ]);

        $asset = AssetInventory::factory()->create([
            'asset_tag' => 'ASSET-'.$number,
            'asset_condition' => $type === 'e_waste' ? 'not_good' : 'returned',
            'decommission_batch_id' => $batch->id,
        ]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => $asset->asset_condition,
            'decommission_type' => $type, 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        return $batch;
    }

    private function pendingQuotation(string $number = 'EWA-2026-Q4'): AssetDecommissionBatch
    {
        return AssetDecommissionBatch::create([
            'batch_number' => $number, 'type' => 'e_waste',
            'status' => 'quotation_uploaded', 'finance_status' => 'pending',
            'vendor_id' => Vendor::create(['name' => 'Quoting Vendor', 'vendor_types' => ['ewaste'], 'is_active' => true])->id,
        ]);
    }

    // ── The listing ──────────────────────────────────────────────────────────
    /**
     * The list is every e-waste cycle, in flight or finished — it is the record. Only vendor
     * returns are excluded (a rental going back to its owner is not a disposal).
     */
    public function test_disposed_status_lists_every_ewaste_cycle_not_just_finished_ones(): void
    {
        $finance = User::factory()->create(['role' => 'finance_executive']);
        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        $this->batchWithAsset('RET-2026-0001', 'vendor_return', true);   // rental going back — not a disposal
        $this->batchWithAsset('EWA-2026-Q2', 'e_waste', false);          // still in flight

        $this->actingAs($finance)->get($this->disposedUrl())
            ->assertOk()
            ->assertSee('E-waste Decommissioning Reports')
            ->assertSee('EWA-2026-Q1')
            ->assertSee('EWA-2026-Q2')
            ->assertDontSee('RET-2026-0001');
    }

    /**
     * Regression: approving a quotation moved finance_status off 'pending', which dropped the
     * cycle out of the pending-approval block — and the reports list was finalized-only, so it
     * never entered that either. The cycle was invisible on Finance's ONLY surface for the whole
     * collection window (approved → collected → paid), which reads as "my approval did nothing".
     */
    public function test_an_approved_cycle_awaiting_collection_is_still_listed(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);

        $batch = $this->batchWithAsset('EWA-2026-Q2', 'e_waste', false);
        $batch->update([
            'status' => 'finance_approved',
            'finance_status' => 'approved',
            'finance_reviewed_by' => $finance->id,
            'finance_reviewed_at' => now(),
        ]);

        $response = $this->actingAs($finance)->get($this->disposedUrl())->assertOk();

        // It is in neither the pending-approval block nor "completed" — so the record must show it.
        $response->assertSee('No quotations awaiting approval.')
            ->assertSee('EWA-2026-Q2')
            ->assertSee('Awaiting collection &amp; payment', false);
    }

    /**
     * An unfinished cycle's figure is the vendor's OFFER. It must never be counted as money
     * received, or the tile overstates income for the whole collection window.
     */
    public function test_an_unreceived_offer_is_not_counted_as_money_received(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);

        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true)->update(['receipt_amount' => 500]);
        $this->batchWithAsset('EWA-2026-Q2', 'e_waste', false)->update([
            'status' => 'finance_approved', 'finance_status' => 'approved', 'quotation_amount' => 900,
        ]);

        $this->actingAs($finance)->get($this->disposedUrl())
            ->assertOk()
            ->assertSee('RM 500.00')          // the completed cycle only
            ->assertDontSee('RM 1,400.00');   // never completed + the outstanding offer
    }

    public function test_report_rows_are_per_cycle_never_per_asset(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        // A second asset in the same cycle must NOT produce a second row.
        $extra = AssetInventory::factory()->create(['asset_tag' => 'EXTRA-LAPTOP', 'asset_condition' => 'not_good', 'decommission_batch_id' => $batch->id]);
        DisposedAsset::create([
            'asset_inventory_id' => $extra->id, 'asset_tag' => $extra->asset_tag,
            'asset_type' => $extra->asset_type, 'brand' => $extra->brand, 'model' => $extra->model,
            'serial_number' => $extra->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        $this->actingAs($finance)->get($this->disposedUrl())
            ->assertOk()
            ->assertSee('EWA-2026-Q1')
            ->assertDontSee('EXTRA-LAPTOP')
            ->assertDontSee('ASSET-EWA-2026-Q1');
    }

    public function test_other_statuses_leave_the_assets_page_untouched(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        $this->pendingQuotation();

        foreach ([[], ['status' => 'active']] as $params) {
            $this->actingAs($finance)->get(route('accounting.fixed-assets.index', $params))
                ->assertOk()
                ->assertDontSee('E-waste Decommissioning Reports')
                ->assertDontSee('Awaiting Approval')
                ->assertDontSee('EWA-2026-Q1');
        }
    }

    // ── The quotation workflow, inline on the same page ──────────────────────
    public function test_pending_quotations_render_with_approve_and_reject_controls(): void
    {
        $finance = User::factory()->create(['role' => 'finance_executive']);
        $batch = $this->pendingQuotation();

        $this->actingAs($finance)->get($this->disposedUrl())
            ->assertOk()
            ->assertSee('E-waste Quotations Awaiting Approval')
            ->assertSee('EWA-2026-Q4')
            ->assertSee(route('finance.ewaste.approve', $batch), false)
            ->assertSee('rejectModal'.$batch->id, false);
    }

    public function test_approving_from_the_assets_page_works_and_returns_there(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->pendingQuotation();

        $this->actingAs($finance)
            ->post(route('finance.ewaste.approve', $batch), ['remarks' => 'Offer accepted'])
            ->assertRedirect($this->disposedUrl());

        $this->assertSame('approved', $batch->fresh()->finance_status);
        $this->assertSame('finance_approved', $batch->fresh()->status);

        // Approved, so it drops off the pending list.
        $this->actingAs($finance)->get($this->disposedUrl())
            ->assertOk()
            ->assertSee('No quotations awaiting approval.');
    }

    public function test_rejecting_from_the_assets_page_requires_a_reason_then_returns_there(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->pendingQuotation();

        $this->actingAs($finance)
            ->post(route('finance.ewaste.reject', $batch), [])
            ->assertSessionHasErrors('remarks');
        $this->assertSame('pending', $batch->fresh()->finance_status);

        $this->actingAs($finance)
            ->post(route('finance.ewaste.reject', $batch), ['remarks' => 'Offer too low'])
            ->assertRedirect($this->disposedUrl());
        $this->assertSame('rejected', $batch->fresh()->finance_status);
    }

    public function test_a_viewer_who_cannot_approve_sees_reports_but_no_quotation_controls(): void
    {
        // hr_manager reaches Accounting and the decommission archive, but may not approve.
        $hr = User::factory()->create(['role' => 'hr_manager']);
        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        $this->pendingQuotation();

        $this->actingAs($hr)->get($this->disposedUrl())
            ->assertOk()
            ->assertSee('EWA-2026-Q1')
            // The pending-approval ACTION block is what they must not get.
            ->assertDontSee('E-waste Quotations Awaiting Approval')
            ->assertDontSee('Reject Quotation')
            // But the cycle itself belongs in the record they are allowed to read — they
            // simply can't act on it. Hiding it would tell them the disposal doesn't exist.
            ->assertSee('EWA-2026-Q4')
            ->assertSee('Pending Finance approval');
    }

    // ── Everything else is gone ──────────────────────────────────────────────
    public function test_finance_sidebar_has_no_decommissioning_or_pending_quotations_entries(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'finance_executive']))
            ->get(route('accounting.fixed-assets.index'))
            ->assertOk()
            ->assertDontSee('Pending Quotations')
            ->assertDontSee('Decommissioning');
    }

    public function test_non_finance_roles_keep_their_decommissioning_sidebar_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->get(route('assets.index'))
            ->assertOk()
            ->assertSee('Decommissioning');
    }

    public function test_reports_tab_carries_no_ewaste_entry(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->get(route('accounting.reports.trial-balance'))
            ->assertOk()
            ->assertDontSee('E-waste Quotations')
            ->assertDontSee('Financial Reports');
    }

    /**
     * The status filter is the ONLY way to reach the e-waste listing, so it must actually
     * submit. It used to carry `onchange="this.form.submit()"`, which the CSP blocks outright
     * — the policy ships 'unsafe-hashes' but lists no script hashes — so the select changed
     * and the page never reloaded, making the reports look permanently absent.
     */
    public function test_status_filter_submits_via_a_csp_safe_listener_not_an_inline_handler(): void
    {
        $res = $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->get(route('accounting.fixed-assets.index'))
            ->assertOk();

        $html = $res->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/id="assetStatusSelect"[^>]*onchange/',
            $html,
            'The status filter must not use an inline onchange handler — CSP blocks it.'
        );
        $this->assertStringContainsString("getElementById('assetStatusSelect')", $html);
        $this->assertMatchesRegularExpression(
            '/<script nonce="[^"]+">\s*\(function \(\) \{\s*var sel = document\.getElementById/',
            $html,
            'The listener must sit in a nonce-protected <script> block.'
        );
    }

    public function test_the_old_standalone_quotation_urls_are_gone(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);

        foreach (['/finance/ewaste-quotations', '/accounting/reports/ewaste-quotations'] as $dead) {
            $this->actingAs($finance)->get($dead)->assertNotFound();
        }
    }
}
