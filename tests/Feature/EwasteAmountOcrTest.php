<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Services\EwasteDocumentOcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The amount on a quotation/receipt is read off the document rather than re-keyed, but a human
 * always owns the final figure. OCR must fail OPEN — it can never block an upload, because the
 * quotation and receipt gate the whole disposal cycle.
 */
class EwasteAmountOcrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        Mail::fake();
        $this->forgetClaudeMemo();
        // Force the Anthropic path: it is the only provider that can read a PDF here.
        config(['claims.ocr.enabled' => true, 'claims.ocr.provider' => 'anthropic', 'claims.ocr.api_key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        $this->forgetClaudeMemo();
        parent::tearDown();
    }

    /**
     * ClaimReceiptOcrService memoises the ClaudeApiSetting lookup in a STATIC, and nothing ever
     * resets it. That is fine in production (one resolution per request) but inside a single
     * PHPUnit process it leaks across tests: whichever test runs first pins the value for every
     * test after it. Without this reset, the live-path test below silently exercises the env
     * fallback instead of the Claude API page, and would pass while proving nothing.
     */
    private function forgetClaudeMemo(): void
    {
        foreach (['claudeMemo' => null, 'claudeMemoLoaded' => false] as $prop => $value) {
            $p = new \ReflectionProperty(\App\Services\ClaimReceiptOcrService::class, $prop);
            $p->setAccessible(true);
            $p->setValue(null, $value);
        }
    }

    /** Fake an Anthropic /v1/messages reply whose text is $text. */
    private function fakeVision(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $text]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);
    }

    /**
     * The same reply as a THINKING-enabled model returns it: a `thinking` block first, the JSON
     * second. Claude Sonnet 5 — offered on the Claude API settings page — runs adaptive thinking
     * whenever the `thinking` parameter is omitted, which is how we call. Haiku 4.5 and Opus 4.8
     * do not, so this break only ever showed on the model labelled "higher accuracy".
     */
    private function fakeThinkingVision(string $text): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'thinking', 'thinking' => ''],
                ['type' => 'text', 'text' => $text],
            ],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 120],
        ], 200)]);
    }

    private function pdf(string $name = 'quote.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<h1>SAMPLE QUOTATION Grand Total 923.40</h1>')->output()
        );
    }

    private function cycle(string $status = 'awaiting_quotation'): AssetDecommissionBatch
    {
        $batch = AssetDecommissionBatch::create([
            'batch_number' => 'EWA-2026-Q4', 'type' => 'e_waste', 'status' => $status,
            'finance_status' => $status === 'finance_approved' ? 'approved' : null,
        ]);
        $asset = AssetInventory::factory()->create(['asset_condition' => 'not_good', 'decommission_batch_id' => $batch->id]);
        DisposedAsset::create([
            'asset_inventory_id' => $asset->id, 'asset_tag' => $asset->asset_tag,
            'asset_type' => $asset->asset_type, 'brand' => $asset->brand, 'model' => $asset->model,
            'serial_number' => $asset->serial_number, 'asset_condition' => 'not_good',
            'decommission_type' => 'e_waste', 'decommission_batch_id' => $batch->id,
            'disposed_by' => 'IT', 'disposed_at' => now(),
        ]);

        return $batch;
    }

    public function test_amount_is_read_from_the_uploaded_quotation(): void
    {
        Storage::fake('local');
        $this->fakeVision('{"amount": 923.40, "currency": "MYR"}');
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()])
            ->assertRedirect();

        $this->assertSame('923.40', $batch->fresh()->quotation_amount);
    }

    /** A PDF must go in an Anthropic `document` block — an `image` block is rejected outright. */
    public function test_a_pdf_is_sent_as_a_document_block_not_an_image(): void
    {
        Storage::fake('local');
        $this->fakeVision('{"amount": 100, "currency": "MYR"}');
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()]);

        Http::assertSent(function ($request) {
            $types = array_column($request['messages'][0]['content'], 'type');

            return in_array('document', $types, true) && ! in_array('image', $types, true);
        });
    }

    /**
     * Regression: the reply was read as `content[0].text`. On a thinking-enabled model
     * `content[0]` is a `thinking` block, so that returned null, OCR failed open, and every
     * amount came back blank with no error — silently, and only on the models the settings page
     * labels "higher accuracy". The parser takes the first TEXT block instead.
     */
    public function test_a_thinking_model_reply_is_parsed_not_silently_dropped(): void
    {
        Storage::fake('local');
        $this->fakeThinkingVision('{"amount": 923.40, "currency": "MYR"}');
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()])
            ->assertRedirect();

        $this->assertSame('923.40', $batch->fresh()->quotation_amount);
    }

    /**
     * max_tokens caps thinking AND text together on those models, so a ceiling sized to this
     * tiny JSON gets spent thinking and returns no text at all.
     */
    public function test_the_token_ceiling_leaves_room_for_thinking(): void
    {
        Storage::fake('local');
        $this->fakeVision('{"amount": 1, "currency": "MYR"}');
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()]);

        Http::assertSent(fn ($request) => $request['max_tokens'] >= 1024);
    }

    /** Anthropic's documented ordering for document Q&A: the document precedes the prompt. */
    public function test_the_document_block_precedes_the_prompt(): void
    {
        Storage::fake('local');
        $this->fakeVision('{"amount": 1, "currency": "MYR"}');
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()]);

        Http::assertSent(function ($request) {
            $types = array_column($request['messages'][0]['content'], 'type');

            return array_search('document', $types, true) < array_search('text', $types, true);
        });
    }

    /** A typed amount is the human's call and must win over whatever OCR reads. */
    public function test_a_typed_amount_overrides_ocr(): void
    {
        Storage::fake('local');
        $this->fakeVision('{"amount": 923.40, "currency": "MYR"}');
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), [
                'quotation_file' => $this->pdf(),
                'quotation_amount' => '850.00',
            ]);

        $this->assertSame('850.00', $batch->fresh()->quotation_amount);
    }

    /**
     * Fail open: OCR is best-effort. A provider error must leave the amount null and still
     * complete the upload — the quotation gates the entire cycle.
     */
    public function test_a_failing_provider_does_not_block_the_upload(): void
    {
        Storage::fake('local');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()])
            ->assertRedirect();

        $fresh = $batch->fresh();
        $this->assertNull($fresh->quotation_amount);
        $this->assertNotNull($fresh->quotation_path);      // the document still landed
        $this->assertSame('quotation_uploaded', $fresh->status);
    }

    /** Garbage from the model must never reach a financial report. */
    public function test_unusable_ocr_values_are_rejected(): void
    {
        $sanitize = new \ReflectionMethod(EwasteDocumentOcrService::class, 'sanitizeAmount');
        $sanitize->setAccessible(true);

        $this->assertSame(923.4, $sanitize->invoke(null, '923.40'));
        $this->assertSame(923.4, $sanitize->invoke(null, 'RM 923.40'));
        $this->assertNull($sanitize->invoke(null, null));
        $this->assertNull($sanitize->invoke(null, 'not a number'));
        // Zero would report "the vendor paid us nothing" as fact.
        $this->assertNull($sanitize->invoke(null, 0));
        $this->assertNull($sanitize->invoke(null, -50));
        // Classic decimal-point / thousands-separator misread.
        $this->assertNull($sanitize->invoke(null, 92340000000));
    }

    /** Only Anthropic can read a PDF here; others must skip rather than send a rejected request. */
    public function test_a_non_pdf_capable_provider_skips_pdfs_instead_of_failing(): void
    {
        Storage::fake('local');
        config(['claims.ocr.provider' => 'gemini']);
        Http::fake();
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()])
            ->assertRedirect();

        $this->assertNull($batch->fresh()->quotation_amount);
        Http::assertNothingSent();
    }

    public function test_it_can_correct_and_clear_the_amount_without_reuploading(): void
    {
        Storage::fake('local');
        $it = User::factory()->create(['role' => 'it_manager']);
        $batch = $this->cycle('quotation_uploaded');
        $batch->update(['quotation_path' => 'ewaste_quotations/q.pdf', 'quotation_uploaded_at' => now(), 'quotation_amount' => 900]);

        $this->actingAs($it)->post(route('ewaste.amount', $batch), ['field' => 'quotation', 'amount' => '923.40'])
            ->assertRedirect();
        $this->assertSame('923.40', $batch->fresh()->quotation_amount);

        // Empty clears it — the report then points at the attached document, not RM 0.00.
        $this->actingAs($it)->post(route('ewaste.amount', $batch), ['field' => 'quotation', 'amount' => '']);
        $this->assertNull($batch->fresh()->quotation_amount);
    }

    /**
     * THE LIVE PATH. Production does not use CLAIMS_OCR_* at all — it resolves through the
     * Claude API settings page, a different branch of callVision(). Every other test here
     * forces the env-override branch, so without this one the branch live actually runs was
     * never exercised. Note live has CLAIMS_OCR_PROVIDER=gemini and this must still go to
     * Anthropic: an active Claude API page outranks the env override.
     */
    public function test_the_claude_api_settings_page_drives_ocr_and_outranks_the_env_provider(): void
    {
        Storage::fake('local');
        config(['claims.ocr.enabled' => false, 'claims.ocr.provider' => 'gemini', 'claims.ocr.api_key' => null]);

        \App\Models\ClaudeApiSetting::current()->update([
            'api_key' => 'sk-ant-live-key',
            'model' => 'claude-sonnet-5',
            'enabled' => true,
        ]);
        $this->forgetClaudeMemo();

        $this->fakeVision('{"amount": 1450.75, "currency": "MYR"}');
        $batch = $this->cycle();

        $this->actingAs(User::factory()->create(['role' => 'it_manager']))
            ->post(route('ewaste.quotation', $batch), ['quotation_file' => $this->pdf()])
            ->assertRedirect();

        $this->assertSame('1450.75', $batch->fresh()->quotation_amount);

        Http::assertSent(function ($request) {
            $types = array_column($request['messages'][0]['content'], 'type');

            return str_contains($request->url(), 'api.anthropic.com')          // not Gemini
                && $request->header('x-api-key')[0] === 'sk-ant-live-key'      // the stored key
                && $request['model'] === 'claude-sonnet-5'                     // the chosen model
                && in_array('document', $types, true);                         // PDF as a document
        });
    }

    /** With the Claude page active, PDFs are readable regardless of the env provider override. */
    public function test_pdf_capability_follows_the_claude_api_page_not_the_env_override(): void
    {
        config(['claims.ocr.provider' => 'gemini']);
        $this->forgetClaudeMemo();
        $this->assertFalse(EwasteDocumentOcrService::pdfCapable(), 'gemini alone cannot read PDFs');

        \App\Models\ClaudeApiSetting::current()->update(['api_key' => 'sk-ant-x', 'enabled' => true]);
        $this->forgetClaudeMemo();
        $this->assertTrue(EwasteDocumentOcrService::pdfCapable(), 'an active Claude key must enable PDF OCR');
    }

    public function test_finance_cannot_edit_the_amount(): void
    {
        $batch = $this->cycle('quotation_uploaded');

        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->post(route('ewaste.amount', $batch), ['field' => 'quotation', 'amount' => '1.00'])
            ->assertForbidden();
    }
}
