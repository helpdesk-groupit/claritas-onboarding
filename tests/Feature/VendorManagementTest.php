<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
    }

    public function test_it_manager_can_create_vendor(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);

        $response = $this->actingAs($it)->post(route('vendors.store'), [
            'name' => 'TechLease Sdn Bhd',
            'vendor_types' => ['rental'],
            'pic_name' => 'Lim',
            'pic_email' => 'boss@techlease.com',
            'is_active' => '1',
        ]);

        $vendor = Vendor::where('name', 'TechLease Sdn Bhd')->first();
        $this->assertNotNull($vendor);
        // Creating now lands on the vendor's own profile — the page that shows contracts,
        // billing and assets — rather than back on the directory.
        $response->assertRedirect(route('vendors.show', $vendor));
        $this->assertDatabaseHas('vendors', ['name' => 'TechLease Sdn Bhd', 'is_active' => 1]);
    }

    public function test_single_primary_ewaste_vendor_is_enforced(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $first = Vendor::create(['name' => 'RecycleA', 'vendor_types' => ['ewaste'], 'is_primary_ewaste' => true, 'is_active' => true]);

        $this->actingAs($it)->post(route('vendors.store'), [
            'name' => 'RecycleB',
            'vendor_types' => ['ewaste'],
            'is_primary_ewaste' => '1',
            'is_active' => '1',
        ]);

        $this->assertFalse($first->fresh()->is_primary_ewaste);            // demoted
        $this->assertSame('RecycleB', Vendor::primaryEwaste()->name);      // the only primary
    }

    public function test_primary_flag_ignored_when_ewaste_not_selected(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $this->actingAs($it)->post(route('vendors.store'), [
            'name' => 'RentalOnly',
            'vendor_types' => ['rental'],
            'is_primary_ewaste' => '1',   // ignored — not an e-waste vendor
            'is_active' => '1',
        ]);

        $v = Vendor::where('name', 'RentalOnly')->first();
        $this->assertFalse($v->is_primary_ewaste);
    }

    /**
     * Regression: Finance was locked out on 2026-07-29, when this table was only the
     * rental/repair/e-waste list the decommissioning flows read a PIC from. It is now the
     * company-wide vendor master holding contracts, billing and SST identity — Finance's
     * own subject matter — so the exclusion was reversed on 2026-08-06. The assertion is
     * kept (inverted) rather than deleted so the reversal is visible in the test history.
     */
    public function test_finance_can_view_and_manage_vendors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'finance_executive']))
            ->get(route('vendors.index'))->assertOk();

        $financeManager = User::factory()->create(['role' => 'finance_manager']);
        $this->actingAs($financeManager)->get(route('vendors.index'))->assertOk();

        $this->actingAs($financeManager)->post(route('vendors.store'), [
            'name' => 'Finance Registered Supplier',
            'vendor_types' => ['professional'],
            'is_active' => '1',
        ]);
        $this->assertDatabaseHas('vendors', ['name' => 'Finance Registered Supplier']);
    }

    public function test_it_manager_can_view_vendors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->get(route('vendors.index'))->assertOk();
    }

    public function test_it_executive_can_view_vendors(): void
    {
        // The asset listing links to Vendor Management from a block gated on
        // canManageDecommission(), which includes it_executive — before 2026-08-06 that
        // link 403'd for exactly the role it was shown to.
        $this->actingAs(User::factory()->create(['role' => 'it_executive']))
            ->get(route('vendors.index'))->assertOk();
    }

    public function test_it_intern_cannot_view_vendors(): void
    {
        $intern = User::factory()->create(['role' => 'it_intern']);
        $this->actingAs($intern)->get(route('vendors.index'))->assertForbidden();
    }

    public function test_employee_cannot_view_vendors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'employee']))
            ->get(route('vendors.index'))->assertForbidden();
    }

    public function test_toggle_active_deactivates_and_clears_primary(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $v = Vendor::create(['name' => 'RecycleCo', 'vendor_types' => ['ewaste'], 'is_primary_ewaste' => true, 'is_active' => true]);

        $this->actingAs($it)
            ->from(route('vendors.index'))
            ->post(route('vendors.toggle-active', $v))
            ->assertRedirect(route('vendors.index'));
        $v->refresh();
        $this->assertFalse($v->is_active);
        $this->assertFalse($v->is_primary_ewaste);   // inactive can't be the RFQ target
    }

    /**
     * Two rows for the same vendor is the failure mode that makes a master useless: one
     * ends up carrying the contracts and the other the assets, and neither view is wrong.
     */
    public function test_duplicate_vendor_name_is_rejected(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        Vendor::create(['name' => 'Acme Supplies', 'vendor_types' => ['purchase'], 'is_active' => true]);

        $this->actingAs($it)
            ->from(route('vendors.create'))
            ->post(route('vendors.store'), [
                'name' => 'Acme Supplies',
                'vendor_types' => ['purchase'],
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Vendor::where('name', 'Acme Supplies')->count());
    }

    public function test_editing_a_vendor_does_not_trip_its_own_unique_name(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $v = Vendor::create(['name' => 'Acme Supplies', 'vendor_types' => ['purchase'], 'is_active' => true]);

        $this->actingAs($it)->put(route('vendors.update', $v), [
            'name' => 'Acme Supplies',
            'vendor_types' => ['purchase', 'repair'],
            'is_active' => '1',
        ])->assertRedirect(route('vendors.show', $v));

        $this->assertEqualsCanonicalizing(['purchase', 'repair'], $v->fresh()->vendor_types);
    }

    public function test_full_vendor_master_fields_are_stored(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);

        $this->actingAs($it)->post(route('vendors.store'), [
            'name' => 'Full Details Sdn Bhd',
            'vendor_types' => ['purchase', 'it_services'],
            'industry' => 'it_hardware',
            'company_registration_no' => '202301012345',
            'sst_number' => 'W10-1234-56789012',
            'sst_category' => 'professional',
            'address' => '12 Jalan Satu, 50000 Kuala Lumpur',
            'contact_number' => '03-1234 5678',
            'email' => 'hello@fulldetails.com',
            'website' => 'fulldetails.com',
            'pic_name' => 'Aisyah',
            'pic_email' => 'aisyah@fulldetails.com',
            'pic_phone' => '012-345 6789',
            'technical_person_name' => 'Ravi',
            'technical_person_email' => 'ravi@fulldetails.com',
            'technical_person_phone' => '012-999 8888',
            'tin_number' => 'C12345678901',
            'bank_name' => 'Maybank',
            'bank_account_name' => 'Full Details Sdn. Bhd.',
            'bank_account_number' => '512345678901',
            'bank_branch' => 'Jalan Tun Perak',
            'bank_swift' => 'MBBEMYKL',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vendors', [
            'name' => 'Full Details Sdn Bhd',
            'industry' => 'it_hardware',
            'sst_number' => 'W10-1234-56789012',
            'sst_category' => 'professional',
            'contact_number' => '03-1234 5678',
            'technical_person_name' => 'Ravi',
            'technical_person_phone' => '012-999 8888',
            'tin_number' => 'C12345678901',
            'bank_name' => 'Maybank',
            'bank_account_name' => 'Full Details Sdn. Bhd.',
            'bank_account_number' => '512345678901',
            'bank_branch' => 'Jalan Tun Perak',
            'bank_swift' => 'MBBEMYKL',
        ]);
    }

    /**
     * The bank name is free text, not a whitelist — a foreign supplier banks somewhere no
     * Malaysian list carries, and rejecting it would push the real bank into the notes
     * field where no payment screen can read it.
     */
    public function test_a_bank_outside_the_suggestion_list_is_accepted(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);

        $this->actingAs($it)->post(route('vendors.store'), [
            'name' => 'Overseas Software Ltd',
            'vendor_types' => ['software'],
            'bank_name' => 'DBS Bank (Singapore)',
            'bank_account_number' => '003-901234-5',
            'bank_swift' => 'DBSSSGSG',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('vendors', [
            'name' => 'Overseas Software Ltd',
            'bank_name' => 'DBS Bank (Singapore)',
            'bank_swift' => 'DBSSSGSG',
        ]);
        $this->assertNotContains('DBS Bank (Singapore)', Vendor::BANK_SUGGESTIONS);
    }

    /** Bank details survive an edit that doesn't touch them, and can be corrected. */
    public function test_bank_details_can_be_updated(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $v = Vendor::create([
            'name' => 'Acme Supplies',
            'vendor_types' => ['purchase'],
            'bank_name' => 'CIMB Bank',
            'bank_account_number' => '111122223333',
            'tin_number' => 'C99999999999',
            'is_active' => true,
        ]);

        $this->actingAs($it)->put(route('vendors.update', $v), [
            'name' => 'Acme Supplies',
            'vendor_types' => ['purchase'],
            'bank_name' => 'Public Bank',
            'bank_account_number' => '444455556666',
            'bank_account_name' => 'Acme Supplies Sdn Bhd',
            'tin_number' => 'C99999999999',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $fresh = $v->fresh();
        $this->assertSame('Public Bank', $fresh->bank_name);
        $this->assertSame('444455556666', $fresh->bank_account_number);
        $this->assertSame('Acme Supplies Sdn Bhd', $fresh->bank_account_name);
        $this->assertSame('C99999999999', $fresh->tin_number);
    }

    // ── Delete ───────────────────────────────────────────────────────────────
    /** The row that is not history — a duplicate or a typo — can be removed outright. */
    public function test_a_vendor_that_references_nothing_can_be_deleted(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $v = Vendor::create(['name' => 'Typo Sdn Bhd', 'vendor_types' => ['purchase'], 'is_active' => true]);

        $this->actingAs($it)->delete(route('vendors.destroy', $v))
            ->assertRedirect(route('vendors.index'));

        $this->assertDatabaseMissing('vendors', ['id' => $v->id]);
    }

    /**
     * vendor_contracts / vendor_billing_documents / rental_asset_acknowledgements are all
     * cascadeOnDelete, so an unguarded delete would take a signed acknowledgement or a filed
     * invoice with it and say nothing. The refusal has to name what is attached, because the
     * operator's next move ("deactivate instead") depends on knowing this is not a bug.
     */
    public function test_a_vendor_with_a_contract_is_refused_and_the_contract_survives(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $v = Vendor::create(['name' => 'Has History Sdn Bhd', 'vendor_types' => ['rental'], 'is_active' => true]);
        $contract = \App\Models\VendorContract::create([
            'vendor_id' => $v->id, 'title' => 'Laptop lease', 'status' => 'active',
        ]);

        $this->actingAs($it)
            ->from(route('vendors.show', $v))
            ->delete(route('vendors.destroy', $v))
            ->assertRedirect(route('vendors.show', $v))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '1 contract') && str_contains($m, 'Deactivate'));

        $this->assertDatabaseHas('vendors', ['id' => $v->id]);
        $this->assertDatabaseHas('vendor_contracts', ['id' => $contract->id]);
    }

    public function test_a_vendor_holding_assets_cannot_be_deleted(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $v = Vendor::create(['name' => 'Kit Supplier', 'vendor_types' => ['rental'], 'is_active' => true]);
        \App\Models\AssetInventory::create([
            'asset_tag' => 'AST-DEL-1',
            'asset_category' => 'it_equipment',
            'asset_type' => 'laptop',
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
            'vendor_id' => $v->id,
        ]);

        $this->actingAs($it)->delete(route('vendors.destroy', $v))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '1 linked asset'));

        $this->assertDatabaseHas('vendors', ['id' => $v->id]);
    }

    public function test_a_read_only_role_cannot_delete_a_vendor(): void
    {
        $intern = User::factory()->create(['role' => 'it_intern']);
        $v = Vendor::create(['name' => 'Untouchable', 'vendor_types' => ['purchase'], 'is_active' => true]);

        $this->actingAs($intern)->delete(route('vendors.destroy', $v))->assertForbidden();

        $this->assertDatabaseHas('vendors', ['id' => $v->id]);
    }

    /** A blocked vendor is shown the control disabled, not a live button that will bounce. */
    public function test_the_listing_disables_delete_for_a_vendor_with_history(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $clean = Vendor::create(['name' => 'Aaa Clean Vendor', 'vendor_types' => ['purchase'], 'is_active' => true]);
        $held = Vendor::create(['name' => 'Bbb Held Vendor', 'vendor_types' => ['rental'], 'is_active' => true]);
        \App\Models\VendorContract::create(['vendor_id' => $held->id, 'title' => 'Lease', 'status' => 'active']);

        $html = $this->actingAs($it)->get(route('vendors.index'))->assertOk()->getContent();

        // The delete URI is the vendor's own URL under a different verb, so it also appears
        // as the Profile link — count the submitting FORMS instead. Exactly one of the two
        // rows may delete.
        $this->assertSame(1, substr_count($html, 'data-confirm-title="Delete vendor"'));
        $this->assertStringContainsString('Cannot delete — 1 contract on record', $html);
        $this->assertStringContainsString($clean->name, $html);
        $this->assertStringContainsString($held->name, $html);
    }

    /**
     * deletionBlockers() reads a withCount attribute when there is one and counts per
     * relation when there isn't — correct either way, but the fallback is five queries PER
     * ROW on a 20-row directory. This pins the count keys to the relation names so a rename
     * shows up here rather than as a page that quietly got slower.
     */
    public function test_the_directory_counts_deletion_blockers_in_the_page_query(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        Vendor::create(['name' => 'Counted Sdn Bhd', 'vendor_types' => ['purchase'], 'is_active' => true]);

        $listed = $this->actingAs($it)->get(route('vendors.index'))->viewData('vendors')->first();

        foreach (Vendor::DELETE_BLOCKERS as $relation => $meta) {
            $this->assertArrayHasKey(
                $meta[0],
                $listed->getAttributes(),
                "withCount('{$relation}') does not produce '{$meta[0]}' — the directory has gone N+1."
            );
        }
    }

    /**
     * Editing moved to the vendor's own Profile page — the only screen that shows what the
     * change affects. A pencil back on the row is the regression this pins.
     */
    public function test_the_listing_offers_no_per_row_edit_control(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        $v = Vendor::create(['name' => 'Editable Sdn Bhd', 'vendor_types' => ['purchase'], 'is_active' => true]);

        $html = $this->actingAs($it)->get(route('vendors.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('vendors.edit', $v), $html);

        // ...and it is still reachable where it was moved to.
        $this->actingAs($it)->get(route('vendors.show', $v))->assertOk()
            ->assertSee(route('vendors.edit', $v), false);
    }

    /** The directory search covers the TIN, as it already does the reg. no and SST no. */
    public function test_the_directory_search_finds_a_vendor_by_its_tin(): void
    {
        $it = User::factory()->create(['role' => 'it_manager']);
        Vendor::create(['name' => 'Findable Sdn Bhd', 'vendor_types' => ['purchase'], 'tin_number' => 'C55501234567', 'is_active' => true]);
        Vendor::create(['name' => 'Other Sdn Bhd', 'vendor_types' => ['purchase'], 'tin_number' => 'C77709876543', 'is_active' => true]);

        $this->actingAs($it)->get(route('vendors.index', ['search' => 'C55501234567']))
            ->assertOk()
            ->assertSee('Findable Sdn Bhd')
            ->assertDontSee('Other Sdn Bhd');
    }
}
