<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetInventory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Models\VendorDocumentScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Matching a Vendor Management contract to the rental assets it covers, WITHOUT a
 * `contract_id` on the asset and without asking anyone to link them by hand.
 *
 * A rental asset already carries its own uploaded copy of the contract in
 * `rental_contract_documents`; a Vendor Management contract carries its own copy in
 * `file_path`. When the two are the SAME file, their SHA-256 hashes are identical — a
 * certain answer, not a guess. See VendorContract::matchedAssets().
 */
class ContractAssetHashMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        // Behave as with no Anthropic key: documents are stored, nothing is read. Hash
        // matching touches none of this — it is the point of the feature.
        config()->set('vendors.ai.enabled', false);
    }

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function vendor(array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => 'Acme Rentals Sdn Bhd '.fake()->unique()->numberBetween(1, 9999),
            'vendor_types' => ['rental'],
            'is_active' => true,
        ], $attrs));
    }

    private function asset(array $attrs = []): AssetInventory
    {
        return AssetInventory::create(array_merge([
            'asset_tag' => 'AST-'.fake()->unique()->numberBetween(1000, 9999),
            'asset_category' => 'IT Equipment',
            'asset_type' => 'Laptop',
            'brand' => 'Dell',
            'model' => 'Latitude 5450',
            'serial_number' => strtoupper(fake()->unique()->bothify('SN####??')),
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
        ], $attrs));
    }

    /**
     * A document uploaded and "read" (no real AI call — vendors.ai.enabled is off) but not
     * yet filed, staged exactly the way the Add-Contract modal leaves it — same shape as
     * VendorMasterTest's helper of the same purpose.
     */
    private function stagedScan(Vendor $vendor, User $actor, string $content, array $fields = []): VendorDocumentScan
    {
        $path = VendorDocumentScan::directoryFor(VendorDocumentScan::KIND_CONTRACT, $vendor->id).'/'.fake()->uuid().'.pdf';
        Storage::disk('local')->put($path, $content);

        return VendorDocumentScan::create([
            'vendor_id' => $vendor->id,
            'user_id' => $actor->id,
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'kind' => VendorDocumentScan::KIND_CONTRACT,
            'file_path' => $path,
            'original_filename' => 'contract.pdf',
            'status' => 'ok',
            'summary' => 'A summary read from the uploaded document.',
            'fields' => $fields,
        ]);
    }

    // ── VendorContract::hashStoredFile() ────────────────────────────────────────

    public function test_hash_stored_file_returns_null_for_a_blank_or_missing_path(): void
    {
        Storage::fake('local');

        $this->assertNull(VendorContract::hashStoredFile(null));
        $this->assertNull(VendorContract::hashStoredFile(''));
        $this->assertNull(VendorContract::hashStoredFile('vendor_contracts/1/does-not-exist.pdf'));
    }

    public function test_hash_stored_file_returns_the_sha256_of_the_actual_bytes(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('vendor_contracts/1/sample.pdf', 'CONTRACT BYTES');

        $this->assertSame(
            hash('sha256', 'CONTRACT BYTES'),
            VendorContract::hashStoredFile('vendor_contracts/1/sample.pdf')
        );
    }

    // ── VendorContract::matchedAssets() ─────────────────────────────────────────

    public function test_matched_assets_finds_a_rental_asset_with_the_identical_document(): void
    {
        $vendor = $this->vendor();
        $hash = hash('sha256', 'SAME CONTRACT BYTES');

        $contract = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Rental Agreement', 'status' => 'active',
            'file_path' => 'vendor_contracts/x/contract.pdf', 'file_hash' => $hash,
        ]);

        $matching = $this->asset([
            'vendor_id' => $vendor->id,
            'rental_contract_documents' => ['rental_contracts/matching.pdf'],
            'rental_contract_document_hashes' => ['rental_contracts/matching.pdf' => $hash],
        ]);
        $other = $this->asset([
            'vendor_id' => $vendor->id,
            'rental_contract_documents' => ['rental_contracts/other.pdf'],
            'rental_contract_document_hashes' => ['rental_contracts/other.pdf' => hash('sha256', 'DIFFERENT BYTES')],
        ]);

        $matched = $contract->matchedAssets(collect([$matching, $other]));

        $this->assertCount(1, $matched);
        $this->assertSame($matching->id, $matched->first()->id);
    }

    /**
     * A contract with several matching assets — the common case (5 laptops, one agreement)
     * — must return every one of them, not just the first.
     */
    public function test_matched_assets_finds_every_asset_sharing_the_document(): void
    {
        $vendor = $this->vendor();
        $hash = hash('sha256', 'FLEET AGREEMENT BYTES');

        $contract = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Fleet Agreement', 'status' => 'active', 'file_hash' => $hash,
        ]);

        $assets = collect(range(1, 5))->map(fn ($n) => $this->asset([
            'vendor_id' => $vendor->id,
            'rental_contract_documents' => ["rental_contracts/unit-{$n}.pdf"],
            'rental_contract_document_hashes' => ["rental_contracts/unit-{$n}.pdf" => $hash],
        ]));

        $this->assertCount(5, $contract->matchedAssets($assets));
    }

    public function test_matched_assets_ignores_a_purchased_asset_even_with_the_same_hash(): void
    {
        $vendor = $this->vendor();
        $hash = hash('sha256', 'SAME CONTRACT BYTES');

        $contract = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Rental Agreement', 'status' => 'active', 'file_hash' => $hash,
        ]);

        $purchased = $this->asset([
            'vendor_id' => $vendor->id,
            'ownership_type' => 'company',
            'rental_contract_documents' => ['rental_contracts/x.pdf'],
            'rental_contract_document_hashes' => ['rental_contracts/x.pdf' => $hash],
        ]);

        $this->assertCount(0, $contract->matchedAssets(collect([$purchased])));
    }

    public function test_matched_assets_is_empty_without_a_file_hash_on_the_contract(): void
    {
        $vendor = $this->vendor();
        $contract = VendorContract::create(['vendor_id' => $vendor->id, 'title' => 'No document', 'status' => 'active']);

        $asset = $this->asset([
            'vendor_id' => $vendor->id,
            'rental_contract_documents' => ['rental_contracts/x.pdf'],
            'rental_contract_document_hashes' => ['rental_contracts/x.pdf' => hash('sha256', 'ANYTHING')],
        ]);

        $this->assertCount(0, $contract->matchedAssets(collect([$asset])));
    }

    /**
     * The exact scenario the feature was asked for: two contracts from the same vendor
     * covering different batches must not bleed into each other just because they share a
     * vendor.
     */
    public function test_two_contracts_from_the_same_vendor_do_not_cross_match(): void
    {
        $vendor = $this->vendor();
        $hashA = hash('sha256', 'CONTRACT A');
        $hashB = hash('sha256', 'CONTRACT B');

        $contractA = VendorContract::create(['vendor_id' => $vendor->id, 'title' => 'A', 'status' => 'active', 'file_hash' => $hashA]);
        $contractB = VendorContract::create(['vendor_id' => $vendor->id, 'title' => 'B', 'status' => 'active', 'file_hash' => $hashB]);

        $assetA = $this->asset([
            'vendor_id' => $vendor->id,
            'rental_contract_documents' => ['rental_contracts/a.pdf'],
            'rental_contract_document_hashes' => ['rental_contracts/a.pdf' => $hashA],
        ]);

        $this->assertCount(0, $contractB->matchedAssets(collect([$assetA])));
        $this->assertCount(1, $contractA->matchedAssets(collect([$assetA])));
    }

    // ── Computing the hash on write ──────────────────────────────────────────────

    public function test_filing_a_contract_computes_its_file_hash(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();
        $scan = $this->stagedScan($vendor, $actor, 'THE ACTUAL CONTRACT BYTES', ['title' => 'Laptop Rental Agreement']);

        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), [
            'scan_token' => $scan->token,
        ])->assertSessionHasNoErrors();

        $contract = VendorContract::first();
        $this->assertNotNull($contract->file_hash);
        $this->assertSame(hash('sha256', 'THE ACTUAL CONTRACT BYTES'), $contract->file_hash);
    }

    public function test_replacing_a_contracts_document_recomputes_its_hash(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $firstScan = $this->stagedScan($vendor, $actor, 'ORIGINAL BYTES', ['title' => 'Original']);
        $this->actingAs($actor)->post(route('vendors.contracts.store', $vendor), ['scan_token' => $firstScan->token])
            ->assertSessionHasNoErrors();
        $contract = VendorContract::first();
        $originalHash = $contract->file_hash;

        $secondScan = $this->stagedScan($vendor, $actor, 'REPLACED BYTES', ['title' => 'Replacement']);
        $this->actingAs($actor)->put(route('vendors.contracts.update', [$vendor, $contract]), [
            'scan_token' => $secondScan->token,
            'ai_summary' => 'Replacement summary',
        ])->assertSessionHasNoErrors();

        $contract->refresh();
        $this->assertNotSame($originalHash, $contract->file_hash);
        $this->assertSame(hash('sha256', 'REPLACED BYTES'), $contract->file_hash);
    }

    public function test_uploading_a_rental_contract_document_on_an_asset_computes_its_hash(): void
    {
        Storage::fake('local');
        $asset = $this->asset();
        $file = UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4\nASSET CONTRACT BYTES");

        $this->actingAs($this->itManager())->put(route('assets.update', $asset), [
            'asset_tag' => $asset->asset_tag,
            'asset_category' => $asset->asset_category,
            'asset_type' => $asset->asset_type,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
            'rental_contract_documents' => [$file],
        ])->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertCount(1, $asset->rental_contract_documents);
        $path = $asset->rental_contract_documents[0];
        $this->assertArrayHasKey($path, $asset->rental_contract_document_hashes);
        $this->assertSame(
            hash_file('sha256', Storage::disk('local')->path($path)),
            $asset->rental_contract_document_hashes[$path]
        );
    }

    /**
     * Hashes are keyed BY PATH, not aligned by array index — dropping one file must never
     * desynchronise which hash belongs to whichever of the others remains.
     */
    public function test_removing_a_kept_file_drops_its_own_hash_entry_on_update(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('rental_contracts/keep.pdf', 'KEEP ME');
        Storage::disk('local')->put('rental_contracts/drop.pdf', 'DROP ME');

        $asset = $this->asset([
            'rental_contract_documents' => ['rental_contracts/keep.pdf', 'rental_contracts/drop.pdf'],
            'rental_contract_document_hashes' => [
                'rental_contracts/keep.pdf' => hash('sha256', 'KEEP ME'),
                'rental_contracts/drop.pdf' => hash('sha256', 'DROP ME'),
            ],
        ]);

        $this->actingAs($this->itManager())->put(route('assets.update', $asset), [
            'asset_tag' => $asset->asset_tag,
            'asset_category' => $asset->asset_category,
            'asset_type' => $asset->asset_type,
            'brand' => $asset->brand,
            'model' => $asset->model,
            'serial_number' => $asset->serial_number,
            'status' => 'available',
            'asset_condition' => 'good',
            'ownership_type' => 'rental',
            'contract_keep_submitted' => '1',
            'contract_keep_paths' => ['rental_contracts/keep.pdf'],
        ])->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertSame(['rental_contracts/keep.pdf'], $asset->rental_contract_documents);
        $this->assertSame(['rental_contracts/keep.pdf' => hash('sha256', 'KEEP ME')], $asset->rental_contract_document_hashes);
    }

    // ── The Contracts tab ──────────────────────────────────────────────────────

    public function test_the_contracts_tab_lists_the_matching_asset(): void
    {
        $vendor = $this->vendor();
        $hash = hash('sha256', 'SHARED CONTRACT BYTES');

        VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Computer Supply Agreement', 'status' => 'active',
            'file_path' => 'vendor_contracts/x/contract.pdf', 'file_hash' => $hash,
        ]);

        $this->asset([
            'vendor_id' => $vendor->id,
            'asset_tag' => 'LNK-0001',
            'rental_contract_documents' => ['rental_contracts/match.pdf'],
            'rental_contract_document_hashes' => ['rental_contracts/match.pdf' => $hash],
        ]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('1 linked asset', $html);
        $this->assertStringContainsString('LNK-0001', $html);
    }

    public function test_the_contracts_tab_names_an_unmatched_contract_as_unmatched(): void
    {
        $vendor = $this->vendor();
        VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Computer Supply Agreement', 'status' => 'active',
            'file_path' => 'vendor_contracts/x/contract.pdf', 'file_hash' => hash('sha256', 'NOBODY MATCHES ME'),
        ]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No asset on the Assets tab shares this document yet', $html);
    }

    // ── Backfill ──────────────────────────────────────────────────────────────

    public function test_the_backfill_command_hashes_existing_documents_and_enables_matching(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();

        Storage::disk('local')->put('vendor_contracts/x/contract.pdf', 'LEGACY SHARED BYTES');
        $contract = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Legacy Agreement', 'status' => 'active',
            'file_path' => 'vendor_contracts/x/contract.pdf',
        ]);
        $this->assertNull($contract->file_hash);

        Storage::disk('local')->put('rental_contracts/legacy.pdf', 'LEGACY SHARED BYTES');
        $asset = $this->asset([
            'vendor_id' => $vendor->id,
            'rental_contract_documents' => ['rental_contracts/legacy.pdf'],
        ]);
        $this->assertNull($asset->rental_contract_document_hashes);

        Artisan::call('vendors:hash-contract-documents');

        $contract->refresh();
        $asset->refresh();
        $this->assertSame(hash('sha256', 'LEGACY SHARED BYTES'), $contract->file_hash);
        $this->assertSame(
            hash('sha256', 'LEGACY SHARED BYTES'),
            $asset->rental_contract_document_hashes['rental_contracts/legacy.pdf']
        );
        $this->assertCount(1, $contract->matchedAssets(collect([$asset])));
    }

    public function test_the_backfill_command_dry_run_writes_nothing(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        Storage::disk('local')->put('vendor_contracts/x/contract.pdf', 'BYTES');
        $contract = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Legacy', 'status' => 'active',
            'file_path' => 'vendor_contracts/x/contract.pdf',
        ]);

        Artisan::call('vendors:hash-contract-documents', ['--dry-run' => true]);

        $this->assertNull($contract->fresh()->file_hash);
    }

    public function test_the_backfill_command_skips_a_document_missing_from_disk_without_failing(): void
    {
        Storage::fake('local');
        $vendor = $this->vendor();
        $contract = VendorContract::create([
            'vendor_id' => $vendor->id, 'title' => 'Missing file', 'status' => 'active',
            'file_path' => 'vendor_contracts/x/gone.pdf',
        ]);

        $exitCode = Artisan::call('vendors:hash-contract-documents');

        $this->assertSame(0, $exitCode);
        $this->assertNull($contract->fresh()->file_hash);
    }
}
