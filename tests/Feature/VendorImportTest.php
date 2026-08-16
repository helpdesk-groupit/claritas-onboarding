<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bulk registration of vendors from a spreadsheet.
 *
 * The thing under test is mostly the REVIEW step, because that is what makes the feature
 * safe: nothing may reach `vendors` before a human has seen what the importer made of the
 * file. The reading itself is pinned in tests/Unit/VendorImportMappingTest.
 */
class VendorImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
    }

    private function manager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function csv(string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('vendors.csv', $contents);
    }

    /** Upload a list and land on its preview, returning the batch token. */
    private function upload(User $user, string $contents): string
    {
        $response = $this->actingAs($user)->post(route('vendors.import.upload'), [
            'import_file' => $this->csv($contents),
        ]);

        $batch = VendorImportBatch::latest('id')->firstOrFail();
        $response->assertRedirect(route('vendors.import.preview', $batch->token));

        return $batch->token;
    }

    private const LIST = "Company Name,SSM No.,Contact Person,Email,H/P,Type of Service\n"
        ."Alpha Sdn Bhd,123456-A,Lim Wei Ming,lim@alpha.com.my,012-345 6789,Rental\n"
        ."Beta Enterprise,654321-B,Siti Aminah,siti@beta.my,03-7788 1234,Repair & Maintenance\n";

    // ── Access ────────────────────────────────────────────────────────────────
    public function test_a_role_that_cannot_manage_vendors_cannot_import(): void
    {
        $intern = User::factory()->create(['role' => 'it_intern']);

        $this->actingAs($intern)->post(route('vendors.import.upload'), [
            'import_file' => $this->csv(self::LIST),
        ])->assertForbidden();

        $this->actingAs($intern)->get(route('vendors.import.template'))->assertForbidden();
    }

    /**
     * The button is a management control, so a read-only viewer must not be offered it.
     * Asserted on the modal's id rather than the words "Import Vendors", which also appear
     * in the modal title and would match a page that renders it for everyone.
     */
    public function test_the_import_button_is_offered_only_to_managers(): void
    {
        $this->actingAs($this->manager())->get(route('vendors.index'))
            ->assertOk()
            ->assertSee('data-bs-target="#vendorImportModal"', false);
    }

    // ── Upload ────────────────────────────────────────────────────────────────
    public function test_uploading_a_list_writes_no_vendors_until_it_is_confirmed(): void
    {
        $this->upload($this->manager(), self::LIST);

        $this->assertSame(0, Vendor::count(), 'the preview step must not create anything');
        $this->assertDatabaseCount('vendor_import_batches', 1);
    }

    public function test_the_preview_shows_what_each_column_was_read_as(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, self::LIST);

        $this->actingAs($user)->get(route('vendors.import.preview', $token))
            ->assertOk()
            ->assertSee('Alpha Sdn Bhd')
            ->assertSee('Beta Enterprise')
            ->assertSee('Matched the column heading')
            // The mapping is presented as editable selects, not as a fait accompli.
            ->assertSee('name="map[0]"', false);
    }

    /**
     * The old binary .xls never reaches the reader — the upload rule turns it away — but the
     * message has to carry the REMEDY either way, because "invalid file type" leaves an
     * operator holding a file Excel produced by default with nothing to do about it.
     */
    public function test_the_legacy_xls_format_is_refused_with_an_instruction(): void
    {
        $this->actingAs($this->manager())
            ->post(route('vendors.import.upload'), [
                'import_file' => UploadedFile::fake()->createWithContent('old.xls', 'not really a workbook'),
            ])
            ->assertSessionHasErrors('import_file');

        $this->assertStringContainsString('re-save', session('errors')->first('import_file'));
        $this->assertStringContainsString('.xlsx', session('errors')->first('import_file'));
        $this->assertDatabaseCount('vendor_import_batches', 0);
    }

    /**
     * A file that passes the extension rule and then turns out to be unreadable must leave
     * NOTHING behind — no batch pointing at a file that cannot be parsed, and no orphan on
     * the private disk that only the nightly sweep would ever notice.
     */
    public function test_a_file_that_cannot_be_parsed_leaves_nothing_behind(): void
    {
        Storage::fake('local');

        $this->actingAs($this->manager())
            ->post(route('vendors.import.upload'), [
                'import_file' => UploadedFile::fake()->createWithContent('vendors.xlsx', 'this is not a zip'),
            ])
            ->assertSessionHas('error')
            // Re-opens the modal it came from, rather than reading as a button that did nothing.
            ->assertSessionHas('import_reopen', true);

        $this->assertDatabaseCount('vendor_import_batches', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    // ── The import itself ─────────────────────────────────────────────────────
    public function test_confirming_creates_the_vendors_and_maps_their_fields(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, self::LIST);

        $this->actingAs($user)->post(route('vendors.import.commit', $token), [
            'rows' => [2, 3],
            'mode' => 'skip',
        ])->assertRedirect(route('vendors.index'));

        $alpha = Vendor::where('name', 'Alpha Sdn Bhd')->first();

        $this->assertNotNull($alpha);
        $this->assertSame('123456-A', $alpha->company_registration_no);
        $this->assertSame('Lim Wei Ming', $alpha->pic_name);
        $this->assertSame('lim@alpha.com.my', $alpha->pic_email);
        $this->assertSame('012-345 6789', $alpha->pic_phone);
        $this->assertSame(['rental'], $alpha->vendor_types);
        $this->assertTrue($alpha->is_active);

        $this->assertSame(['repair'], Vendor::where('name', 'Beta Enterprise')->first()->vendor_types);
    }

    /** An unticked row is a decision, so it must not be imported anyway. */
    public function test_only_the_ticked_rows_are_imported(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, self::LIST);

        $this->actingAs($user)->post(route('vendors.import.commit', $token), ['rows' => [2]]);

        $this->assertSame(1, Vendor::count());
        $this->assertNotNull(Vendor::where('name', 'Alpha Sdn Bhd')->first());
    }

    /**
     * `vendors.name` carries no database unique index — it is unique by validation — so a
     * bulk path that did not check would be the one way to get two rows for one vendor,
     * which is the failure a master exists to prevent. Matched case-insensitively, because
     * "ABC SDN BHD" and "ABC Sdn Bhd" are the same company.
     */
    public function test_a_vendor_already_registered_is_left_alone_by_default(): void
    {
        $user = $this->manager();
        Vendor::create(['name' => 'ALPHA SDN BHD', 'vendor_types' => ['ewaste'], 'pic_name' => 'Existing PIC', 'is_active' => true]);

        $token = $this->upload($user, self::LIST);
        $this->actingAs($user)->post(route('vendors.import.commit', $token), ['rows' => [2, 3], 'mode' => 'skip']);

        $this->assertSame(1, Vendor::whereRaw('LOWER(name) = ?', ['alpha sdn bhd'])->count());
        $this->assertSame('Existing PIC', Vendor::whereRaw('LOWER(name) = ?', ['alpha sdn bhd'])->first()->pic_name);
    }

    /**
     * Update mode ADDS what the sheet knows. It must never blank a field somebody filled in
     * on the vendor's profile just because the spreadsheet has no column for it — an import
     * asserts what the list says, not what it omits.
     */
    public function test_update_mode_fills_blanks_without_wiping_what_is_already_there(): void
    {
        $user = $this->manager();
        $existing = Vendor::create([
            'name' => 'Alpha Sdn Bhd',
            'vendor_types' => ['ewaste', 'repair'],
            'pic_name' => null,
            'notes' => 'Preferred disposal partner',
            'is_active' => true,
        ]);

        $token = $this->upload($user, self::LIST);
        $this->actingAs($user)->post(route('vendors.import.commit', $token), ['rows' => [2], 'mode' => 'update']);

        $existing->refresh();
        $this->assertSame('Lim Wei Ming', $existing->pic_name, 'the blank was filled from the sheet');
        $this->assertSame('Preferred disposal partner', $existing->notes, 'a field the sheet has no column for is untouched');
        $this->assertSame(1, Vendor::count());
    }

    /**
     * The counterpart trap: a sheet WITHOUT a service-type column gives every new vendor
     * "Other" as a default, and writing that back on an update would silently re-tag a
     * carefully categorised vendor.
     */
    public function test_a_sheet_with_no_service_type_column_does_not_retag_an_existing_vendor(): void
    {
        $user = $this->manager();
        $existing = Vendor::create(['name' => 'Alpha Sdn Bhd', 'vendor_types' => ['rental', 'repair'], 'is_active' => true]);

        $token = $this->upload($user, "Company Name,Contact Person\nAlpha Sdn Bhd,Lim Wei Ming\n");
        $this->actingAs($user)->post(route('vendors.import.commit', $token), ['rows' => [2], 'mode' => 'update']);

        $existing->refresh();
        $this->assertSame(['rental', 'repair'], $existing->vendor_types);
        $this->assertSame('Lim Wei Ming', $existing->pic_name);
    }

    /**
     * ColumnMapper::FIELDS is the whitelist, not just the menu: a mapping hand-posted at a
     * field the operator was never shown is DROPPED, and the row still imports on the fields
     * that were. An import is a bulk write nobody reviews cell by cell, so a crafted submit
     * must not be able to reach a column outside that list.
     *
     * `is_primary_ewaste` is the field named here because it used to exist and used to decide
     * where our e-waste disposal was offered. It was retired on 2026-08-15 (the sweep RFQs
     * every e-waste vendor now), and this pins that resurrecting the column would not, on its
     * own, make it reachable from a spreadsheet.
     */
    public function test_a_hand_posted_mapping_to_an_unoffered_field_is_ignored(): void
    {
        $user = $this->manager();

        $token = $this->upload($user, "Company Name,Type of Service,Is Primary Ewaste\nGamma Sdn Bhd,E-waste Disposal,Yes\n");

        $this->actingAs($user)->post(route('vendors.import.commit', $token), [
            'rows' => [2],
            'map' => [0 => 'name', 1 => 'vendor_types', 2 => 'is_primary_ewaste'],
        ]);

        $vendor = Vendor::where('name', 'Gamma Sdn Bhd')->first();
        $this->assertNotNull($vendor, 'the mapped columns still import');
        $this->assertSame(['ewaste'], $vendor->vendor_types);
        $this->assertArrayNotHasKey('is_primary_ewaste', $vendor->getAttributes());
    }

    /** A row the file repeats must not become two vendors. */
    public function test_the_same_name_twice_in_one_file_imports_once(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, "Company Name\nAlpha Sdn Bhd\nAlpha Sdn Bhd\n");

        $this->actingAs($user)->get(route('vendors.import.preview', $token))
            ->assertSee('appears earlier in this file');

        $this->actingAs($user)->post(route('vendors.import.commit', $token), ['rows' => [2, 3]]);

        $this->assertSame(1, Vendor::count());
    }

    /**
     * The confirm step re-reads the stored FILE and takes only the mapping from the browser,
     * so a value that was never in the spreadsheet cannot be imported by posting it.
     */
    public function test_values_posted_with_the_confirmation_are_ignored(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, self::LIST);

        $this->actingAs($user)->post(route('vendors.import.commit', $token), [
            'rows' => [2],
            'attributes' => [['name' => 'Injected Sdn Bhd', 'pic_email' => 'attacker@example.com']],
            'name' => 'Injected Sdn Bhd',
        ]);

        $this->assertNull(Vendor::where('name', 'Injected Sdn Bhd')->first());
        $this->assertNotNull(Vendor::where('name', 'Alpha Sdn Bhd')->first());
    }

    // ── The staging row ───────────────────────────────────────────────────────
    /**
     * The token travels in a URL and is short — a lookup key, never a capability. Somebody
     * else's half-finished import is not theirs to file. Same rule as VendorDocumentScan.
     */
    public function test_another_operators_upload_is_not_reachable(): void
    {
        $token = $this->upload($this->manager(), self::LIST);
        $other = User::factory()->create(['role' => 'finance_manager']);

        $this->actingAs($other)->get(route('vendors.import.preview', $token))->assertNotFound();
        $this->actingAs($other)->post(route('vendors.import.commit', $token), ['rows' => [2]])->assertNotFound();
        $this->assertSame(0, Vendor::count());
    }

    /** A finished import leaves nothing on the private disk — the file has served its purpose. */
    public function test_the_uploaded_file_is_discarded_once_the_import_lands(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, self::LIST);
        $path = VendorImportBatch::where('token', $token)->firstOrFail()->file_path;

        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->actingAs($user)->post(route('vendors.import.commit', $token), ['rows' => [2, 3]]);

        $this->assertDatabaseCount('vendor_import_batches', 0);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_cancelling_discards_the_upload_and_imports_nothing(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, self::LIST);

        $this->actingAs($user)->post(route('vendors.import.discard', $token))
            ->assertRedirect(route('vendors.index'));

        $this->assertDatabaseCount('vendor_import_batches', 0);
        $this->assertSame(0, Vendor::count());
    }

    /**
     * A closed browser tab leaves a spreadsheet full of vendor contact and banking details
     * on the private disk with nothing that will ever read it.
     */
    public function test_an_abandoned_upload_is_swept_with_its_file(): void
    {
        $user = $this->manager();
        $token = $this->upload($user, self::LIST);
        $batch = VendorImportBatch::where('token', $token)->firstOrFail();
        $batch->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('vendors:prune-import-batches')->assertSuccessful();

        $this->assertDatabaseCount('vendor_import_batches', 0);
        $this->assertFalse(Storage::disk('local')->exists($batch->file_path));
    }

    public function test_a_fresh_upload_survives_the_sweep(): void
    {
        $this->upload($this->manager(), self::LIST);

        $this->artisan('vendors:prune-import-batches')->assertSuccessful();

        $this->assertDatabaseCount('vendor_import_batches', 1);
    }

    // ── Template ──────────────────────────────────────────────────────────────
    public function test_the_template_headings_are_ones_the_importer_recognises(): void
    {
        $response = $this->actingAs($this->manager())->get(route('vendors.import.template'));
        $response->assertOk();

        $csv = $response->streamedContent();
        $headers = str_getcsv(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))[0]);

        // A template whose own headings the importer cannot map would be the worst possible
        // first impression, and nothing else would catch it.
        $mapping = \App\Support\VendorImport\ColumnMapper::map($headers);

        foreach ($mapping as $index => $column) {
            $this->assertNotNull($column['field'], 'template heading "'.$headers[$index].'" is not recognised');
            $this->assertSame('header', $column['via'], 'template heading "'.$headers[$index].'" only matched partially');
        }
    }
}
