<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceTwoFactor;
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
 * Token-usage tracking on the Claude API page: capture at the call site, costing
 * from the editable rate table, the month x feature report, and the PDF export.
 */
class ClaudeApiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnforceTwoFactor::class);
        ClaudeModelRate::flushMemo();
    }

    private function superadmin(): User
    {
        $super = User::factory()->create(['role' => 'superadmin']);
        Employee::factory()->create(['user_id' => $super->id, 'company' => 'Claritas']);

        return $super;
    }

    public function test_seeded_rates_cost_a_call_correctly(): void
    {
        // Haiku 4.5 is seeded at $1 in / $5 out per million tokens.
        // 10,000 in + 2,000 out = (10000*1 + 2000*5) / 1e6 = $0.02
        $this->assertSame(0.02, ClaudeModelRate::costFor('claude-haiku-4-5', 10_000, 2_000));

        // Opus 4.8 at $5 / $25: (1000*5 + 1000*25) / 1e6 = $0.03
        $this->assertSame(0.03, ClaudeModelRate::costFor('claude-opus-4-8', 1_000, 1_000));
    }

    public function test_cache_tokens_use_anthropic_multipliers(): void
    {
        // Haiku input $1/M. 1M cache writes = 1.25x, 1M cache reads = 0.1x.
        $this->assertSame(1.25, ClaudeModelRate::costFor('claude-haiku-4-5', 0, 0, 1_000_000, 0));
        $this->assertSame(0.1, ClaudeModelRate::costFor('claude-haiku-4-5', 0, 0, 0, 1_000_000));
    }

    public function test_unpriced_model_still_logs_tokens_but_costs_zero(): void
    {
        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-future-9', ['input_tokens' => 500, 'output_tokens' => 100]);

        $log = ClaudeApiUsageLog::firstOrFail();
        $this->assertSame(500, $log->input_tokens);
        $this->assertSame(0.0, (float) $log->cost_usd);
    }

    public function test_a_claude_receipt_scan_records_its_token_usage(): void
    {
        $super = $this->superadmin();
        ClaudeApiSetting::current()->update([
            'api_key' => 'sk-ant-test', 'model' => 'claude-haiku-4-5', 'enabled' => true,
        ]);

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
        $this->assertSame('claim_item_verify', $log->feature);       // extract() = reviewer verification
        $this->assertSame('claude-haiku-4-5', $log->model);
        $this->assertSame(1_500, $log->input_tokens);
        $this->assertSame(300, $log->output_tokens);
        $this->assertSame($super->id, $log->user_id);
        // (1500*1 + 300*5) / 1e6 = $0.003
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
        // Simulate the pre-migration / broken-table case: the recorder must swallow it.
        DB::statement('DROP TABLE claude_api_usage_logs');

        ClaudeUsageRecorder::record('claim_receipt_scan', 'claude-haiku-4-5', ['input_tokens' => 10, 'output_tokens' => 5]);

        $this->assertTrue(true); // reaching here without an exception IS the assertion
    }

    public function test_report_groups_by_month_and_feature_with_totals(): void
    {
        $super = $this->superadmin();

        // Two features in the current month, one in a prior month.
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 1_000_000, 'output_tokens' => 0, 'cost_usd' => 1.0]);
        ClaudeApiUsageLog::create(['feature' => 'accounting_invoice_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 500_000, 'output_tokens' => 0, 'cost_usd' => 0.5]);
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 2_000_000, 'output_tokens' => 0, 'cost_usd' => 2.0])
            ->forceFill(['created_at' => now()->subMonths(2)])->save();

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['period' => 12]));
        $res->assertOk();

        $totals = $res->viewData('totals');
        $this->assertSame(3, $totals['calls']);
        $this->assertSame(3_500_000, $totals['tokens']);
        $this->assertSame(3.5, $totals['cost_usd']);

        // Two month buckets; the current one carries both features.
        $report = $res->viewData('report');
        $this->assertCount(2, $report);
        $this->assertCount(2, $report->first()['features']);
        $res->assertSee('eClaim — Receipt / Document Scan', false);
        $res->assertSee('Accounting — Invoice Scan', false);
    }

    public function test_report_also_rolls_spend_up_by_module_and_feature(): void
    {
        $super = $this->superadmin();

        // eClaim spend spread across TWO months and TWO features — the module rollup
        // must sum all of it, which is the whole point of this second grouping.
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 1.0]);
        ClaudeApiUsageLog::create(['feature' => 'claim_item_verify', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 2.0]);
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 1.0])
            ->forceFill(['created_at' => now()->subMonth()])->save();
        ClaudeApiUsageLog::create(['feature' => 'accounting_invoice_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 6.0]);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $byModule = $res->viewData('byModule')->keyBy('module');

        // eClaim = 1 + 2 + 1 = $4 across 3 calls and 2 features; Accounting = $6.
        $this->assertSame(4.0, $byModule['eClaim (Receipt OCR)']['cost_usd']);
        $this->assertSame(3, $byModule['eClaim (Receipt OCR)']['calls']);
        $this->assertCount(2, $byModule['eClaim (Receipt OCR)']['features']);
        $this->assertSame(6.0, $byModule['Accounting (AI)']['cost_usd']);

        // Shares are of the $10 grand total, and the biggest spender sorts first.
        $this->assertSame(40.0, round($byModule['eClaim (Receipt OCR)']['share'], 1));
        $this->assertSame(60.0, round($byModule['Accounting (AI)']['share'], 1));
        $this->assertSame('Accounting (AI)', $res->viewData('byModule')->first()['module']);

        $res->assertSee('Spend by feature', false);
        $res->assertSee('Spend by month', false);
    }

    public function test_period_filter_excludes_older_months(): void
    {
        $super = $this->superadmin();

        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 1.0]);
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 9.0])
            ->forceFill(['created_at' => now()->subMonths(8)])->save();

        // 3-month window keeps only the recent row...
        $this->assertSame(1.0, $this->actingAs($super)
            ->get(route('superadmin.claude-api.index', ['period' => 3]))->viewData('totals')['cost_usd']);

        // ...while "all time" sees both.
        $this->assertSame(10.0, $this->actingAs($super)
            ->get(route('superadmin.claude-api.index', ['period' => 'all']))->viewData('totals')['cost_usd']);
    }

    public function test_a_single_month_can_be_selected_and_excludes_other_months(): void
    {
        $super = $this->superadmin();

        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 3.0]);
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 8.0])
            ->forceFill(['created_at' => now()->subMonth()])->save();

        $thisMonth = now()->format('Y-m');
        $lastMonth = now()->subMonth()->format('Y-m');

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index', ['period' => $lastMonth]));
        $res->assertOk();

        // Only the selected month's spend, and exactly one month bucket.
        $this->assertSame(8.0, $res->viewData('totals')['cost_usd']);
        $this->assertCount(1, $res->viewData('report'));
        $this->assertSame($lastMonth, $res->viewData('report')->first()['ym']);
        $this->assertSame(now()->subMonth()->format('F Y'), $res->viewData('period')['label']);
        $this->assertTrue($res->viewData('period')['isMonth']);

        // Both months with data are offered in the picker.
        $this->assertSame([$thisMonth, $lastMonth], array_keys($res->viewData('availableMonths')));
    }

    public function test_empty_report_explains_why_no_months_are_offered(): void
    {
        $super = $this->superadmin();

        // Nothing recorded: the month picker has nothing to offer, and the page must say
        // so rather than silently dropping the group (which reads as a broken control).
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $res->assertOk();
        $this->assertSame([], $res->viewData('availableMonths'));
        $res->assertSee('Single month', false);
        $res->assertSee('none recorded yet', false);
        // OCR is off in this fixture, so the empty state names that as the blocker.
        $res->assertSee('OCR is switched off above', false);

        // Once usage exists the placeholder gives way to real months.
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 1.0]);
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $res->assertSee(now()->format('F Y'), false);
        $res->assertDontSee('none recorded yet', false);
    }

    public function test_month_export_is_scoped_and_named_after_that_month(): void
    {
        $super = $this->superadmin();

        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 3.0]);
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 8.0])
            ->forceFill(['created_at' => now()->subMonth()])->save();

        $lastMonth = now()->subMonth()->format('Y-m');
        $res = $this->actingAs($super)->get(route('superadmin.claude-api.usage-pdf', ['period' => $lastMonth]));

        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('claude-api-usage-'.$lastMonth.'.pdf', $res->headers->get('content-disposition'));

        // A rolling window is instead named for the window plus the run date.
        $rolling = $this->actingAs($super)->get(route('superadmin.claude-api.usage-pdf', ['period' => 12]));
        $this->assertStringContainsString('last-12-months-'.now()->format('Y-m-d').'.pdf', $rolling->headers->get('content-disposition'));
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

    public function test_myr_column_uses_the_configured_rate(): void
    {
        $super = $this->superadmin();
        ClaudeApiSetting::current()->update(['usd_myr_rate' => 4.5]);
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 100, 'cost_usd' => 2.0]);

        $totals = $this->actingAs($super)->get(route('superadmin.claude-api.index'))->viewData('totals');
        $this->assertSame(9.0, $totals['cost_myr']);
    }

    public function test_superadmin_can_edit_rates_and_new_calls_use_them(): void
    {
        $super = $this->superadmin();

        $this->actingAs($super)->post(route('superadmin.claude-api.rates'), [
            'usd_myr_rate' => 4.25,
            'rates' => [
                ['model' => 'claude-haiku-4-5', 'input_per_mtok' => 2, 'output_per_mtok' => 8],
                ['model' => 'claude-brand-new', 'input_per_mtok' => 7, 'output_per_mtok' => 9],
            ],
        ])->assertRedirect();

        ClaudeModelRate::flushMemo();
        $this->assertSame(4.25, (float) ClaudeApiSetting::current()->usd_myr_rate);
        // (1e6*2 + 0) / 1e6 = $2 under the edited rate (was $1).
        $this->assertSame(2.0, ClaudeModelRate::costFor('claude-haiku-4-5', 1_000_000, 0));
        $this->assertSame(7.0, ClaudeModelRate::costFor('claude-brand-new', 1_000_000, 0));
    }

    public function test_editing_a_rate_does_not_repice_past_usage(): void
    {
        $super = $this->superadmin();
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 1_000_000, 'cost_usd' => 1.0]);

        $this->actingAs($super)->post(route('superadmin.claude-api.rates'), [
            'usd_myr_rate' => 4.7,
            'rates' => [['model' => 'claude-haiku-4-5', 'input_per_mtok' => 50, 'output_per_mtok' => 99]],
        ]);

        // The historical row keeps the cost it was recorded at.
        $this->assertSame(1.0, (float) ClaudeApiUsageLog::firstOrFail()->cost_usd);
        $this->assertSame(1.0, $this->actingAs($super)
            ->get(route('superadmin.claude-api.index'))->viewData('totals')['cost_usd']);
    }

    public function test_unpriced_models_are_flagged_on_the_report(): void
    {
        $super = $this->superadmin();
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-mystery-1', 'input_tokens' => 100, 'cost_usd' => 0]);

        $res = $this->actingAs($super)->get(route('superadmin.claude-api.index'));
        $this->assertContains('claude-mystery-1', $res->viewData('unpricedModels'));
        $res->assertSee('claude-mystery-1', false);
    }

    public function test_pdf_export_downloads_for_superadmin_only(): void
    {
        ClaudeApiUsageLog::create(['feature' => 'claim_receipt_scan', 'model' => 'claude-haiku-4-5', 'input_tokens' => 1_000, 'cost_usd' => 0.001]);

        $res = $this->actingAs($this->superadmin())->get(route('superadmin.claude-api.usage-pdf', ['period' => 12]));
        $res->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        $this->assertStringContainsString('.pdf', $res->headers->get('content-disposition'));

        $hr = User::factory()->create(['role' => 'hr_manager']);
        $this->actingAs($hr)->get(route('superadmin.claude-api.usage-pdf'))->assertForbidden();
    }

    public function test_rate_page_is_superadmin_only(): void
    {
        $hr = User::factory()->create(['role' => 'hr_manager']);
        $this->actingAs($hr)->post(route('superadmin.claude-api.rates'), [
            'usd_myr_rate' => 1, 'rates' => [],
        ])->assertForbidden();
    }
}
