<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Jobs\SummariseVendorDocument;
use App\Models\ClaudeApiUsageLog;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBillingDocument;
use App\Models\VendorChatMessage;
use App\Models\VendorContract;
use App\Services\VendorDocumentInsightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The AI reading of a vendor document — the summary on its row, and the assistant that
 * answers from the transcription.
 *
 * The through-line of nearly every test here: an answer is only worth what went into it,
 * so the feature must never let "we never read this document" pass for "we read it and it
 * did not say that".
 */
class VendorDocumentInsightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Storage::fake('local');

        // A live Anthropic key, so the gates under test are the feature's own and not
        // "there is no provider configured".
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

    private function pdf(string $name = 'contract.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>SAMPLE VENDOR DOCUMENT</h1>')->output()
        );
    }

    /** A contract with a real stored file, as the summariser expects to find it. */
    private function storedContract(Vendor $vendor, array $attrs = []): VendorContract
    {
        $path = $this->pdf()->store('vendor_contracts/'.$vendor->id, 'local');

        return VendorContract::create(array_merge([
            'vendor_id' => $vendor->id,
            'title' => 'Equipment Rental Agreement',
            'contract_reference' => 'RC-2026-01',
            'status' => 'active',
            'file_path' => $path,
            'original_filename' => 'contract.pdf',
        ], $attrs));
    }

    /** A contract that has been READ, so the assistant can genuinely be asked about it. */
    private function readContract(Vendor $vendor, array $attrs = []): VendorContract
    {
        return $this->storedContract($vendor, array_merge([
            'ai_status' => 'ok',
            'ai_summary' => 'Five laptops rented at RM 4,500 per month.',
            'ai_text' => 'Clause 1. The term is 24 months.',
            'ai_at' => now(),
        ], $attrs));
    }

    /** Its billing twin, so a scope test has two documents of different kinds to separate. */
    private function readInvoice(Vendor $vendor, array $attrs = []): VendorBillingDocument
    {
        return VendorBillingDocument::create(array_merge([
            'vendor_id' => $vendor->id,
            'doc_type' => 'invoice',
            'doc_number' => 'INV-8821',
            'status' => 'received',
            'file_path' => $this->pdf('invoice.pdf')->store('vendor_billing/'.$vendor->id, 'local'),
            'original_filename' => 'invoice.pdf',
            'ai_status' => 'ok',
            'ai_summary' => 'Two months of laptop rental.',
            'ai_text' => 'Amount due RM 9,000.',
            'ai_at' => now(),
        ], $attrs));
    }

    /**
     * Whether the assistant's scope chip for a document is TICKED in the rendered page —
     * i.e. whether an answer would actually be grounded in it.
     */
    private function scopeTicked(string $html, string $key): bool
    {
        $at = strpos($html, 'value="'.$key.'"');
        if ($at === false) {
            return false;
        }

        // Only as far as the end of that one <input>; the next chip along may well be ticked.
        return str_contains(substr($html, $at, strpos($html, '>', $at) - $at), 'checked');
    }

    /**
     * A document uploaded and READ but not yet filed — the first half of the two-request
     * save the Add/Edit modals do (VendorDocumentScanController stores and reads, then the
     * modal posts the token with whatever the operator corrected).
     */
    private function stagedScan(Vendor $vendor, User $actor, string $kind, array $overrides = []): \App\Models\VendorDocumentScan
    {
        $path = $this->pdf('staged.pdf')->store(\App\Models\VendorDocumentScan::directoryFor($kind, $vendor->id), 'local');

        return \App\Models\VendorDocumentScan::create(array_merge([
            'vendor_id' => $vendor->id,
            'user_id' => $actor->id,
            'token' => (string) \Illuminate\Support\Str::uuid(),
            'kind' => $kind,
            'file_path' => $path,
            'original_filename' => 'staged.pdf',
            'status' => 'ok',
            'summary' => 'A summary read from the uploaded document.',
            'key_points' => [],
            'text' => 'FULL TRANSCRIPT',
            'companies' => [],
            'fields' => [],
        ], $overrides));
    }

    /**
     * Everything `vendors.show` needs, for the branches no role can reach through the
     * controller (every role that reaches the page can also manage it today).
     *
     * Kept in step with VendorController::show() by hand — this render bypasses the
     * controller, so a view variable added there has to be added here too, or the whole
     * suite fails on an undefined-variable ViewException that looks nothing like the thing
     * you changed.
     */
    private function showData(Vendor $vendor, bool $canManage): array
    {
        $vendor->load(['contracts', 'billingDocuments', 'assets']);

        return [
            'vendor' => $vendor,
            'assets' => $vendor->assets,
            'summary' => [
                'rented' => 0, 'purchased' => 0, 'monthly_rental' => 0.0,
                'contracts_active' => 0, 'contracts_expiring' => 0,
                'quotations' => 0, 'invoices' => 0, 'sst_flags' => 0,
            ],
            'canManage' => $canManage,
            'pendingAssets' => \App\Models\RentalAssetAcknowledgement::pendingAssetsFor($vendor),
            'acknowledgements' => $vendor->rentalAcknowledgements,
            'ewasteCycles' => collect(),
            'askable' => $vendor->askableDocuments(),
            'chatMessages' => $vendor->chatMessages,
            'askFocus' => null,
        ];
    }

    /** An Anthropic /v1/messages reply carrying $text, with the given stop_reason. */
    private function anthropic(string $text, string $stop = 'end_turn'): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 340],
        ];
    }

    private function fakeSummary(array $payload, string $stop = 'end_turn'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->anthropic(json_encode($payload), $stop)
        )]);
    }

    // ── The invalidation rule ─────────────────────────────────────────────────

    /**
     * THE trap this feature can produce. A replaced document leaves a summary and a
     * transcription describing a file that no longer exists — the row would show the old
     * PDF's summary under the new one's name, and the assistant would answer questions
     * about the new contract out of the old one's text.
     */
    public function test_replacing_a_document_clears_the_reading_of_the_one_it_replaced(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $contract = $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'A summary of the FIRST document.',
            'ai_key_points' => ['60 days notice'],
            'ai_text' => 'Clause 8: sixty (60) days notice.',
            'ai_at' => now()->subDay(),
            'companies_involved' => ['A Party That Signed The FIRST Document'],
        ]);
        $oldPath = $contract->file_path;

        // A replacement arrives the same way a new document does: scanned first, so its
        // summary can be reviewed before it is saved.
        $scan = $this->stagedScan($vendor, $actor, \App\Models\VendorDocumentScan::KIND_CONTRACT, [
            'status' => 'ok',
            'summary' => 'A summary of the SECOND document.',
            'key_points' => ['30 days notice'],
            'text' => 'Clause 8: thirty (30) days notice.',
            'companies' => ['Acme Rentals Sdn Bhd'],
        ]);

        $this->actingAs($actor)
            ->put(route('vendors.contracts.update', [$vendor, $contract]), [
                'scan_token' => $scan->token,
                'title' => 'Equipment Rental Agreement',
                'status' => 'active',
                'ai_summary' => 'A summary of the SECOND document.',
                'companies_involved' => 'Acme Rentals Sdn Bhd',
            ])
            ->assertRedirect();

        $contract->refresh();

        // Nothing of the first document's reading may survive under the second's name.
        $this->assertSame('A summary of the SECOND document.', $contract->ai_summary);
        $this->assertSame('Clause 8: thirty (30) days notice.', $contract->ai_text);
        $this->assertSame(['30 days notice'], $contract->ai_key_points);
        $this->assertSame(['Acme Rentals Sdn Bhd'], $contract->companies_involved);
        $this->assertStringNotContainsString('FIRST', (string) $contract->ai_summary);

        // And the file it described is gone, with the record pointing at the new one.
        $this->assertSame($scan->file_path, $contract->file_path);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($contract->file_path);
        $this->assertSame(0, \App\Models\VendorDocumentScan::count());
    }

    /**
     * A save that does not replace the document must not throw away its reading — the
     * summary is expensive, and re-reading a file that has not changed would replace
     * wording somebody may have corrected with a fresh opinion nobody asked for.
     */
    public function test_saving_without_a_new_document_leaves_the_reading_alone(): void
    {
        Queue::fake();
        $vendor = $this->vendor();

        $contract = $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'The summary as read.',
            'ai_text' => 'Clause 8: sixty (60) days notice.',
            'notice_period_days' => 60,
        ]);

        $this->actingAs($this->itManager())
            ->put(route('vendors.contracts.update', [$vendor, $contract]), [
                'ai_summary' => 'The summary as read.',
                'companies_involved' => 'Acme Rentals Sdn Bhd',
            ])
            ->assertRedirect();

        $contract->refresh();

        $this->assertSame('The summary as read.', $contract->ai_summary);
        $this->assertSame('Clause 8: sixty (60) days notice.', $contract->ai_text);
        $this->assertSame(60, $contract->notice_period_days, 'A term the form no longer shows must survive a save.');
        $this->assertFalse($contract->summaryIsEdited(), 'An unchanged summary must not be stamped as edited.');
        Queue::assertNotPushed(SummariseVendorDocument::class);
    }

    /**
     * There is no field-entry form anywhere any more — not on Add, not on Edit. A submit
     * carrying terms must therefore change nothing: no form displays them, so accepting one
     * from the request would be a way to set a contract value that was never on screen.
     */
    public function test_terms_posted_to_the_edit_form_are_ignored(): void
    {
        $vendor = $this->vendor();

        $contract = $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'As read.',
            'contract_value' => 493.00,
            'notice_period_days' => 30,
            'status' => 'active',
        ]);

        $this->actingAs($this->itManager())
            ->put(route('vendors.contracts.update', [$vendor, $contract]), [
                'ai_summary' => 'As read.',
                'title' => 'Renamed by hand',
                'contract_value' => '999999.00',
                'notice_period_days' => 1,
                'status' => 'terminated',
                'end_date' => '1999-01-01',
            ])
            ->assertRedirect();

        $contract->refresh();

        $this->assertSame('Equipment Rental Agreement', $contract->title);
        $this->assertSame('493.00', (string) $contract->contract_value);
        $this->assertSame(30, $contract->notice_period_days);
        $this->assertSame('active', $contract->status);
        $this->assertNull($contract->end_date);
    }

    /**
     * The summary is editable, and the row has to say who stands behind it. Printing
     * "Generated by AI" over wording a person typed is how a correction gets dismissed as a
     * machine's guess — and how a machine's guess gets trusted as a correction.
     */
    public function test_an_edited_summary_is_attributed_to_the_person_who_wrote_it(): void
    {
        $vendor = $this->vendor();
        $actor = $this->itManager();

        $contract = $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'What the model wrote.',
        ]);

        $this->actingAs($actor)
            ->put(route('vendors.contracts.update', [$vendor, $contract]), [
                'title' => 'Equipment Rental Agreement',
                'status' => 'active',
                'ai_summary' => 'What the operator corrected it to.',
            ])
            ->assertRedirect();

        $contract->refresh();

        $this->assertSame('What the operator corrected it to.', $contract->ai_summary);
        $this->assertTrue($contract->summaryIsEdited());
        $this->assertSame($actor->id, $contract->ai_summary_edited_by);
        $this->assertStringContainsString('Edited by', $contract->summaryProvenance());
        $this->assertStringNotContainsString('Generated by AI', $contract->summaryProvenance());

        // And a re-reading hands it back to the model, stamp and all — the wording it
        // replaces is not that person's any more.
        $this->fakeSummary(['summary' => 'Read again.', 'key_points' => [], 'text' => 'TEXT']);
        (new SummariseVendorDocument('contract', $contract->id))->handle();

        $contract->refresh();
        $this->assertFalse($contract->summaryIsEdited());
        $this->assertNull($contract->ai_summary_edited_by);
    }

    /** A stale transcription must not be askable while it is being replaced, either. */
    public function test_a_document_being_re_read_is_not_offered_to_the_assistant(): void
    {
        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor, ['ai_status' => 'pending']);

        $this->assertFalse($contract->hasAiText());
        $this->assertSame('it is still being read', $contract->aiUnavailableReason());
    }

    // ── Failing open ──────────────────────────────────────────────────────────

    public function test_a_failed_reading_keeps_the_document_and_its_extracted_fields(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor, [
            'contract_value' => 4500.00,
            'payment_terms' => '30 days from invoice date',
        ]);

        (new SummariseVendorDocument('contract', $contract->id))->handle();

        $contract->refresh();

        $this->assertSame('failed', $contract->ai_status);
        $this->assertNotNull($contract->file_path);
        $this->assertTrue(Storage::disk('local')->exists($contract->file_path));
        // The field scan's work is a different reading and must survive this one failing.
        $this->assertSame('4500.00', $contract->contract_value);
        $this->assertSame('30 days from invoice date', $contract->payment_terms);
    }

    /** A file recorded on the row but gone from disk is stated, not silently skipped. */
    public function test_a_missing_file_is_recorded_as_failed_rather_than_left_pending(): void
    {
        $vendor = $this->vendor();
        $contract = VendorContract::create([
            'vendor_id' => $vendor->id,
            'title' => 'Ghost',
            'status' => 'active',
            'file_path' => 'vendor_contracts/'.$vendor->id.'/gone.pdf',
            'ai_status' => 'pending',
        ]);

        (new SummariseVendorDocument('contract', $contract->id))->handle();

        $this->assertSame('failed', $contract->refresh()->ai_status);
    }

    /**
     * Only Anthropic can read a PDF. On any other provider the document is stored,
     * recorded as skipped, and — critically — excluded from the assistant WITH its reason
     * rather than quietly treated as a document containing nothing.
     */
    public function test_a_pdf_on_a_provider_that_cannot_read_pdfs_is_skipped_and_says_why(): void
    {
        config()->set('claims.ocr.provider', 'gemini');
        config()->set('claims.ocr.model', 'gemini-2.5-flash');
        Http::fake();

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);

        (new SummariseVendorDocument('contract', $contract->id))->handle();
        $contract->refresh();

        $this->assertSame('skipped', $contract->ai_status);
        $this->assertFalse($contract->hasAiText());
        $this->assertSame(
            'it is a PDF and the configured AI provider cannot read PDFs',
            $contract->aiUnavailableReason()
        );
        Http::assertNothingSent();

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'ask']))
            ->assertOk()
            ->assertSee('the configured AI provider cannot read PDFs', false);
    }

    // ── Truncation ────────────────────────────────────────────────────────────

    /**
     * A transcript cut off at max_tokens is not the whole document. Recorded as `partial`
     * so the assistant can never report a clause as absent when it simply never received
     * the page it was on.
     */
    public function test_a_truncated_transcription_is_recorded_as_partial(): void
    {
        $this->fakeSummary([
            'summary' => 'Rental of five laptops.',
            'key_points' => ['Term starts 01-01-2026'],
            'text' => 'Clause 1 … (cut off here)',
        ], stop: 'max_tokens');

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);

        (new SummariseVendorDocument('contract', $contract->id))->handle();
        $contract->refresh();

        $this->assertSame('partial', $contract->ai_status);
        // Still usable — a partial contract answers most questions; it just has to say so.
        $this->assertTrue($contract->hasAiText());
        $this->assertStringContainsString('Only part of this document', $contract->aiNote());
    }

    /** The context the model receives has to carry that warning, not just the page. */
    public function test_a_partial_document_is_flagged_inside_the_context_sent_to_the_model(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic('An answer.'))]);

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor, [
            'ai_status' => 'partial',
            'ai_summary' => 'Partial summary.',
            'ai_text' => 'Clause 1: the vendor supplies five laptops.',
        ]);

        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'What is supplied?'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $system = json_encode($request->data()['system'] ?? []);

            return str_contains($system, 'STATUS: PARTIAL')
                && str_contains($system, 'never report it as absent');
        });
    }

    // ── The assistant's scope ─────────────────────────────────────────────────

    /**
     * The document ids arrive from the request. Without scoping them back to the vendor
     * in the URL, one vendor's assistant could be grounded in another's contracts — on a
     * page whose whole premise is that it shows you one vendor.
     */
    public function test_a_document_belonging_to_another_vendor_is_never_read(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic('An answer.'))]);

        $ours = $this->vendor();
        $theirs = $this->vendor(['name' => 'Other Vendor Sdn Bhd']);

        $this->storedContract($ours, [
            'title' => 'Our Agreement',
            'ai_status' => 'ok',
            'ai_text' => 'OURS: the rate is RM 4,500 per month.',
        ]);
        $secret = $this->storedContract($theirs, [
            'title' => 'Their Agreement',
            'ai_status' => 'ok',
            'ai_text' => 'THEIRS: confidential rate RM 99,999.',
        ]);

        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $ours), [
                'question' => 'What is the rate?',
                'documents' => [$secret->askKey()],
            ])
            // Nothing of ours was selected and the foreign key matched nothing, so there is
            // no scope at all — which is refused rather than silently widened.
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_answer_names_the_documents_it_read(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->anthropic('The notice period is **60 days** (Clause 8).')
        )]);

        $vendor = $this->vendor();
        $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_text' => 'Clause 8: sixty (60) days written notice.',
        ]);

        $response = $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'What notice must we give?'])
            ->assertOk();

        $used = $response->json('answer.used');
        $this->assertNotEmpty($used);
        $this->assertStringContainsString('Equipment Rental Agreement', $used[0]);

        $this->assertDatabaseCount('vendor_chat_messages', 2);
    }

    /**
     * A document dropped for size must be NAMED. An answer that silently never saw a
     * contract reads exactly like one that read it and found nothing.
     */
    public function test_documents_dropped_for_size_are_named_in_the_answer(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic('An answer.'))]);
        config()->set('vendors.ai.chat_context_chars', 10000);

        $vendor = $this->vendor();
        $this->storedContract($vendor, [
            'title' => 'Newer Agreement',
            'ai_status' => 'ok',
            'ai_text' => str_repeat('NEWER contract wording. ', 500),
            'ai_at' => now(),
        ]);
        $this->storedContract($vendor, [
            'title' => 'Older Agreement',
            'ai_status' => 'ok',
            'ai_text' => str_repeat('OLDER contract wording. ', 500),
            'ai_at' => now()->subYear(),
        ]);

        $response = $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'Summarise our position.'])
            ->assertOk();

        $this->assertSame(['Contract — Newer Agreement (ref. RC-2026-01)'], $response->json('answer.used'));

        $excluded = $response->json('answer.excluded');
        $this->assertCount(1, $excluded);
        $this->assertStringContainsString('Older Agreement', $excluded[0]['label']);
        $this->assertStringContainsString('size limit', $excluded[0]['reason']);
    }

    public function test_asking_with_no_readable_document_refuses_instead_of_guessing(): void
    {
        Http::fake();
        $vendor = $this->vendor();
        $this->storedContract($vendor, ['ai_status' => 'failed']);

        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'What are the terms?'])
            ->assertStatus(422);

        Http::assertNothingSent();
        $this->assertDatabaseCount('vendor_chat_messages', 0);
    }

    /** A reachable provider that fails still leaves a record of what was asked. */
    public function test_a_failed_answer_still_records_the_question(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'nope'], 500)]);

        $vendor = $this->vendor();
        $this->storedContract($vendor, ['ai_status' => 'ok', 'ai_text' => 'Clause 1.']);

        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'Anything?'])
            ->assertOk();

        $this->assertDatabaseCount('vendor_chat_messages', 2);
        $reply = VendorChatMessage::where('role', 'assistant')->first();
        $this->assertTrue($reply->context_json['failed']);
        $this->assertNull($reply->model);
    }

    // ── The thread ────────────────────────────────────────────────────────────

    /**
     * "Start new topic" bounds what the NEXT question carries. It must not delete
     * anything: the thread is the record of what the assistant was asked about a
     * commercial document and what it said back.
     */
    public function test_starting_a_new_topic_bounds_the_context_without_deleting_history(): void
    {
        $vendor = $this->vendor();

        VendorChatMessage::create(['vendor_id' => $vendor->id, 'role' => 'user', 'content' => 'OLD QUESTION']);
        VendorChatMessage::create(['vendor_id' => $vendor->id, 'role' => 'assistant', 'content' => 'OLD ANSWER']);

        $this->actingAs($this->itManager())
            ->post(route('vendors.ask.new-topic', $vendor))
            ->assertRedirect();

        $this->assertDatabaseCount('vendor_chat_messages', 3);
        $this->assertSame([], VendorChatMessage::contextFor($vendor));

        // And it is still on the page — bounded, not erased.
        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'ask']))
            ->assertOk()
            ->assertSee('OLD QUESTION');
    }

    public function test_a_new_topic_marker_is_not_repeated_on_an_empty_thread(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())->post(route('vendors.ask.new-topic', $vendor));
        $this->actingAs($this->itManager())->post(route('vendors.ask.new-topic', $vendor));

        $this->assertDatabaseCount('vendor_chat_messages', 0);
    }

    // ── Access ────────────────────────────────────────────────────────────────

    public function test_a_role_outside_vendor_management_cannot_ask_or_summarise(): void
    {
        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);
        $intern = User::factory()->create(['role' => 'it_intern']);

        $this->actingAs($intern)->postJson(route('vendors.ask', $vendor), ['question' => 'Hi'])->assertForbidden();
        $this->actingAs($intern)->post(route('vendors.contracts.summarise', [$vendor, $contract]))->assertForbidden();
        $this->actingAs($intern)->getJson(route('vendors.insights', $vendor))->assertForbidden();
    }

    /** A contract reached through another vendor's URL is a 404, not an edit. */
    public function test_summarising_through_the_wrong_vendors_route_is_refused(): void
    {
        Queue::fake();
        $ours = $this->vendor();
        $theirs = $this->vendor(['name' => 'Other Vendor Sdn Bhd']);
        $contract = $this->storedContract($theirs);

        $this->actingAs($this->itManager())
            ->post(route('vendors.contracts.summarise', [$ours, $contract]))
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    // ── Billing side ──────────────────────────────────────────────────────────

    public function test_a_billing_document_is_read_with_its_own_framing(): void
    {
        $this->fakeSummary([
            'summary' => 'Invoice for one month of laptop rental.',
            'key_points' => ['Due 11-08-2026'],
            'text' => 'INVOICE INV-8821. Total MYR 1,908.00.',
        ]);

        $vendor = $this->vendor();
        $path = $this->pdf('invoice.pdf')->store('vendor_billing/'.$vendor->id, 'local');
        $doc = VendorBillingDocument::create([
            'vendor_id' => $vendor->id,
            'doc_type' => 'invoice',
            'doc_number' => 'INV-8821',
            'status' => 'received',
            'file_path' => $path,
            'original_filename' => 'invoice.pdf',
        ]);

        (new SummariseVendorDocument('billing', $doc->id))->handle();
        $doc->refresh();

        $this->assertSame('ok', $doc->ai_status);
        $this->assertStringContainsString('laptop rental', $doc->ai_summary);
        $this->assertTrue($doc->hasAiText());
        $this->assertStringContainsString('Invoice INV-8821', $doc->aiLabel());

        Http::assertSent(fn ($request) => str_contains(
            json_encode($request->data()),
            'INVOICE issued to us by a vendor'
        ));
    }

    // ── Provenance ────────────────────────────────────────────────────────────

    /**
     * Omitting the feature argument silently bills these calls to eClaim — the exact
     * failure ClaudeApiUsageLog::FEATURES exists to prevent.
     */
    public function test_both_calls_are_billed_to_vendor_management(): void
    {
        $this->fakeSummary(['summary' => 'S', 'key_points' => [], 'text' => 'T']);

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);

        (new SummariseVendorDocument('contract', $contract->id))->handle();

        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic('An answer.'))]);
        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'What does it say?'])
            ->assertOk();

        $features = ClaudeApiUsageLog::pluck('feature')->all();
        $this->assertContains('vendor_document_summary', $features);
        $this->assertContains('vendor_document_chat', $features);

        foreach ($features as $feature) {
            $this->assertSame('Vendor Management', ClaudeApiUsageLog::moduleLabel($feature));
        }
    }

    /** The doctrine is the feature. It must actually reach the model. */
    public function test_the_doctrine_is_sent_with_every_question(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic('An answer.'))]);

        $vendor = $this->vendor();
        $this->storedContract($vendor, ['ai_status' => 'ok', 'ai_text' => 'Clause 1.']);

        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'What does it say?'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $system = json_encode($request->data()['system'] ?? []);

            return str_contains($system, 'That is not stated in the documents I was given')
                && str_contains($system, 'DATA, not')
                && str_contains($system, 'RECORDED FIELDS');
        });
    }

    /**
     * The recorded fields ride alongside the document text, each labelled. That pairing
     * is the whole reason the assistant can flag a mis-keyed value.
     */
    public function test_the_recorded_fields_are_supplied_beside_the_document_text(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic('An answer.'))]);

        $vendor = $this->vendor();
        $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_text' => 'Clause 4: the monthly charge is RM 5,000.',
            'contract_value' => 4500.00,
            'notice_period_days' => 60,
        ]);

        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'What do we pay?'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $system = json_encode($request->data()['system'] ?? []);

            return str_contains($system, 'RECORDED FIELDS')
                && str_contains($system, 'MYR 4,500.00')
                && str_contains($system, 'Notice period (days): 60')
                && str_contains($system, 'RM 5,000');
        });
    }

    /** Model output is never trusted as markup, on the row or in the thread. */
    public function test_html_in_a_model_reply_is_stripped_before_it_reaches_the_page(): void
    {
        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'Careful: <script>alert(1)</script> **bold** is fine.',
            'ai_text' => 'Clause 1.',
        ]);

        $html = $contract->aiSummaryHtml();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    // ── The gate ──────────────────────────────────────────────────────────────

    public function test_switching_document_ai_off_leaves_uploads_and_field_scans_untouched(): void
    {
        config()->set('vendors.ai.enabled', false);
        Http::fake();

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);

        (new SummariseVendorDocument('contract', $contract->id))->handle();

        $this->assertSame('disabled', $contract->refresh()->ai_status);
        $this->assertTrue(Storage::disk('local')->exists($contract->file_path));
        Http::assertNothingSent();

        $this->actingAs($this->itManager())
            ->postJson(route('vendors.ask', $vendor), ['question' => 'Anything?'])
            ->assertStatus(422);
    }

    /** A reply with nothing in it is "read and found nothing", not a silent success. */
    public function test_a_reply_with_no_content_is_recorded_as_empty(): void
    {
        $this->fakeSummary(['summary' => null, 'key_points' => [], 'text' => null]);

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);

        (new SummariseVendorDocument('contract', $contract->id))->handle();
        $contract->refresh();

        $this->assertSame('empty', $contract->ai_status);
        $this->assertFalse($contract->hasAiText());
        $this->assertStringContainsString('nothing could be summarised', $contract->aiNote());
    }

    /** The two document tables share one key space, or a scope selection is ambiguous. */
    public function test_a_contract_and_a_billing_document_with_the_same_id_do_not_collide(): void
    {
        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);
        $doc = VendorBillingDocument::create([
            'vendor_id' => $vendor->id, 'doc_type' => 'invoice', 'status' => 'received',
        ]);

        $this->assertNotSame($contract->askKey(), $doc->askKey());
        $this->assertSame('contract:'.$contract->id, $contract->askKey());
        $this->assertSame('billing:'.$doc->id, $doc->askKey());
    }

    // ── Backfill ──────────────────────────────────────────────────────────────

    /**
     * The default only picks up documents nobody has ever tried to read. A `failed` or
     * `skipped` row was tried, and silently re-billing it on every deployment is not the
     * behaviour anyone would expect from a backfill.
     */
    public function test_the_backfill_reads_only_untried_documents_unless_told_otherwise(): void
    {
        Queue::fake();
        $vendor = $this->vendor();

        $this->storedContract($vendor, ['title' => 'Never tried']);
        $this->storedContract($vendor, ['title' => 'Already skipped', 'ai_status' => 'skipped']);

        $this->artisan('vendors:summarise-documents')->assertExitCode(0);
        Queue::assertPushed(SummariseVendorDocument::class, 1);

        $this->artisan('vendors:summarise-documents', ['--redo' => 'skipped'])->assertExitCode(0);
        Queue::assertPushed(SummariseVendorDocument::class, 2);
    }

    public function test_the_backfill_dry_run_queues_nothing(): void
    {
        Queue::fake();
        $vendor = $this->vendor();
        $this->storedContract($vendor);

        $this->artisan('vendors:summarise-documents', ['--dry-run' => true])->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // ── The page ──────────────────────────────────────────────────────────────

    public function test_the_summary_is_shown_on_the_row_and_labelled_as_ai_generated(): void
    {
        $vendor = $this->vendor();
        $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'Five laptops rented at RM 4,500 per month.',
            'ai_key_points' => ['60 days notice to terminate'],
            'ai_at' => now(),
        ]);

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->assertSee('Five laptops rented at RM 4,500 per month.')
            ->assertSee('60 days notice to terminate')
            ->assertSee('Generated by AI from the uploaded document');
    }

    /**
     * The AI summary sits BESIDE the operator's own scope_summary, never on top of it —
     * that column is a human's transcription and an edit must not be overwritten.
     */
    public function test_the_reading_never_writes_over_the_operators_own_summary(): void
    {
        $this->fakeSummary([
            'summary' => 'The MODEL wrote this.',
            'key_points' => [],
            'text' => 'Clause 1.',
        ]);

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor, [
            'scope_summary' => 'A HUMAN wrote this.',
        ]);

        (new SummariseVendorDocument('contract', $contract->id))->handle();
        $contract->refresh();

        $this->assertSame('A HUMAN wrote this.', $contract->scope_summary);
        $this->assertSame('The MODEL wrote this.', $contract->ai_summary);
    }

    // ── The floating assistant ────────────────────────────────────────────────

    /**
     * The assistant is a floating panel over the page, not a sixth tab.
     *
     * The questions it answers are asked while reading a contract row, an invoice or the
     * asset list — as a tab, reaching it meant leaving the very row being asked about. So
     * the panel and its button must render on EVERY tab, and there must be no tab button
     * competing with them.
     */
    public function test_the_assistant_is_a_floating_panel_reachable_from_every_tab(): void
    {
        $vendor = $this->vendor();
        VendorChatMessage::create([
            'vendor_id' => $vendor->id,
            'role' => 'user',
            'content' => 'AN EARLIER QUESTION',
        ]);

        foreach (['profile', 'contracts', 'billing', 'assets'] as $tab) {
            $html = $this->actingAs($this->itManager())
                ->get(route('vendors.show', [$vendor, 'tab' => $tab]))
                ->assertOk()
                // The button and the panel it opens, on whichever tab is showing.
                ->assertSee('data-bs-target="#vndAskPanel"', false)
                ->assertSee('id="vndAskPanel"', false)
                // And the thread itself, so the panel is genuinely on the page rather than
                // a control pointing at something only the Ask tab rendered.
                ->assertSee('AN EARLIER QUESTION')
                ->getContent();

            // The tab button is gone — matched with its closing quote, which is what keeps
            // this from passing on the panel's own "#vndAskPanel".
            $this->assertStringNotContainsString('data-bs-target="#vndAsk"', $html);
        }
    }

    /**
     * "Ask about this document" must leave the operator where they were reading.
     *
     * The link reloads the page, so the tab it carries is the tab they land on. Sending
     * tab=ask — which no longer names a tab — would bounce them to Profile and away from
     * the row they just asked about; `focus` alone is what opens the panel.
     */
    public function test_asking_about_a_document_keeps_the_operator_on_its_own_tab(): void
    {
        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'Five laptops rented at RM 4,500 per month.',
            'ai_text' => 'Clause 1. The term is 24 months.',
            'ai_at' => now(),
        ]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        // `ask` opens the panel, `focus` says which document — the tab stays the one they
        // were reading.
        $this->assertStringContainsString(
            'tab=contracts&amp;ask=1&amp;focus=contract%3A'.$contract->id,
            $html
        );
        $this->assertStringNotContainsString('tab=ask', $html);
    }

    /**
     * One assistant, one icon.
     *
     * A document row's button and the floating one open the same panel, and giving the row
     * a different mark said they were two different features. What separates them is the
     * SCOPE, which the panel states in words — not the icon on the button.
     */
    public function test_the_row_button_carries_the_assistants_own_icon(): void
    {
        $vendor = $this->vendor();
        $contract = $this->readContract($vendor);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts']))
            ->assertOk()
            ->getContent();

        // Sliced to the row's OWN button. A loose search for the icon would pass on the
        // floating button further down the page, which is exactly the one it has to match.
        $at = strpos($html, 'data-vnd-ask-focus="'.$contract->askKey().'"');
        $this->assertNotFalse($at, 'The contract row has no Ask AI button.');

        $button = substr($html, $at, strpos($html, '</a>', $at) - $at);
        $this->assertStringContainsString('bi bi-robot', $button);
        $this->assertStringNotContainsString('bi-chat-dots', $html);
    }

    /**
     * Asking from a row asks about THAT document.
     *
     * And the panel has to say which, in words. "1 of 2 in scope" is true and useless: it
     * gives the reader no way to tell which document an answer came from, so one grounded
     * in the wrong contract reads exactly like one grounded in the right one.
     */
    public function test_opening_the_assistant_from_a_row_scopes_it_to_that_document_alone(): void
    {
        $vendor = $this->vendor();
        $contract = $this->readContract($vendor);
        $invoice = $this->readInvoice($vendor);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts', 'ask' => 1, 'focus' => $contract->askKey()]))
            ->assertOk()
            ->assertSee('Asking about: '.$contract->aiLabel())
            ->getContent();

        $this->assertTrue($this->scopeTicked($html, $contract->askKey()));
        $this->assertFalse($this->scopeTicked($html, $invoice->askKey()),
            'The invoice would have been read for an answer about the contract.');

        // And the box the question is typed into names it too — someone who came from a
        // row is looking there, not at the toolbar, when they decide what to ask. Asserted
        // on the opening of the label, which a long one is clipped after.
        $this->assertStringContainsString('placeholder="Ask about Contract — Equipment Rental', $html);
    }

    /**
     * The floating button is the whole-page assistant, and means that every time it is
     * pressed — including on a page opened about a single row.
     */
    public function test_the_floating_button_covers_every_readable_document(): void
    {
        $vendor = $this->vendor();
        $contract = $this->readContract($vendor);
        $invoice = $this->readInvoice($vendor);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Asking about all 2 documents')
            ->getContent();

        $this->assertTrue($this->scopeTicked($html, $contract->askKey()));
        $this->assertTrue($this->scopeTicked($html, $invoice->askKey()));
    }

    /**
     * A focus naming a document that cannot be asked about falls back to everything.
     *
     * Ticking nothing would read as an empty scope — a panel that says it has nothing to
     * answer from — rather than as the one document that has not been read, which the
     * blocked list below states with its reason.
     */
    public function test_a_focus_on_an_unreadable_document_falls_back_to_every_document(): void
    {
        $vendor = $this->vendor();
        $contract = $this->readContract($vendor);
        $invoice = $this->readInvoice($vendor);
        $unread = $this->storedContract($vendor, ['title' => 'Never Read Agreement']);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', [$vendor, 'tab' => 'contracts', 'ask' => 1, 'focus' => $unread->askKey()]))
            ->assertOk()
            ->assertSee('Asking about all 2 documents')
            ->getContent();

        $this->assertTrue($this->scopeTicked($html, $contract->askKey()));
        $this->assertTrue($this->scopeTicked($html, $invoice->askKey()));

        // Not silently dropped either: it is listed with the reason it cannot be asked about.
        $this->assertStringContainsString('Never Read Agreement', $html);
    }

    /**
     * The panel must be able to read an unread document itself.
     *
     * Its own "Re-summarise" lives on the document row — which is behind this panel's
     * backdrop while it is open. Telling the operator to press a button they cannot reach
     * is the whole reason this exists, so the panel carries its own, and the redirect
     * brings them back to the panel instead of the tab underneath.
     */
    public function test_the_panel_can_read_an_unread_document_without_leaving_it(): void
    {
        Queue::fake();
        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);   // filed, never read

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('vendors.contracts.summarise', [$vendor, $contract]),
            $html
        );
        // The old instruction pointed at a button behind the backdrop.
        $this->assertStringNotContainsString('press Re-summarise on its row', $html);

        $this->actingAs($this->itManager())
            ->post(route('vendors.contracts.summarise', [$vendor, $contract]), ['from' => 'ask'])
            ->assertRedirect(route('vendors.show', [$vendor, 'tab' => 'contracts', 'ask' => 1]));

        Queue::assertPushed(SummariseVendorDocument::class);
    }

    /**
     * A read-only viewer gets the reason without a button that would 403 — the same rule
     * the rest of the module follows for a control its owner cannot use.
     */
    public function test_a_read_only_viewer_is_not_offered_the_read_button(): void
    {
        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);

        $html = view('vendors.show', $this->showData($vendor, canManage: false))->render();

        $this->assertStringContainsString('it has not been read yet', $html);
        $this->assertStringNotContainsString(
            route('vendors.contracts.summarise', [$vendor, $contract]),
            $html
        );
    }

    /**
     * Every assistant action returns to the assistant, by ONE param. `?tab=ask` named a
     * tab that no longer exists, so a redirect still carrying it would land the operator
     * on Profile with the panel shut — reading as the action having closed it.
     */
    public function test_assistant_actions_redirect_back_into_the_panel(): void
    {
        $vendor = $this->vendor();
        VendorChatMessage::create([
            'vendor_id' => $vendor->id,
            'role' => 'user',
            'content' => 'A QUESTION',
        ]);

        $this->actingAs($this->itManager())
            ->post(route('vendors.ask.new-topic', $vendor))
            ->assertRedirect(route('vendors.show', [$vendor, 'ask' => 1]));
    }

    /**
     * "Nothing has been read" and "nothing has been FILED" are different problems with
     * different next moves. The panel used to say the first for both, sending someone
     * looking for a read button on a vendor that has no documents to read.
     */
    public function test_a_vendor_with_no_documents_says_so_rather_than_blaming_the_reading(): void
    {
        $vendor = $this->vendor();

        $this->actingAs($this->itManager())
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('No contracts or billing documents are filed for this vendor yet.')
            ->assertDontSee('None of this vendor\'s documents have been read yet.', false)
            // The composer stays, disabled, carrying the reason — a chat panel with no
            // input at all reads as broken rather than as empty.
            ->assertSee('No documents are filed for this vendor yet');
    }

    /**
     * With document AI switched off the readable documents are still LISTED — hiding what
     * the assistant can see behind a config switch is the one thing this panel must not do
     * — but every control that would spend money is inert.
     */
    public function test_switching_ai_off_leaves_the_scope_visible_but_inert(): void
    {
        config()->set('vendors.ai.enabled', false);

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor, [
            'ai_status' => 'ok',
            'ai_summary' => 'Five laptops.',
            'ai_text' => 'Clause 1. The term is 24 months.',
        ]);

        $html = $this->actingAs($this->itManager())
            ->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Document AI is switched off for vendors')
            ->getContent();

        // Still listed as something the assistant can see…
        $this->assertStringContainsString('value="'.$contract->askKey().'"', $html);

        // …but the composer is the disabled one carrying the reason, and the scope has no
        // controls. Asserted on MARKUP, never on the data-* attribute names: every one of
        // those also appears as a literal selector in the page's own script, so a negative
        // assertion on `data-vnd-ask-form` passes on the JS and proves nothing.
        $this->assertStringContainsString('placeholder="Document AI is switched off for vendors"', $html);
        $this->assertStringNotContainsString('>Select all<', $html);
    }

    public function test_the_service_reports_a_status_rather_than_throwing_on_a_bad_reply(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->anthropic('not json at all'))]);

        $vendor = $this->vendor();
        $contract = $this->storedContract($vendor);

        $result = VendorDocumentInsightService::read(
            Storage::disk('local')->path($contract->file_path),
            'application/pdf',
            'contract',
            $vendor->name
        );

        $this->assertSame('failed', $result['status']);
        $this->assertNull($result['text']);
    }
}
