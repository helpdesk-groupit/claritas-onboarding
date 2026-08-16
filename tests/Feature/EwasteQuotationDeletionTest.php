<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Delete quotation" (added alongside the AI summary/amount-backfill work) —
 * AssetDecommissionQuotation::isDeletable() and AssetDecommissionController::deleteQuotation().
 *
 * An undo for an upload mistake — the wrong file, the wrong vendor picked — before the
 * comparison has ever been submitted for approval. Not a way to withdraw an offer that has
 * already been submitted, recommended or decided on; the re-quote loop (upload a revised
 * offer) is what handles that, and it is deliberately preserved as history.
 */
class EwasteQuotationDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Storage::fake('local');
    }

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function vendor(string $name): Vendor
    {
        return Vendor::create([
            'name' => $name, 'vendor_types' => ['ewaste'],
            'pic_email' => strtolower(str_replace(' ', '', $name)).'@example.com',
            'is_active' => true,
        ]);
    }

    private function cycle(): AssetDecommissionBatch
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q3', 'type' => 'e_waste',
            'company' => 'Claritas Asia Sdn Bhd', 'status' => 'awaiting_quotation',
        ]);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'status' => 'unavailable']);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'ewaste_completeness' => 'complete', 'company' => 'Claritas Asia Sdn Bhd',
            'inspected_at' => now(), 'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        return $batch;
    }

    private function pdf(string $name = 'quote.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            Pdf::loadHTML('<html><body><h1>'.e($name).'</h1></body></html>')->output()
        );
    }

    private function fileQuotation(User $it, AssetDecommissionBatch $batch, Vendor $vendor, float $amount): void
    {
        $this->actingAs($it)->post(route('ewaste.quotation', $batch), [
            'vendor_id' => $vendor->id,
            'quotation_file' => $this->pdf(),
            'quotation_amount' => $amount,
        ])->assertSessionHasNoErrors();
    }

    public function test_it_can_delete_a_quotation_before_the_cycle_is_submitted(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batch, $vendor, 500);

        $quotation = $batch->fresh()->quotationsForComparison()->first();
        $path = $quotation->path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($it)
            ->delete(route('ewaste.quotation.delete', [$batch, $quotation]))
            ->assertRedirect(route('decommission.show', $batch))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('asset_decommission_quotations', ['id' => $quotation->id]);
        Storage::disk('local')->assertMissing($path);

        // No quotations remain at all — the cycle reverts to still gathering offers rather
        // than asserting an offer is on file when none is.
        $this->assertSame('awaiting_quotation', $batch->fresh()->status);
    }

    public function test_deleting_one_of_several_vendors_offers_leaves_the_others_and_the_cycle_status_alone(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $keep = $this->vendor('KeepCo');
        $drop = $this->vendor('DropCo');
        $this->fileQuotation($it, $batch, $keep, 400);
        $this->fileQuotation($it, $batch, $drop, 600);

        $toDelete = $batch->fresh()->quotationsForComparison()->firstWhere('vendor_id', $drop->id);

        $this->actingAs($it)
            ->delete(route('ewaste.quotation.delete', [$batch, $toDelete]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('asset_decommission_quotations', ['id' => $toDelete->id]);
        $remaining = $batch->fresh()->quotationsForComparison();
        $this->assertCount(1, $remaining);
        $this->assertSame($keep->id, $remaining->first()->vendor_id);
        // A quotation still on file — the cycle is still "quotation_uploaded", not reverted.
        $this->assertSame('quotation_uploaded', $batch->fresh()->status);
    }

    public function test_the_filed_contract_copy_is_left_in_place_after_the_quotation_is_deleted(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batch, $vendor, 500);

        $quotation = $batch->fresh()->quotationsForComparison()->first();
        $contract = VendorContract::where('asset_decommission_quotation_id', $quotation->id)->firstOrFail();

        $this->actingAs($it)->delete(route('ewaste.quotation.delete', [$batch, $quotation]))
            ->assertSessionHas('success');

        // The document the vendor actually sent stays on their record — nullOnDelete, never
        // cascade — even though the cycle no longer holds the quotation it was filed from.
        $this->assertDatabaseHas('vendor_contracts', ['id' => $contract->id, 'asset_decommission_quotation_id' => null]);
    }

    public function test_a_quotation_already_submitted_for_approval_cannot_be_deleted(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batch, $vendor, 500);
        $quotation = $batch->fresh()->quotationsForComparison()->first();

        $this->actingAs($it)->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $quotation->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($it)
            ->delete(route('ewaste.quotation.delete', [$batch, $quotation]))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('asset_decommission_quotations', ['id' => $quotation->id]);
    }

    public function test_a_superseded_revision_cannot_be_deleted_even_while_the_cycle_is_still_collecting(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batch, $vendor, 400);
        $first = $batch->fresh()->quotationsForComparison()->first();

        // A second upload from the SAME vendor is a new revision — the first is history.
        $this->fileQuotation($it, $batch, $vendor, 450);

        $this->actingAs($it)
            ->delete(route('ewaste.quotation.delete', [$batch, $first]))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('asset_decommission_quotations', ['id' => $first->id]);
    }

    public function test_finance_may_not_delete_a_quotation(): void
    {
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($this->itManager(), $batch, $vendor, 500);
        $quotation = $batch->fresh()->quotationsForComparison()->first();

        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->delete(route('ewaste.quotation.delete', [$batch, $quotation]))
            ->assertForbidden();

        $this->assertDatabaseHas('asset_decommission_quotations', ['id' => $quotation->id]);
    }

    public function test_a_quotation_cannot_be_deleted_through_another_cycles_route(): void
    {
        $it = $this->itManager();
        $batchA = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batchA, $vendor, 500);
        $quotation = $batchA->fresh()->quotationsForComparison()->first();

        $batchB = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q4', 'type' => 'e_waste',
            'company' => 'Claritas Asia Sdn Bhd', 'status' => 'awaiting_quotation',
        ]);

        $this->actingAs($it)
            ->delete(route('ewaste.quotation.delete', [$batchB, $quotation]))
            ->assertNotFound();

        $this->assertDatabaseHas('asset_decommission_quotations', ['id' => $quotation->id]);
    }

    public function test_the_delete_control_is_only_offered_while_the_cycle_is_collecting(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batch, $vendor, 500);

        $this->actingAs($it)->get(route('decommission.show', $batch))
            ->assertOk()
            ->assertSee('Delete this quotation');

        $quotation = $batch->fresh()->quotationsForComparison()->first();
        $this->actingAs($it)->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $quotation->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($it)->get(route('decommission.show', $batch))
            ->assertOk()
            ->assertDontSee('Delete this quotation');
    }
}
