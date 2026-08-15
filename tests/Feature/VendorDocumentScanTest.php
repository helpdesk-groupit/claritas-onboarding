<?php

namespace Tests\Feature;

use App\Console\Commands\PruneVendorDocumentScans;
use App\Http\Middleware\EnforceTwoFactor;
use App\Models\ClaudeApiUsageLog;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorContract;
use App\Models\VendorDocumentScan;
use App\Services\VendorDocumentInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Scan-before-save: the document is uploaded and READ before the record exists, so the
 * operator corrects the summary rather than meeting it afterwards on the row.
 *
 * The through-line: an upload must never be lost and must never be filed as something
 * nobody looked at. Between those two, everything here is a test about what happens when
 * the reading does not go to plan — because the reading runs inside a web request, and on
 * live that request can be cut off while PHP carries on.
 */
class VendorDocumentScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Storage::fake('local');

        config()->set('vendors.ai.enabled', true);
        config()->set('claims.ocr.enabled', true);
        config()->set('claims.ocr.provider', 'anthropic');
        config()->set('claims.ocr.api_key', 'test-key');
        config()->set('claims.ocr.model', 'claude-haiku-4-5');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function itManager(): User
    {
        return User::factory()->create(['role' => 'it_manager']);
    }

    private function vendor(array $attrs = []): Vendor
    {
        return Vendor::create(array_merge([
            'name' => 'Acme Rentals Sdn Bhd',
            'vendor_types' => ['rental'],
            'is_active' => true,
        ], $attrs));
    }

    /**
     * A real PDF: `valid_file_content` checks magic bytes against the extension, so a
     * zero-filled fake is rejected before the controller runs.
     */
    private function pdf(string $name = 'contract.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>SAMPLE VENDOR DOCUMENT</h1>')->output()
        );
    }

    /**
     * The two Anthropic calls a reading makes, in order: the vision pass that transcribes
     * and summarises, then the text pass that reads the parties and the record fields off
     * that transcript.
     */
    private function fakeReading(array $summaryPayload, array $detailPayload): void
    {
        $reply = fn (array $payload) => [
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 300],
        ];

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($reply($summaryPayload))
            ->push($reply($detailPayload)),
        ]);
    }

    // ── The happy path ────────────────────────────────────────────────────────

    public function test_a_scan_reads_the_document_and_hands_back_a_summary_to_correct(): void
    {
        $vendor = $this->vendor();

        $this->fakeReading(
            ['summary' => 'A 24-month laptop rental.', 'key_points' => ['RM493 per month'], 'text' => 'FULL TEXT'],
            [
                'companies_involved' => ['Acme Rentals Sdn Bhd', 'Claritas Sdn Bhd'],
                'title' => 'Laptop Rental Agreement 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'contract_value' => 5916,
                'currency' => 'MYR',
                'billing_cycle' => 'monthly',
            ]
        );

        $response = $this->actingAs($this->itManager())
            ->post(route('vendors.documents.scan', $vendor), [
                'kind' => 'contract',
                'token' => 'tok-'.str_repeat('a', 12),
                'document' => $this->pdf(),
            ])
            ->assertOk();

        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('settled', true);
        $response->assertJsonPath('summary', 'A 24-month laptop rental.');
        $response->assertJsonPath('companies', ['Acme Rentals Sdn Bhd', 'Claritas Sdn Bhd']);
        $response->assertJsonPath('fields.title', 'Laptop Rental Agreement 2026');

        // The transcription is NOT shipped to the browser: it is not shown or edited in the
        // modal, and it would be the largest thing on the page.
        $response->assertJsonMissingPath('text');

        // Nothing is filed until the operator saves.
        $this->assertSame(0, VendorContract::count());
        $scan = VendorDocumentScan::first();
        $this->assertSame('FULL TEXT', $scan->text);
        Storage::disk('local')->assertExists($scan->file_path);
    }

    /**
     * The reading is two calls on purpose, and this pins WHY: the transcription can run
     * past max_tokens and come back as unparseable JSON. If the record fields rode in that
     * same reply, a long contract would lose its dates as collateral damage. Read from the
     * transcript instead, they survive a truncation — and the truncation is still reported.
     */
    public function test_a_truncated_transcript_still_yields_the_fields_read_from_it(): void
    {
        $vendor = $this->vendor();

        Http::fake(['api.anthropic.com/*' => Http::sequence()
            // Cut off at max_tokens: valid JSON here, but the status must say partial.
            ->push([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'The first pages only.', 'key_points' => [], 'text' => 'PAGE ONE',
                ])]],
                'stop_reason' => 'max_tokens',
                'usage' => ['input_tokens' => 9000, 'output_tokens' => 8000],
            ])
            ->push([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'companies_involved' => ['Acme Rentals Sdn Bhd'],
                    'doc_type' => 'invoice',
                    'doc_number' => 'INV-77',
                    'total' => 1080,
                ])]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 400, 'output_tokens' => 90],
            ]),
        ]);

        $this->actingAs($this->itManager())
            ->post(route('vendors.documents.scan', $vendor), [
                'kind' => 'billing',
                'token' => 'tok-'.str_repeat('b', 12),
                'document' => $this->pdf('invoice.pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('fields.doc_number', 'INV-77')
            ->assertJsonPath('fields.total', 1080);
    }

    /**
     * The reading FAILS OPEN. A refused provider leaves the file stored and the record
     * fileable, with the reason stated — an unreadable document must never be an unfileable
     * one, because the file itself is the thing being kept.
     */
    public function test_a_refused_provider_still_leaves_a_saveable_upload(): void
    {
        $vendor = $this->vendor();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->actingAs($this->itManager())
            ->post(route('vendors.documents.scan', $vendor), [
                'kind' => 'contract',
                'token' => 'tok-'.str_repeat('c', 12),
                'document' => $this->pdf(),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('settled', true)
            ->assertJsonPath('summary', '');

        $scan = VendorDocumentScan::first();
        $this->assertNotNull($scan);
        Storage::disk('local')->assertExists($scan->file_path);
    }

    // ── Losing the response ───────────────────────────────────────────────────

    /**
     * THE reason the token exists. The read runs inside the upload request, so a long PDF
     * can outlive the edge timeout on live: the browser gets a network error while PHP
     * finishes and writes the row. Polling by token is how that work is collected instead
     * of being paid for a second time and thrown away.
     */
    public function test_a_read_whose_response_was_lost_can_be_collected_by_token(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $this->fakeReading(
            ['summary' => 'Read successfully.', 'key_points' => [], 'text' => 'TEXT'],
            ['companies_involved' => ['Acme Rentals Sdn Bhd']]
        );

        $token = 'tok-'.str_repeat('d', 12);

        $this->actingAs($actor)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract',
            'token' => $token,
            'document' => $this->pdf(),
        ])->assertOk();

        // As if the upload response never arrived: the modal asks what became of it.
        $this->actingAs($actor)
            ->getJson(route('vendors.documents.scan-status', [$vendor, 'token' => $token]))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('settled', true)
            ->assertJsonPath('summary', 'Read successfully.');
    }

    public function test_polling_an_unknown_token_says_the_upload_is_gone(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())
            ->getJson(route('vendors.documents.scan-status', [$vendor, 'token' => 'tok-'.str_repeat('z', 12)]))
            ->assertNotFound();
    }

    /**
     * A token is a lookup key, not a capability: it is short, it travels in a URL, and the
     * file behind it is a commercial document somebody else uploaded.
     */
    public function test_one_operators_upload_is_not_reachable_by_another(): void
    {
        $vendor = $this->vendor();
        $mine = $this->itManager();
        $theirs = $this->itManager();

        $this->fakeReading(['summary' => 'Theirs.', 'key_points' => [], 'text' => 'T'], []);

        $token = 'tok-'.str_repeat('e', 12);

        $this->actingAs($theirs)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract',
            'token' => $token,
            'document' => $this->pdf(),
        ])->assertOk();

        $this->actingAs($mine)
            ->getJson(route('vendors.documents.scan-status', [$vendor, 'token' => $token]))
            ->assertNotFound();
    }

    /** The vendor comes from the URL, so a token must not reach across vendors either. */
    public function test_a_token_cannot_be_read_through_another_vendors_route(): void
    {
        $vendor = $this->vendor();
        $other = $this->vendor(['name' => 'Other Vendor Sdn Bhd']);
        $actor = $this->itManager();

        $this->fakeReading(['summary' => 'Ours.', 'key_points' => [], 'text' => 'T'], []);

        $token = 'tok-'.str_repeat('f', 12);

        $this->actingAs($actor)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract',
            'token' => $token,
            'document' => $this->pdf(),
        ])->assertOk();

        $this->actingAs($actor)
            ->getJson(route('vendors.documents.scan-status', [$other, 'token' => $token]))
            ->assertNotFound();
    }

    // ── Changing your mind ────────────────────────────────────────────────────

    /**
     * Re-picking a file in the same modal reuses the token. The previous attempt has to go
     * WITH its file — otherwise every changed mind leaks an upload onto the private disk.
     */
    public function test_rescanning_under_the_same_token_discards_the_previous_upload(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();
        $token = 'tok-'.str_repeat('g', 12);

        // ONE fake for both readings, four replies deep. Http::fake() MERGES stubs rather
        // than replacing them, so calling it a second time leaves the first (by then
        // exhausted) sequence matching first.
        $reply = fn (array $payload) => [
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ];
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($reply(['summary' => 'First.', 'key_points' => [], 'text' => 'ONE']))
            ->push($reply(['companies_involved' => []]))
            ->push($reply(['summary' => 'Second.', 'key_points' => [], 'text' => 'TWO']))
            ->push($reply(['companies_involved' => []])),
        ]);

        $this->actingAs($actor)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract', 'token' => $token, 'document' => $this->pdf('first.pdf'),
        ])->assertOk();

        $first = VendorDocumentScan::first();
        $firstPath = $first->file_path;

        $this->actingAs($actor)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract', 'token' => $token, 'document' => $this->pdf('second.pdf'),
        ])->assertOk()->assertJsonPath('summary', 'Second.');

        $this->assertSame(1, VendorDocumentScan::count());
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists(VendorDocumentScan::first()->file_path);
    }

    /**
     * An abandoned scan is a file on the private disk nothing points at. The sweep deletes
     * the file with the row, which is only safe because saving deletes the row and KEEPS
     * the file — so a surviving row always means an unclaimed upload.
     */
    public function test_the_sweep_discards_an_abandoned_upload_and_its_file(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $this->fakeReading(['summary' => 'Abandoned.', 'key_points' => [], 'text' => 'T'], []);

        $this->actingAs($actor)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract', 'token' => 'tok-'.str_repeat('h', 12), 'document' => $this->pdf(),
        ])->assertOk();

        $scan = VendorDocumentScan::first();
        $path = $scan->file_path;

        // Still inside the retention window: a dry run must touch nothing.
        $scan->forceFill(['created_at' => now()->subHours(48)])->save();

        $this->artisan(PruneVendorDocumentScans::class, ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, VendorDocumentScan::count());
        Storage::disk('local')->assertExists($path);

        $this->artisan(PruneVendorDocumentScans::class)->assertSuccessful();
        $this->assertSame(0, VendorDocumentScan::count());
        Storage::disk('local')->assertMissing($path);
    }

    /** A scan still inside the window is somebody's open modal. It must survive the sweep. */
    public function test_the_sweep_leaves_a_recent_upload_alone(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $this->fakeReading(['summary' => 'Recent.', 'key_points' => [], 'text' => 'T'], []);

        $this->actingAs($actor)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract', 'token' => 'tok-'.str_repeat('i', 12), 'document' => $this->pdf(),
        ])->assertOk();

        $this->artisan(PruneVendorDocumentScans::class)->assertSuccessful();
        $this->assertSame(1, VendorDocumentScan::count());
    }

    // ── Access ────────────────────────────────────────────────────────────────

    public function test_a_role_outside_vendor_management_cannot_scan(): void
    {
        $vendor = $this->vendor();
        $outsider = User::factory()->create(['role' => 'it_intern']);

        $this->actingAs($outsider)
            ->post(route('vendors.documents.scan', $vendor), [
                'kind' => 'contract',
                'token' => 'tok-'.str_repeat('j', 12),
                'document' => $this->pdf(),
            ])
            ->assertForbidden();

        $this->assertSame(0, VendorDocumentScan::count());
    }

    // ── What the reading is allowed to write ──────────────────────────────────

    /**
     * The extraction is model output about to be written to typed columns, so it is clamped
     * rather than trusted. A hallucinated enum would bounce a legitimate upload on
     * validation; an impossible date would drive an "Expired" badge on a live contract.
     */
    public function test_nonsense_from_the_model_is_dropped_rather_than_stored(): void
    {
        $fields = VendorDocumentInsightService::extractDetails('TRANSCRIPT', 'contract', null);
        $this->assertSame([], $fields['fields'], 'guard: with no fake in place nothing should come back');

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'companies_involved' => ['Acme Rentals Sdn Bhd', 'acme rentals sdn bhd', '  ', 42],
                'contract_type' => 'a-type-that-does-not-exist',
                'billing_cycle' => 'fortnightly',
                'start_date' => '2026-13-45',
                'end_date' => '2026-01-01',
                'contract_value' => -500,
                'notice_period_days' => 99999,
                'currency' => 'Malaysian Ringgit',
            ])]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ])]);

        $result = VendorDocumentInsightService::extractDetails('TRANSCRIPT', 'contract', null);

        // The same party twice in different case is one party.
        $this->assertSame(['Acme Rentals Sdn Bhd'], $result['companies']);

        foreach (['contract_type', 'billing_cycle', 'start_date', 'contract_value', 'notice_period_days', 'currency'] as $key) {
            $this->assertArrayNotHasKey($key, $result['fields'], "{$key} should have been rejected");
        }

        // The end date survives on its own — there is no start date left to contradict it.
        $this->assertSame('2026-01-01', $result['fields']['end_date']);
    }

    /**
     * An end date before the start date is a misread, and it would make the derived Status
     * badge — the column the listing is read from — nonsense. Both go, so the operator
     * types the dates they can see rather than correcting an impossible term.
     */
    public function test_a_term_that_ends_before_it_starts_is_dropped_entirely(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'companies_involved' => [],
                'start_date' => '2026-12-31',
                'end_date' => '2026-01-01',
            ])]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
        ])]);

        $result = VendorDocumentInsightService::extractDetails('TRANSCRIPT', 'contract', null);

        $this->assertArrayNotHasKey('start_date', $result['fields']);
        $this->assertArrayNotHasKey('end_date', $result['fields']);
    }

    /** Both halves of a reading are billed, and both to Vendor Management. */
    public function test_the_detail_pass_is_billed_separately_from_the_summary(): void
    {
        $vendor = $this->vendor();

        $this->fakeReading(
            ['summary' => 'Billed.', 'key_points' => [], 'text' => 'TEXT'],
            ['companies_involved' => []]
        );

        $this->actingAs($this->itManager())->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'contract', 'token' => 'tok-'.str_repeat('k', 12), 'document' => $this->pdf(),
        ])->assertOk();

        $features = ClaudeApiUsageLog::pluck('feature')->all();
        $this->assertContains('vendor_document_summary', $features);
        $this->assertContains('vendor_document_fields', $features);

        foreach ($features as $feature) {
            $this->assertSame('Vendor Management', ClaudeApiUsageLog::MODULES[$feature] ?? null);
        }
    }

    // ── End to end ────────────────────────────────────────────────────────────

    /**
     * The whole point of the change, in one test: an operator uploads an invoice, is shown
     * what it says, corrects the summary, and saves — without typing a number, a date or a
     * figure, all of which are stored anyway because the SST flag, the Overdue badge and
     * the asset link are computed from them.
     */
    public function test_an_invoice_goes_from_upload_to_filed_row_without_typing_a_figure(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();
        $token = 'tok-'.str_repeat('m', 12);

        $this->fakeReading(
            ['summary' => 'Monthly rental for 11 laptops.', 'key_points' => ['Due 30 days'], 'text' => 'FULL TEXT'],
            [
                'companies_involved' => ['Acme Rentals Sdn Bhd', 'Enlinea Sdn Bhd'],
                'doc_type' => 'invoice',
                'doc_number' => 'IV-25268',
                'doc_date' => '2026-07-01',
                'due_date' => '2026-07-31',
                'subtotal' => 5423.00,
                'sst_amount' => 0,
                'total' => 5423.00,
                'currency' => 'MYR',
                'description' => 'Laptop rental, July 2026',
            ]
        );

        $this->actingAs($actor)->post(route('vendors.documents.scan', $vendor), [
            'kind' => 'billing', 'token' => $token, 'document' => $this->pdf('invoice.pdf'),
        ])->assertOk();

        $this->actingAs($actor)->post(route('vendors.billing.store', $vendor), [
            'scan_token' => $token,
            'ai_summary' => 'Monthly rental for 11 laptops. Checked against the contract rate.',
            'companies_involved' => 'Acme Rentals Sdn Bhd, Enlinea Sdn Bhd',
        ])->assertSessionHasNoErrors();

        $doc = VendorBillingDocument::first();
        $this->assertSame('invoice', $doc->doc_type);
        $this->assertSame('IV-25268', $doc->doc_number);
        $this->assertSame('2026-07-31', $doc->due_date->toDateString());
        $this->assertSame('5423.00', (string) $doc->total);
        $this->assertSame(['Acme Rentals Sdn Bhd', 'Enlinea Sdn Bhd'], $doc->companies_involved);
        $this->assertTrue($doc->summaryIsEdited());
        $this->assertSame('FULL TEXT', $doc->ai_text);
        $this->assertSame(0, VendorDocumentScan::count());

        // And the listing shows what the operator saved, in the columns they asked for.
        $html = $this->actingAs($actor)
            ->get(route('vendors.show', [$vendor, 'tab' => 'billing']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Companies Involved', $html);
        $this->assertStringContainsString('Enlinea Sdn Bhd', $html);
        $this->assertStringContainsString('Checked against the contract rate.', $html);
    }
}
