<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
use App\Models\ClaudeApiKeyHistory;
use App\Models\ClaudeApiSetting;
use App\Models\ClaudeApiUsageLog;
use App\Models\ClaudeModelRate;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClaimReceiptOcrService;
use App\Services\ClaudeUsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Token-usage tracking on the Claude API page: capture at the call site, costing from
 * the config price catalogue (split into input/output halves), the year › month ›
 * feature report, and the per-month PDF export.
 */
class ClaudeApiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        // Pin config rates so the numbers below don't drift when the real catalogue is edited.
        config()->set('claude.model_rates', [
            'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input' => 1.00, 'output' => 5.00],
            'claude-sonnet-5' => ['label' => 'Claude Sonnet 5', 'input' => 2.00, 'output' => 10.00],
            'claude-opus-4-8' => ['label' => 'Claude Opus 4.8', 'input' => 5.00, 'output' => 25.00],
        ]);
        config()->set('claude.usd_myr_rate', 4.7);
    }

    private function superadmin(): User
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        Employee::factory()->create(['user_id' => $super->id, 'company' => 'Claritas']);

        return $super;
    }

    /** Create a priced usage row (real split from config) at a chosen age. */
    private function usage(string $feature, string $model, int $in, int $out, int $monthsAgo = 0): ClaudeApiUsageLog
    {
        $row = ClaudeApiUsageLog::create([
            'feature' => $feature, 'model' => $model, 'provider' => 'anthropic',
            'input_tokens' => $in, 'output_tokens' => $out,
            'input_cost_usd' => ClaudeModelRate::inputCostFor($model, $in),
            'output_cost_usd' => ClaudeModelRate::outputCostFor($model, $out),
            'cost_usd' => ClaudeModelRate::costFor($model, $in, $out),
        ]);
        if ($monthsAgo) {
            $row->forceFill(['created_at' => now()->subMonths($monthsAgo)])->save();
        }

        return $row;
    }

    // ── Pricing (config-backed) ──────────────────────────────────────────────

    public function test_config_rates_cost_a_call_correctly(): void
    {
        // Haiku 4.5 at $1 in / $5 out per million: 10,000 in + 2,000 out = $0.02
        $this->assertSame(0.02, ClaudeModelRate::costFor('claude-haiku-4-5', 10_000, 2_000));
        // Opus 4.8 at $5 / $25: 1000 in + 1000 out = $0.03
        $this->assertSame(0.03, ClaudeModelRate::costFor('claude-opus-4-8', 1_000, 1_000));
    }

    public function test_cost_splits_into_input_and_output_halves(): void
    {
        // Haiku: input 10,000 × $1/M = $0.01; output 2,000 × $5/M = $0.01.
        $this->assertSame(0.01, ClaudeModelRate::inputCostFor('claude-haiku-4-5', 10_000));
        $this->assertSame(0.01, ClaudeModelRate::outputCostFor('claude-haiku-4-5', 2_000));
        // The two halves sum to the total.
        $this->assertSame(
            ClaudeModelRate::costFor('claude-haiku-4-5', 10_000, 2_000),
            ClaudeModelRate::inputCostFor('claude-haiku-4-5', 10_000) + ClaudeModelRate::outputCostFor('claude-haiku-4-5', 2_000)
        );
    }

    public function test_cache_tokens_are_priced_on_the_input_side(): void
    {
        // Haiku input $1/M. 1M cache writes = 1.25x, 1M cache reads = 0.1x — both input-side.
        $this->assertSame(1.25, ClaudeModelRate::inputCostFor('claude-haiku-4-5', 0, 1_000_000, 0));
        $this->assertSame(0.1, ClaudeModelRate::inputCostFor('claude-haiku-4-5', 0, 0, 1_000_000));
        // Output cost ignores cache entirely.
        $this->assertSame(0.0, ClaudeModelRate::outputCostFor('claude-haiku-4-5', 0));
    }

    public function test_unpriced_model_costs_zero_on_both_halves(): void
    {
        $this->assertSame(0.0, ClaudeModelRate::inputCostFor('claude-future-9', 5_000));
        $this->assertSame(0.0, ClaudeModelRate::outputCostFor('claude-future-9', 5_000));
        $this->assertSame(0.0, ClaudeModelRate::costFor('claude-future-9', 5_000, 5_000));
    }

    // ── Recording at the call site ───────────────────────────────────────────

    public function test_recorder_stores_the_input_output_split(): void
    {
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 10_000, 'output_tokens' => 2_000]);

        $log = ClaudeApiUsageLog::firstOrFail();
        $this->assertSame(0.01, (float) $log->input_cost_usd);
        $this->assertSame(0.01, (float) $log->output_cost_usd);
        $this->assertSame(0.02, (float) $log->cost_usd);
    }

    public function test_unpriced_model_still_logs_tokens(): void
    {
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-future-9', ['input_tokens' => 500, 'output_tokens' => 100]);

        $log = ClaudeApiUsageLog::firstOrFail();
        $this->assertSame(500, $log->input_tokens);
        $this->assertSame(0.0, (float) $log->cost_usd);
    }

    public function test_a_claude_receipt_scan_records_its_token_usage(): void
    {
        $super = $this->superadmin();
        ClaudeApiSetting::current()->update(['api_key' => 'sk-ant-test', 'model' => 'claude-haiku-4-5', 'enabled' => true]);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['text' => '{"amount": 12.50, "date": "2026-07-01"}']],
            'usage' => ['input_tokens' => 1_500, 'output_tokens' => 300],
        ], 200)]);

        $file = tempnam(sys_get_temp_dir(), 'rcpt').'.jpg';
        file_put_contents($file, 'not-a-real-image');

        $this->actingAs($super);
        ClaimReceiptOcrService::extract($file, 'image/jpeg', 'Claritas');
        @unlink($file);

        $log = ClaudeApiUsageLog::firstOrFail();
        $this->assertSame('claim_item_verify', $log->feature);   // extract() = reviewer verification
        $this->assertSame(1_500, $log->input_tokens);
        $this->assertSame(300, $log->output_tokens);
        // 1500×$1/M = $0.0015 in, 300×$5/M = $0.0015 out, total $0.003.
        $this->assertSame(0.0015, (float) $log->input_cost_usd);
        $this->assertSame(0.0015, (float) $log->output_cost_usd);
        $this->assertSame(0.003, (float) $log->cost_usd);
    }

    public function test_a_failed_call_reporting_no_usage_writes_no_row(): void
    {
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 0, 'output_tokens' => 0]);

        $this->assertSame(0, ClaudeApiUsageLog::count());
    }

    public function test_recording_never_breaks_the_caller(): void
    {
        DB::statement('DROP TABLE claude_api_usage_logs');
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 10, 'output_tokens' => 5]);
        $this->assertTrue(true); // reaching here without an exception IS the assertion
    }

    // ── The year › month › feature report ────────────────────────────────────

    public function test_report_nests_years_then_months_then_features(): void
    {
        $super = $this->superadmin();

        // Two features this month, one last month, one two months back — all in 2026.
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);       // $1 this month
        $this->usage('accounting_invoice_scan', 'claude-haiku-4-5', 500_000, 0);    // $0.50 this month
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 2_000_000, 0, 1);    // $2 last month
        $this->usage('claim_item_verify', 'claude-haiku-4-5', 500_000, 0, 2);       // $0.50 two months back

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $res->assertOk();

        $byYear = $res->viewData('byYear');
        $this->assertCount(1, $byYear);                          // one year: 2026
        $this->assertSame('2026', $byYear->first()['year']);
        $this->assertSame(4.0, $byYear->first()['cost_usd']);    // 1 + 0.5 + 2 + 0.5

        // Three month buckets under the year, newest first.
        $months = $byYear->first()['months'];
        $this->assertCount(3, $months);
        $this->assertSame(now()->format('Y-m'), $months->first()['ym']);
        // Newest month carries both its features.
        $this->assertCount(2, $months->first()['features']);

        $res->assertSee('eClaim — Receipt / Document Scan', false);
        $res->assertSee('Accounting — Invoice Scan', false);
    }

    public function test_report_splits_each_feature_into_input_and_output_cost(): void
    {
        $super = $this->superadmin();

        // Haiku: 10,000 in ($0.01) + 2,000 out ($0.01) = $0.02.
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 10_000, 2_000);

        $feature = $this->actingAs($super)->get(route('superadmin.claude-api.index'))
            ->viewData('byYear')->first()['months']->first()['features']->first();

        $this->assertSame(10_000, $feature['in_tokens']);
        $this->assertSame(2_000, $feature['out_tokens']);
        $this->assertSame(0.01, $feature['in_cost']);
        $this->assertSame(0.01, $feature['out_cost']);
        $this->assertSame(0.02, $feature['cost_usd']);
        // Feature carries its module colour (eClaim → blue slot).
        $this->assertSame('#2a78d6', $feature['color']);
    }

    public function test_multiple_years_are_grouped_separately(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);        // 2026
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 3_000_000, 0, 13);    // ~13 months back → 2025

        $byYear = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['period' => 'all']))
            ->viewData('byYear')->keyBy('year');

        $this->assertTrue($byYear->has(now()->format('Y')));
        $this->assertTrue($byYear->has(now()->subMonths(13)->format('Y')));
    }

    public function test_feature_colour_follows_the_feature_not_its_rank(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 100, 0);        // eClaim, small
        $this->usage('accounting_invoice_scan', 'claude-haiku-4-5', 9_000, 0); // Accounting, larger

        $features = $this->actingAs($super)->get(route('superadmin.claude-api.index'))
            ->viewData('byYear')->first()['months']->first()['features']
            ->keyBy('feature');

        // Colours are fixed per feature's module, regardless of spend ranking.
        $this->assertSame('#2a78d6', $features['claim_receipt_scan']['color']);      // eClaim blue
        $this->assertSame('#008300', $features['accounting_invoice_scan']['color']); // Accounting green
    }

    // ── Totals, MYR, period, months picker ───────────────────────────────────

    public function test_totals_include_avg_per_call_and_myr_from_config(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0); // $1
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0); // $1

        $totals = $this->actingAs($super)->get(route('superadmin.claude-api.index'))->viewData('totals');
        $this->assertSame(2.0, $totals['cost_usd']);
        $this->assertSame(1.0, $totals['avg_per_call']);   // $2 over 2 calls
        $this->assertSame(9.4, $totals['cost_myr']);       // $2 × 4.7 (config)
        $this->assertSame(4.7, $totals['myr_rate']);
    }

    public function test_period_filter_excludes_older_months(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);       // $1 now
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 9_000_000, 0, 8);    // $9 eight months back

        $this->assertSame(1.0, $this->actingAs($super)
            ->get(route('superadmin.claude-api.index', ['period' => 3]))->viewData('totals')['cost_usd']);
        $this->assertSame(10.0, $this->actingAs($super)
            ->get(route('superadmin.claude-api.index', ['period' => 'all']))->viewData('totals')['cost_usd']);
    }

    public function test_a_single_month_can_be_selected(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 3_000_000, 0);       // this month
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 8_000_000, 0, 1);    // last month

        $lastMonth = now()->subMonth()->format('Y-m');
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['period' => $lastMonth]));

        $this->assertSame(8.0, $res->viewData('totals')['cost_usd']);
        $this->assertCount(1, $res->viewData('report'));
        $this->assertTrue($res->viewData('period')['isMonth']);
        $this->assertSame([now()->format('Y-m'), $lastMonth], array_keys($res->viewData('availableMonths')));
    }

    public function test_feature_filter_scopes_the_whole_report(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);       // $1 eClaim
        $this->usage('accounting_invoice_scan', 'claude-haiku-4-5', 4_000_000, 0);  // $4 Accounting

        // Unfiltered: both features, $5 total.
        $all = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $this->assertSame(5.0, $all->viewData('totals')['cost_usd']);
        // Both features are offered in the picker.
        $this->assertSame(['accounting_invoice_scan', 'claim_receipt_scan'], array_keys($all->viewData('availableFeatures')));

        // Filtered to eClaim: only its $1, and every rendered feature is that one.
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['feature' => 'claim_receipt_scan']));
        $this->assertSame('claim_receipt_scan', $res->viewData('feature'));
        $this->assertSame(1.0, $res->viewData('totals')['cost_usd']);
        foreach ($res->viewData('report') as $month) {
            foreach ($month['features'] as $f) {
                $this->assertSame('claim_receipt_scan', $f['feature']);
            }
        }
    }

    public function test_an_invalid_feature_filter_is_ignored(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['feature' => 'not_a_feature']));
        $res->assertOk();
        $this->assertSame('', $res->viewData('feature'));           // fell back to "all"
        $this->assertSame(1.0, $res->viewData('totals')['cost_usd']); // nothing filtered out
    }

    public function test_the_whole_period_export_button_is_gone(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);

        // The top-of-page "Export PDF — <period>" button is removed; download is per-month.
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $res->assertDontSee('Export PDF', false);
        // The month-level download link is still present.
        $res->assertSee('usage.pdf?period='.now()->format('Y-m'), false);
        // ...and the Feature filter label is now on the page.
        $res->assertSee('Feature', false);
    }

    public function test_pdf_export_respects_the_feature_filter(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);
        $this->usage('accounting_invoice_scan', 'claude-haiku-4-5', 4_000_000, 0);

        $ym = now()->format('Y-m');
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.usage-pdf', ['period' => $ym, 'feature' => 'claim_receipt_scan']));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    public function test_an_unrecognised_period_falls_back_to_12_months(): void
    {
        $super = $this->superadmin();
        foreach (['nonsense', '2026-13', '99', ''] as $bad) {
            $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['period' => $bad]));
            $res->assertOk();
            $this->assertSame('Last 12 months', $res->viewData('period')['label'], "period={$bad}");
        }
    }

    public function test_empty_report_explains_why_no_months_are_offered(): void
    {
        $super = $this->superadmin();

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $this->assertSame([], $res->viewData('availableMonths'));
        $res->assertSee('none recorded yet', false);
        $res->assertSee('OCR is switched off above', false); // OCR off in this fixture

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 100, 0);
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $res->assertSee(now()->format('F Y'), false);
        $res->assertDontSee('none recorded yet', false);
    }

    public function test_unpriced_models_are_flagged_on_the_report(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-mystery-1', 100, 0);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $this->assertContains('claude-mystery-1', $res->viewData('unpricedModels'));
        $res->assertSee('claude-mystery-1', false);
    }

    // ── Trend chart ──────────────────────────────────────────────────────────

    public function test_trend_chart_is_hidden_for_a_single_month(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $this->assertCount(1, $res->viewData('chart'));
        $res->assertDontSee('<div class="uc-chart">', false);

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 4_000_000, 0, 1);
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $chart = $res->viewData('chart');
        $this->assertCount(2, $chart);
        $this->assertSame(now()->subMonth()->format('Y-m'), $chart->first()['ym']); // oldest first
        $this->assertSame(100.0, $chart->first()['height']);                         // peak scaled
        $this->assertTrue($chart->last()['isLatest']);
        $res->assertSee('<div class="uc-chart">', false);
    }

    // ── Spend by Key ─────────────────────────────────────────────────────────

    public function test_usage_log_is_stamped_with_the_active_key_history_id(): void
    {
        $history = ClaudeApiKeyHistory::rotate('sk-ant-active-key-9999', 'Active key', null);

        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 100, 'output_tokens' => 20]);

        $log = ClaudeApiUsageLog::firstOrFail();
        $this->assertSame($history->id, $log->claude_api_key_history_id);
    }

    public function test_usage_with_no_key_history_reads_as_before_tracking_began(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0); // usage() never sets the FK

        $byKey = $this->actingAs($super)->get(route('superadmin.claude-api.index'))->viewData('byKey');

        $this->assertCount(1, $byKey);
        $this->assertSame('Before key tracking began', $byKey->first()['label']);
        $this->assertNull($byKey->first()['id']);
    }

    public function test_by_key_splits_spend_across_a_rotation_boundary(): void
    {
        $super = $this->superadmin();

        $keyA = ClaudeApiKeyHistory::rotate('sk-ant-key-a-1111', 'Key A', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]); // $1

        $keyB = ClaudeApiKeyHistory::rotate('sk-ant-key-b-2222', 'Key B', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 2_000_000, 'output_tokens' => 0]); // $2

        $byKey = $this->actingAs($super)->get(route('superadmin.claude-api.index'))->viewData('byKey')->keyBy('id');

        $this->assertCount(2, $byKey);
        $this->assertSame('Key A', $byKey[$keyA->id]['label']);
        $this->assertSame(1.0, $byKey[$keyA->id]['cost_usd']);
        $this->assertFalse($byKey[$keyA->id]['is_current']);
        $this->assertSame('Key B', $byKey[$keyB->id]['label']);
        $this->assertSame(2.0, $byKey[$keyB->id]['cost_usd']);
        $this->assertTrue($byKey[$keyB->id]['is_current']);
    }

    public function test_by_key_respects_period_and_feature_filters(): void
    {
        $super = $this->superadmin();
        $key = ClaudeApiKeyHistory::rotate('sk-ant-filtered-3333', 'Filtered key', null);

        ClaudeApiUsageLog::create([
            'feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'provider' => 'anthropic',
            'input_tokens' => 1_000_000, 'output_tokens' => 0,
            'input_cost_usd' => 1.0, 'output_cost_usd' => 0, 'cost_usd' => 1.0,
            'claude_api_key_history_id' => $key->id,
        ]);
        ClaudeApiUsageLog::create([
            'feature' => 'accounting_invoice_scan', 'model' => 'claude-haiku-4-5', 'provider' => 'anthropic',
            'input_tokens' => 4_000_000, 'output_tokens' => 0,
            'input_cost_usd' => 4.0, 'output_cost_usd' => 0, 'cost_usd' => 4.0,
            'claude_api_key_history_id' => $key->id,
        ]);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['feature' => 'claim_receipt_scan']));
        $byKey = $res->viewData('byKey');

        $this->assertCount(1, $byKey);
        $this->assertSame(1.0, $byKey->first()['cost_usd']); // only the filtered feature's spend
    }

    public function test_spend_by_key_card_is_hidden_with_only_one_bucket(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $this->assertCount(1, $res->viewData('byKey'));
        $res->assertDontSee('Spend by Key', false);
    }

    public function test_pdf_export_includes_spend_by_key(): void
    {
        $super = $this->superadmin();
        ClaudeApiKeyHistory::rotate('sk-ant-pdf-a-4444', 'PDF Key A', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]);
        ClaudeApiKeyHistory::rotate('sk-ant-pdf-b-5555', 'PDF Key B', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.usage-pdf', ['period' => 'all']));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    // ── Downloading a single key's own breakdown ─────────────────────────────

    public function test_key_filter_scopes_the_whole_report_to_that_key(): void
    {
        $super = $this->superadmin();

        $keyA = ClaudeApiKeyHistory::rotate('sk-ant-scope-a-1111', 'Scope Key A', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]); // $1

        ClaudeApiKeyHistory::rotate('sk-ant-scope-b-2222', 'Scope Key B', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 4_000_000, 'output_tokens' => 0]); // $4

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['key' => $keyA->id]));

        $this->assertSame(1.0, $res->viewData('totals')['cost_usd']); // only Key A's spend
        $this->assertSame((string) $keyA->id, $res->viewData('key'));
        // With the report already scoped to one key, byKey collapses to a single row
        // and its own card stays hidden (count() > 1 gate) — nothing to add on top.
        $this->assertCount(1, $res->viewData('byKey'));
    }

    public function test_key_filter_none_selects_usage_from_before_tracking_began(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0); // no history id

        ClaudeApiKeyHistory::rotate('sk-ant-tracked-3333', 'Tracked key', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 5_000_000, 'output_tokens' => 0]); // $5

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['key' => 'none']));

        $this->assertSame(1.0, $res->viewData('totals')['cost_usd']);
        $this->assertSame('none', $res->viewData('key'));
    }

    public function test_an_invalid_key_filter_is_ignored(): void
    {
        $super = $this->superadmin();
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000_000, 0);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['key' => '999999']));
        $res->assertOk();
        $this->assertSame('', $res->viewData('key'));            // fell back to "all keys"
        $this->assertSame(1.0, $res->viewData('totals')['cost_usd']); // nothing filtered out
    }

    public function test_pdf_filename_and_header_reflect_the_key_filter(): void
    {
        $super = $this->superadmin();
        $key = ClaudeApiKeyHistory::rotate('sk-ant-named-4444', 'Reimbursement key', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.usage-pdf', ['period' => 'all', 'key' => $key->id]));

        $res->assertOk();
        $this->assertStringContainsString('-key-reimbursement-key.pdf', $res->headers->get('content-disposition'));
    }

    public function test_key_history_card_offers_a_lifetime_per_key_download_link(): void
    {
        $super = $this->superadmin();
        $keyA = ClaudeApiKeyHistory::rotate('sk-ant-lifetime-5555', 'Lifetime Key', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        // period=all so the Key History link is a true lifetime export, independent
        // of whatever period the Usage & Cost section happens to be showing.
        $res->assertSee('usage.pdf?period=all&amp;key='.$keyA->id, false);
    }

    public function test_spend_by_key_card_offers_a_per_key_download_link(): void
    {
        $super = $this->superadmin();
        $keyA = ClaudeApiKeyHistory::rotate('sk-ant-perkey-6666', 'Per Key A', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]);
        $keyB = ClaudeApiKeyHistory::rotate('sk-ant-perkey-7777', 'Per Key B', null);
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 1_000_000, 'output_tokens' => 0]);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $res->assertSee('key='.$keyA->id, false);
        $res->assertSee('key='.$keyB->id, false);
    }

    // ── Key history migration backfill ──────────────────────────────────────

    public function test_migration_backfills_one_history_row_from_the_existing_singleton(): void
    {
        // Seed the pre-migration shape: a settings row with a key, and some usage
        // logs already written under it (created before the FK column existed).
        $super = User::factory()->create(['role' => 'superadmin']);
        $setting = ClaudeApiSetting::current();
        $setting->update(['api_key' => 'sk-ant-legacy-key-6666', 'model' => 'claude-haiku-4-5', 'enabled' => true, 'updated_by' => $super->id]);

        // The FK column + backfill already ran once via RefreshDatabase's migration
        // pass (with no settings row yet, so it was a no-op). Clear any history rows
        // that produced and any usage rows, then invoke the backfill fresh against
        // this seeded state.
        ClaudeApiKeyHistory::query()->delete();
        DB::table('claude_api_usage_logs')->delete();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 100, 0);
        $this->usage('claim_item_verify', 'claude-haiku-4-5', 200, 0);

        $migration = require database_path('migrations/2026_08_17_120000_create_claude_api_key_histories_table.php');

        $method = new \ReflectionMethod($migration, 'backfillFromExistingSetting');
        $method->setAccessible(true);
        $method->invoke($migration);

        $this->assertSame(1, ClaudeApiKeyHistory::count());
        $history = ClaudeApiKeyHistory::first();
        $this->assertSame('sk-ant-…6666', $history->masked_key);
        $this->assertNull($history->label);
        $this->assertSame($super->id, $history->set_by);
        $this->assertNull($history->ended_at);

        $this->assertSame(2, ClaudeApiUsageLog::where('claude_api_key_history_id', $history->id)->count());
    }

    // ── PDF export ───────────────────────────────────────────────────────────

    public function test_month_export_is_scoped_and_named_after_that_month(): void
    {
        $super = $this->superadmin();

        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 3_000_000, 0);
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 8_000_000, 0, 1);

        $lastMonth = now()->subMonth()->format('Y-m');
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.usage-pdf', ['period' => $lastMonth]));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('claude-api-usage-'.$lastMonth.'.pdf', $res->headers->get('content-disposition'));

        $rolling = $this->actingAs($super)->get(route('superadmin.claude-api.usage-pdf', ['period' => 12]));
        $this->assertStringContainsString('last-12-months-'.now()->format('Y-m-d').'.pdf', $rolling->headers->get('content-disposition'));
    }

    public function test_pdf_export_downloads_for_superadmin_only(): void
    {
        $this->usage('claim_receipt_scan', 'claude-haiku-4-5', 1_000, 100);

        $res = $this->actingAs($this->superadmin())->get(route('superadmin.claude-api.usage-pdf', ['period' => 12]));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('.pdf', $res->headers->get('content-disposition'));

        $hr = User::factory()->create(['role' => 'hr_manager']);
        $this->actingAs($hr)->get(route('superadmin.claude-api.usage-pdf'))->assertForbidden();
    }

    // ── The Pricing card is gone ─────────────────────────────────────────────

    public function test_pricing_card_and_rate_route_are_removed(): void
    {
        $super = $this->superadmin();

        // The page no longer renders an editable pricing form.
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $res->assertOk();
        $res->assertDontSee('Save pricing', false);

        // ...and the rate-editing route no longer exists.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('superadmin.claude-api.rates'));
    }
}
