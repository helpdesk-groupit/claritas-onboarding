<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\EwasteCompanyApprover;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Management → Decommissioning is the ONLY place Finance and management touch e-waste:
 * the comparison is reviewed there, both decisions are cast there, and the cycles' reports
 * are listed there.
 *
 * It was Accounting → Assets → status "Disposed" (Finance) plus the IT cycle page
 * (management) until 2026-08-14. Those two surfaces are gone, and several tests below exist
 * to keep them gone — a second place to approve one disposal is what this replaced.
 */
class FinanceDecommissionAccessTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = 'Claritas Asia Sdn Bhd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
    }

    private function reviewUrl(): string
    {
        return route('reports.decommission');
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
            'company' => self::COMPANY,
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
            // `pending_approval` since Phase 5 — `quotation_uploaded` now means offers are
            // still being collected and nothing has been put up for review.
            'batch_number' => $number, 'type' => 'e_waste',
            'status' => 'pending_approval', 'finance_status' => 'pending', 'management_status' => 'pending',
            'company' => self::COMPANY,
            'vendor_id' => Vendor::create(['name' => 'Quoting Vendor', 'vendor_types' => ['ewaste'], 'is_active' => true])->id,
        ]);
    }

    // ── The listing ──────────────────────────────────────────────────────────
    /**
     * The list is every e-waste cycle, in flight or finished — it is the record. Only vendor
     * returns are excluded (a rental going back to its owner is not a disposal).
     */
    public function test_decommissioning_lists_every_ewaste_cycle_not_just_finished_ones(): void
    {
        $finance = User::factory()->create(['role' => 'finance_executive']);
        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        $this->batchWithAsset('RET-2026-0001', 'vendor_return', true);   // rental going back — not a disposal
        $this->batchWithAsset('EWA-2026-Q2', 'e_waste', false);          // still in flight

        $this->actingAs($finance)->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('EWA-2026-Q1')
            ->assertSee('EWA-2026-Q2')
            ->assertDontSee('RET-2026-0001');
    }

    /**
     * Regression, carried over from the Accounting page: approving a quotation moves
     * finance_status off 'pending', which drops the cycle out of the awaiting-decision block.
     * If the list below it were finalized-only the cycle would be invisible for the whole
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

        $this->actingAs($finance)->get($this->reviewUrl())
            ->assertOk()
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

        $this->actingAs($finance)->get($this->reviewUrl())
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

        $this->actingAs($finance)->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('EWA-2026-Q1')
            ->assertDontSee('EXTRA-LAPTOP')
            ->assertDontSee('ASSET-EWA-2026-Q1');
    }

    // ── The quotation workflow, on the review page ───────────────────────────
    public function test_pending_quotations_render_with_approve_and_reject_controls(): void
    {
        $finance = User::factory()->create(['role' => 'finance_executive']);
        $batch = $this->pendingQuotation();

        $this->actingAs($finance)->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('Awaiting your decision')
            ->assertSee('EWA-2026-Q4')
            ->assertSee(route('finance.ewaste.approve', $batch), false)
            ->assertSee(route('finance.ewaste.reject', $batch), false);
    }

    public function test_approving_from_the_review_page_works_and_returns_there(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->pendingQuotation();

        $this->actingAs($finance)
            ->post(route('finance.ewaste.approve', $batch), ['remarks' => 'Offer accepted'])
            ->assertRedirect($this->reviewUrl());

        $this->assertSame('approved', $batch->fresh()->finance_status);
        // Their approval is a recorded position, not the release: management authorise the
        // disposal, so the cycle is still awaiting a decision.
        $this->assertSame('pending_approval', $batch->fresh()->status);

        // Decided, so it drops out of the awaiting-decision block — but stays in the list.
        $this->actingAs($finance)->get($this->reviewUrl())
            ->assertOk()
            ->assertDontSee('Awaiting your decision')
            ->assertSee('EWA-2026-Q4');
    }

    public function test_rejecting_from_the_review_page_requires_a_reason_then_returns_there(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $batch = $this->pendingQuotation();

        $this->actingAs($finance)
            ->post(route('finance.ewaste.reject', $batch), [])
            ->assertSessionHasErrors('remarks');
        $this->assertSame('pending', $batch->fresh()->finance_status);

        $this->actingAs($finance)
            ->post(route('finance.ewaste.reject', $batch), ['remarks' => 'Offer too low'])
            ->assertRedirect($this->reviewUrl());
        $this->assertSame('rejected', $batch->fresh()->finance_status);
    }

    public function test_a_viewer_who_cannot_approve_sees_the_record_but_no_controls(): void
    {
        // hr_manager reads the decommission archive but may approve nothing.
        $hr = User::factory()->create(['role' => 'hr_manager']);
        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        $batch = $this->pendingQuotation();

        $this->actingAs($hr)->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('EWA-2026-Q1')
            // The decision block is what they must not get.
            ->assertDontSee('Awaiting your decision')
            ->assertDontSee(route('finance.ewaste.approve', $batch), false)
            ->assertDontSee(route('management.ewaste.approve', $batch), false)
            // But the cycle itself belongs in the record they are allowed to read — they
            // simply can't act on it. Hiding it would tell them the disposal doesn't exist.
            ->assertSee('EWA-2026-Q4')
            ->assertSee('Pending approval');
    }

    // ── Management: named approvers, scoped per company ──────────────────────
    /**
     * A named approver holds none of the report roles, so the page they are emailed a link to
     * has to admit them on the strength of being named — otherwise the approval request lands
     * on a 403.
     */
    public function test_a_named_approver_with_no_other_role_can_reach_the_page_and_decide(): void
    {
        $ceo = User::factory()->create(['role' => 'employee']);
        EwasteCompanyApprover::create(['company' => self::COMPANY, 'user_id' => $ceo->id]);
        $batch = $this->pendingQuotation();

        $this->actingAs($ceo)->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('Awaiting your decision')
            ->assertSee(route('management.ewaste.approve', $batch), false)
            // Finance's controls are not theirs.
            ->assertDontSee(route('finance.ewaste.approve', $batch), false);

        $this->actingAs($ceo)
            ->post(route('management.ewaste.approve', $batch), ['remarks' => 'Approved'])
            ->assertRedirect(route('decommission.show', $batch));

        $this->assertSame('approved', $batch->fresh()->management_status);
        $this->assertSame('approved', $batch->fresh()->status);
    }

    /**
     * Another entity's disposal is not theirs to read — the same rule that decides who a signed
     * AARF is copied to. Their authority is per-company, so the list must match it.
     */
    public function test_a_named_approver_sees_only_their_own_companys_cycles(): void
    {
        $ceo = User::factory()->create(['role' => 'employee']);
        EwasteCompanyApprover::create(['company' => 'Enlinea Sdn Bhd', 'user_id' => $ceo->id]);

        $theirs = $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        $theirs->update(['company' => 'Enlinea Sdn Bhd']);
        $others = $this->batchWithAsset('EWA-2026-Q2', 'e_waste', true);   // Claritas

        $this->actingAs($ceo)->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('EWA-2026-Q1')
            ->assertDontSee('EWA-2026-Q2');

        // The listing is filtered, so the document behind it must be too — the id is in the URL.
        $this->actingAs($ceo)->get(route('reports.decommission.pdf', $others))->assertForbidden();
    }

    /** A role-holder is not scoped: Finance and IT own the process across the group. */
    public function test_finance_still_sees_every_companys_cycles(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true)->update(['company' => 'Enlinea Sdn Bhd']);
        $this->batchWithAsset('EWA-2026-Q2', 'e_waste', true);

        $this->actingAs($finance)->get($this->reviewUrl())
            ->assertOk()
            ->assertSee('EWA-2026-Q1')
            ->assertSee('EWA-2026-Q2');
    }

    // ── The displaced surfaces stay gone ─────────────────────────────────────
    /**
     * Accounting → Assets → "Disposed" reverts to a plain asset listing. A second place to
     * review a quotation is exactly what moving it here was undoing.
     */
    public function test_the_disposed_assets_page_carries_no_ewaste_blocks(): void
    {
        $finance = User::factory()->create(['role' => 'finance_manager']);
        $this->batchWithAsset('EWA-2026-Q1', 'e_waste', true);
        $batch = $this->pendingQuotation();

        $this->actingAs($finance)->get($this->disposedUrl())
            ->assertOk()
            ->assertDontSee('E-waste Decommissioning Reports')
            ->assertDontSee('E-waste Quotations Awaiting Approval')
            ->assertDontSee('EWA-2026-Q1')
            ->assertDontSee('EWA-2026-Q4')
            ->assertDontSee(route('finance.ewaste.approve', $batch), false);
    }

    /** The IT cycle page is IT's working surface — management decide on Decommissioning. */
    public function test_the_cycle_page_offers_management_no_decision_control(): void
    {
        $ceo = User::factory()->create(['role' => 'employee']);
        EwasteCompanyApprover::create(['company' => self::COMPANY, 'user_id' => $ceo->id]);
        $batch = $this->pendingQuotation();

        $this->actingAs($ceo)->get(route('decommission.show', $batch))
            ->assertOk()
            // Readable — it is the cycle their decision concerns…
            ->assertSee('EWA-2026-Q4')
            // …but the control is not here. Asserted on the form action, not prose: the page
            // legitimately mentions the decision in its timeline.
            ->assertDontSee(route('management.ewaste.approve', $batch), false)
            ->assertDontSee(route('management.ewaste.reject', $batch), false)
            // Instead it says where to go.
            ->assertSee('Review on Decommissioning');
    }

    public function test_finance_now_has_the_decommissioning_sidebar_link(): void
    {
        // Reversed on 2026-08-14: this page is Finance's only route to a quotation decision,
        // so hiding the link would leave the approval email pointing somewhere unreachable.
        $this->actingAs(User::factory()->create(['role' => 'finance_executive']))
            ->get(route('accounting.fixed-assets.index'))
            ->assertOk()
            ->assertSee(route('reports.decommission'), false);
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
     * The status filter must actually submit. It used to carry `onchange="this.form.submit()"`,
     * which the CSP blocks outright — the policy ships 'unsafe-hashes' but lists no script
     * hashes — so the select changed and the page never reloaded.
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
