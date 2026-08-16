<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\AssetDecommissionBatch;
use App\Models\AssetInventory;
use App\Models\DisposedAsset;
use App\Models\User;
use App\Models\Vendor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Ask AI to compare quotations" (added 2026-08-16) — EwasteQuotationComparisonService and
 * AssetDecommissionController::compareQuotations().
 *
 * Explicit and billed, never automatic: IT clicks a button, the service reads every current
 * quotation and suggests one, and the result only PRE-FILLS the Recommend form —
 * submitForApproval() is still what the module treats as IT's actual recommendation.
 *
 * Fails open throughout, like every AI service in this app — in this test environment no
 * Claude key is configured, so most tests exercise the 'disabled' path exactly the way the
 * feature behaves for any operator who hasn't switched on AI reading. The two tests that
 * exercise a live comparison configure a fake key and fake the Anthropic transport.
 */
class EwasteQuotationComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        // Once per test, not per upload: Storage::fake() wipes the disk on every call, which
        // would delete an earlier vendor's quotation the moment a second one is filed.
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

    /** A real PDF — the project's valid_file_content rule checks magic bytes. */
    private function pdf(string $name = 'quote.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            Pdf::loadHTML('<html><body><h1>'.e($name).'</h1></body></html>')->output()
        );
    }

    /**
     * Storage::fake('local') is NOT called here, deliberately — it wipes any previously
     * stored file on that disk, which would delete an earlier vendor's quotation the moment
     * a second one is filed. Call it ONCE, before the first upload in a test.
     */
    private function fileQuotation(User $it, AssetDecommissionBatch $batch, Vendor $vendor, float $amount): void
    {
        $this->actingAs($it)->post(route('ewaste.quotation', $batch), [
            'vendor_id' => $vendor->id,
            'quotation_file' => $this->pdf(),
            'quotation_amount' => $amount,
        ])->assertSessionHasNoErrors();
    }

    private function anthropic(string $text, string $stop = 'end_turn'): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stop,
            'usage' => ['input_tokens' => 800, 'output_tokens' => 200],
        ];
    }

    // ── Fails open ────────────────────────────────────────────────────────────

    public function test_compare_fails_open_when_ai_is_not_configured(): void
    {
        $batch = $this->cycle();
        $this->fileQuotation($this->itManager(), $batch, $this->vendor('RecycleCo'), 500);

        $this->actingAs($this->itManager())
            ->post(route('ewaste.compare', $batch))
            ->assertRedirect(route('decommission.show', $batch))
            ->assertSessionHas('info');

        $batch->refresh();
        $this->assertSame('disabled', $batch->ai_compare_status);
        $this->assertNull($batch->ai_recommended_quotation_id);
        $this->assertNull($batch->ai_recommendation_note);
        $this->assertNotNull($batch->ai_recommended_at);
    }

    public function test_compare_refuses_when_there_is_nothing_to_compare_yet(): void
    {
        $batch = $this->cycle();

        $this->actingAs($this->itManager())
            ->post(route('ewaste.compare', $batch))
            ->assertRedirect(route('decommission.show', $batch))
            ->assertSessionHas('error');

        $this->assertNull($batch->fresh()->ai_compare_status);
    }

    public function test_only_it_may_trigger_a_comparison(): void
    {
        $batch = $this->cycle();
        $this->fileQuotation($this->itManager(), $batch, $this->vendor('RecycleCo'), 500);

        $this->actingAs(User::factory()->create(['role' => 'finance_manager']))
            ->post(route('ewaste.compare', $batch))
            ->assertForbidden();
    }

    public function test_the_cycle_page_still_works_and_defaults_to_the_mechanical_best_offer_without_ai(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $this->fileQuotation($it, $batch, $this->vendor('LowBall'), 300);
        $this->fileQuotation($it, $batch, $this->vendor('TopDollar'), 900);

        $this->actingAs($it)->get(route('decommission.show', $batch))
            ->assertOk()
            ->assertSee('Ask AI to compare quotations')
            ->assertSee('Defaults to the offer that pays us most', false);
    }

    // ── A live comparison ────────────────────────────────────────────────────

    private function enableAi(): void
    {
        config()->set('claims.ocr.enabled', true);
        config()->set('claims.ocr.provider', 'anthropic');
        config()->set('claims.ocr.api_key', 'test-key');
        config()->set('claims.ocr.model', 'claude-haiku-4-5');
    }

    public function test_a_successful_comparison_prefills_the_recommend_form(): void
    {
        $this->enableAi();
        $it = $this->itManager();
        $batch = $this->cycle();
        $low = $this->vendor('LowBall');
        $high = $this->vendor('TopDollar');
        $this->fileQuotation($it, $batch, $low, 300);
        $this->fileQuotation($it, $batch, $high, 900);

        $winner = $batch->fresh()->quotationsForComparison()->firstWhere('vendor_id', $low->id);

        // Two vendors → two transcription (vision) calls, in ranked order (highest amount
        // first, per quotationsForComparison()) — then one comparison (text) call.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropic(json_encode(['text' => 'TopDollar quotation: RM900, collects within 5 business days.'])))
                ->push($this->anthropic(json_encode(['text' => 'LowBall quotation: RM300, FREE collection within 24 hours, no conditions.'])))
                ->push($this->anthropic(json_encode([
                    'quotation_id' => $winner->id,
                    'reasoning' => 'LowBall offers free next-day collection with no conditions, worth more than the RM600 gap.',
                ]))),
        ]);

        $this->actingAs($it)->post(route('ewaste.compare', $batch))
            ->assertRedirect(route('decommission.show', $batch))
            ->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame('ok', $batch->ai_compare_status);
        $this->assertSame($winner->id, $batch->ai_recommended_quotation_id);
        $this->assertStringContainsString('free next-day collection', $batch->ai_recommendation_note);
        Http::assertSentCount(3);

        // Pre-filled on the page — LowBall selected by default, not the mechanically-highest
        // TopDollar offer, and the note carries the AI's reasoning.
        $this->actingAs($it)->get(route('decommission.show', $batch))
            ->assertOk()
            ->assertSee('AI suggests: LowBall', false)
            // assertSee escapes the expected text by default — the apostrophe renders as
            // &#039; through Blade's {{ }}, so this must NOT pass false here.
            ->assertSee("the AI's suggestion")
            ->assertSee('value="'.$batch->ai_recommendation_note.'"', false);
    }

    public function test_transcripts_are_not_re_read_on_a_second_comparison(): void
    {
        $this->enableAi();
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batch, $vendor, 500);
        $quotation = $batch->fresh()->quotationsForComparison()->first();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropic(json_encode(['text' => 'RecycleCo quotation: RM500.'])))
                ->push($this->anthropic(json_encode(['quotation_id' => $quotation->id, 'reasoning' => 'Only offer.'])))
                // A second run must not re-transcribe — only one more call (the comparison).
                ->push($this->anthropic(json_encode(['quotation_id' => $quotation->id, 'reasoning' => 'Still the only offer.']))),
        ]);

        $this->actingAs($it)->post(route('ewaste.compare', $batch));
        $this->assertSame('ok', $batch->fresh()->ai_compare_status);
        Http::assertSentCount(2);

        $this->actingAs($it)->post(route('ewaste.compare', $batch));
        // 2 (first run) + 1 (second run's comparison only, no re-transcription) = 3.
        Http::assertSentCount(3);
    }

    public function test_a_malformed_ai_reply_fails_open_and_leaves_the_manual_pick_available(): void
    {
        $this->enableAi();
        $it = $this->itManager();
        $batch = $this->cycle();
        $this->fileQuotation($it, $batch, $this->vendor('RecycleCo'), 500);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropic(json_encode(['text' => 'RecycleCo quotation: RM500.'])))
                ->push($this->anthropic('not json at all')),
        ]);

        $this->actingAs($it)->post(route('ewaste.compare', $batch))
            ->assertRedirect(route('decommission.show', $batch))
            ->assertSessionHas('error');

        $batch->refresh();
        $this->assertSame('failed', $batch->ai_compare_status);
        $this->assertNull($batch->ai_recommended_quotation_id);

        // IT can still submit a recommendation by hand — the page is not blocked by the failure.
        $quotation = $batch->quotationsForComparison()->first();
        $this->actingAs($it)->post(route('ewaste.submit', $batch), [
            'recommended_quotation_id' => $quotation->id,
        ])->assertSessionHasNoErrors();
    }

    // ── Summary + amount backfill (added alongside quotation delete) ────────────

    public function test_a_successful_comparison_stores_a_short_summary_without_overwriting_a_recorded_amount(): void
    {
        $this->enableAi();
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');
        $this->fileQuotation($it, $batch, $vendor, 300);
        $quotation = $batch->fresh()->quotationsForComparison()->first();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropic(json_encode([
                    'text' => 'RecycleCo quotation: RM999, collects within 3 business days.',
                    'summary' => 'RecycleCo offers RM999 with free collection within 3 business days.',
                    'amount' => 999,
                ])))
                ->push($this->anthropic(json_encode(['quotation_id' => $quotation->id, 'reasoning' => 'Only offer.']))),
        ]);

        $this->actingAs($it)->post(route('ewaste.compare', $batch))->assertSessionHas('success');

        $quotation->refresh();
        $this->assertSame('RecycleCo offers RM999 with free collection within 3 business days.', $quotation->ai_summary);
        // The figure typed at upload is a human's — a differing AI reading must never
        // silently overwrite it.
        $this->assertEquals(300.00, (float) $quotation->amount);

        $this->actingAs($it)->get(route('decommission.show', $batch))
            ->assertOk()
            ->assertSee('RecycleCo offers RM999 with free collection within 3 business days.');
    }

    public function test_a_successful_comparison_backfills_an_amount_the_upload_time_read_missed(): void
    {
        $it = $this->itManager();
        $batch = $this->cycle();
        $vendor = $this->vendor('RecycleCo');

        // AI is disabled at upload time, so the amount is never read — the same real failure
        // this backfill exists to give a second chance at.
        $this->actingAs($it)->post(route('ewaste.quotation', $batch), [
            'vendor_id' => $vendor->id,
            'quotation_file' => $this->pdf(),
        ])->assertSessionHasNoErrors();

        $quotation = $batch->fresh()->quotationsForComparison()->first();
        $this->assertNull($quotation->amount);

        $this->enableAi();
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropic(json_encode([
                    'text' => 'RecycleCo quotation: RM750, no conditions.',
                    'summary' => 'RecycleCo offers RM750 with no conditions.',
                    'amount' => 750,
                ])))
                ->push($this->anthropic(json_encode(['quotation_id' => $quotation->id, 'reasoning' => 'Only offer.']))),
        ]);

        $this->actingAs($it)->post(route('ewaste.compare', $batch))->assertSessionHas('success');

        $quotation->refresh();
        $this->assertEquals(750.00, (float) $quotation->amount);
        // The cache column the report/mailables read must follow it too.
        $this->assertEquals(750.00, (float) $batch->fresh()->quotation_amount);
    }
}
