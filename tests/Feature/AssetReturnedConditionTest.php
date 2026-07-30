<?php

namespace Tests\Feature;

use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cover for the "Returned" asset condition (Section E on the asset edit form).
 *
 * A returned rental asset leaves the active inventory the same way a Not Good one
 * does, but it is going back to its vendor rather than being scrapped — so the
 * staging row records which flow it left by, and the Decommissioning tab says so.
 */
class AssetReturnedConditionTest extends TestCase
{
    use RefreshDatabase;

    private User $itManager;

    protected function setUp(): void
    {
        parent::setUp();
        // withTwoFactor() bypasses the force-2FA-enrollment middleware.
        $this->itManager = User::factory()->itManager()->withTwoFactor()->create();
    }

    private function asset(array $overrides = []): AssetInventory
    {
        return AssetInventory::create(array_merge([
            'asset_tag'       => 'IT-LT-0001',
            'asset_category'  => 'IT Equipment',
            'asset_type'      => 'laptop',
            'brand'           => 'Dell',
            'model'           => 'Latitude 5440',
            'serial_number'   => 'SN-RETURN-1',
            'status'          => 'available',
            'ownership_type'  => 'rental',
            'rental_vendor'   => 'Acme Leasing',
            'asset_condition' => 'good',
        ], $overrides));
    }

    /** The payload the edit form posts, so validation sees a complete Section A–E. */
    private function payload(AssetInventory $asset, array $overrides = []): array
    {
        return array_merge([
            'asset_tag'       => $asset->asset_tag,
            'asset_category'  => $asset->asset_category,
            'asset_type'      => $asset->asset_type,
            'brand'           => $asset->brand,
            'model'           => $asset->model,
            'serial_number'   => $asset->serial_number,
            'status'          => 'unavailable',
            'ownership_type'  => $asset->ownership_type,
            'asset_condition' => 'returned',
        ], $overrides);
    }

    public function test_returned_is_an_offered_condition_on_the_edit_form(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager)
            ->get(route('assets.edit', ['asset' => $asset->id]))
            ->assertOk()
            ->assertSee('value="returned"', false);
    }

    public function test_setting_condition_to_returned_stages_the_asset_as_a_vendor_return(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager)
            ->put(route('assets.update', ['asset' => $asset->id]), $this->payload($asset, [
                'decommission_reason' => 'End of lease',
            ]))
            ->assertRedirect();

        $staged = DisposedAsset::where('asset_inventory_id', $asset->id)->first();

        $this->assertNotNull($staged, 'A returned asset must appear in the Decommissioning queue.');
        $this->assertSame('vendor_return', $staged->decommission_type);
        $this->assertSame('returned', $staged->asset_condition);
        $this->assertSame('End of lease', $staged->reason);
        $this->assertTrue($staged->isVendorReturn());
        $this->assertFalse($staged->isEwaste());
    }

    public function test_not_good_still_stages_as_ewaste(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager)
            ->put(route('assets.update', ['asset' => $asset->id]), $this->payload($asset, [
                'asset_condition'     => 'not_good',
                'decommission_reason' => 'Screen cracked beyond repair',
            ]))
            ->assertRedirect();

        $staged = DisposedAsset::where('asset_inventory_id', $asset->id)->first();

        $this->assertNotNull($staged);
        $this->assertSame('e_waste', $staged->decommission_type);
        $this->assertTrue($staged->isEwaste());
    }

    /**
     * The whole point of staging: the asset must not be counted or listed as active
     * stock while it is also sitting in the Decommissioning tab.
     */
    public function test_a_returned_asset_leaves_the_active_listing(): void
    {
        $returned = $this->asset(['asset_tag' => 'IT-LT-RET', 'serial_number' => 'SN-RET', 'asset_condition' => 'returned']);
        $good     = $this->asset(['asset_tag' => 'IT-LT-GOOD', 'serial_number' => 'SN-GOOD']);

        $this->actingAs($this->itManager)
            ->get(route('assets.index'))
            ->assertOk()
            ->assertSee($good->asset_tag)
            ->assertDontSee($returned->asset_tag);
    }

    public function test_the_decommissioning_tab_labels_a_returned_asset_as_a_return(): void
    {
        $asset = $this->asset(['asset_condition' => 'returned']);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id,
            'asset_tag'          => $asset->asset_tag,
            'asset_type'         => $asset->asset_type,
            'brand'              => $asset->brand,
            'model'              => $asset->model,
            'serial_number'      => $asset->serial_number,
            'asset_condition'    => 'returned',
            'decommission_type'  => 'vendor_return',
            'disposed_by'         => 'IT Team',
            'disposed_at'        => now(),
        ]);

        $this->actingAs($this->itManager)
            ->get(route('assets.index', ['tab' => 'damaged']))
            ->assertOk()
            ->assertSee($asset->asset_tag)
            ->assertSee('Return')
            ->assertSee('Returned');
    }

    /**
     * Switching Not Good -> Returned must RE-ROUTE the existing staging row, not leave
     * it filed as e-waste. The row is created once (firstOrCreate), so the type has to
     * be re-applied on every save.
     */
    public function test_switching_from_not_good_to_returned_reroutes_the_existing_staging_row(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager)->put(route('assets.update', ['asset' => $asset->id]), $this->payload($asset, [
            'asset_condition'     => 'not_good',
            'decommission_reason' => 'Hardware failure',
        ]));

        $this->assertSame('e_waste', DisposedAsset::where('asset_inventory_id', $asset->id)->value('decommission_type'));

        $this->actingAs($this->itManager)->put(route('assets.update', ['asset' => $asset->id]), $this->payload($asset, [
            'asset_condition'     => 'returned',
            'decommission_reason' => 'Actually going back to the vendor',
        ]));

        $rows = DisposedAsset::where('asset_inventory_id', $asset->id)->get();

        $this->assertCount(1, $rows, 'Re-routing must not create a second staging row.');
        $this->assertSame('vendor_return', $rows->first()->decommission_type);
        $this->assertSame('returned', $rows->first()->asset_condition);
    }

    /** Restoring the condition takes the asset back out of the Decommissioning queue. */
    public function test_restoring_a_returned_asset_to_good_clears_the_staging_row(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager)->put(route('assets.update', ['asset' => $asset->id]), $this->payload($asset));
        $this->assertTrue(DisposedAsset::where('asset_inventory_id', $asset->id)->exists());

        $this->actingAs($this->itManager)->put(route('assets.update', ['asset' => $asset->id]), $this->payload($asset, [
            'asset_condition' => 'good',
            'status'          => 'available',
        ]));

        $this->assertFalse(DisposedAsset::where('asset_inventory_id', $asset->id)->exists());
    }

    public function test_an_unknown_condition_is_still_rejected(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->itManager)
            ->put(route('assets.update', ['asset' => $asset->id]), $this->payload($asset, [
                'asset_condition' => 'scrapped',
            ]))
            ->assertSessionHasErrors('asset_condition');
    }
}
