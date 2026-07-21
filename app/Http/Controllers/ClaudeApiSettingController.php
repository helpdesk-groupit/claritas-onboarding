<?php

namespace App\Http\Controllers;

use App\Models\ClaudeApiSetting;
use App\Models\ClaudeApiUsageLog;
use App\Models\ClaudeModelRate;
use App\Services\ClaimReceiptOcrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Superadmin-only "Claude API" settings page (Settings menu). Stores the Anthropic
 * API key + model that powers claim-receipt OCR. When enabled with a valid key,
 * OCR runs through Claude and overrides the env-based CLAIMS_OCR_* config.
 *
 * The key is stored encrypted and never sent back to the browser in full — the
 * view only ever sees a masked hint (sk-ant-…last4). Saving with a blank key keeps
 * the existing one, so re-saving other fields never wipes the stored secret.
 */
class ClaudeApiSettingController extends Controller
{
    private function authorizeSuperadmin(): void
    {
        if (! Auth::user()->isSuperadmin()) {
            abort(403);
        }
    }

    /** Rolling-window period options. A ?period= of YYYY-MM selects one calendar month instead. */
    private const PERIODS = [
        '3' => 'Last 3 months',
        '6' => 'Last 6 months',
        '12' => 'Last 12 months',
        'all' => 'All time',
    ];

    public function index(Request $request)
    {
        $this->authorizeSuperadmin();

        $setting = ClaudeApiSetting::current();
        $period = $this->resolvePeriod($request);

        return view('superadmin.claude-api', array_merge([
            'setting' => $setting,
            'models' => ClaudeApiSetting::MODELS,
            'periods' => self::PERIODS,
            'period' => $period,
            'availableMonths' => $this->availableMonths(),
        ], $this->usageReport($period)));
    }

    /**
     * Resolve ?period= into a concrete date window. Accepts either a rolling window
     * ('3'|'6'|'12'|'all') or a single calendar month as 'YYYY-MM' — the latter is what
     * makes "export just July" possible. Anything unrecognised falls back to 12 months.
     *
     * @return array{key: string, label: string, start: ?Carbon, end: ?Carbon, isMonth: bool}
     */
    private function resolvePeriod(Request $request): array
    {
        $period = (string) $request->query('period', '12');

        // A specific calendar month.
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $start = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

            return [
                'key' => $period,
                'label' => $start->format('F Y'),
                'start' => $start,
                'end' => $start->copy()->endOfMonth(),
                'isMonth' => true,
            ];
        }

        if (! array_key_exists($period, self::PERIODS)) {
            $period = '12';
        }

        return [
            'key' => $period,
            'label' => self::PERIODS[$period],
            // startOfMonth so "last 3 months" means 3 whole calendar months, not 90 days —
            // the report is presented per calendar month, so a partial edge month would
            // show a total that doesn't match its own heading.
            'start' => $period === 'all' ? null : now()->subMonths((int) $period - 1)->startOfMonth(),
            'end' => null,
            'isMonth' => false,
        ];
    }

    /**
     * Every month that actually has usage, newest first, as ['2026-07' => 'July 2026'].
     * Drives the per-month options in the period picker — offering an empty month would
     * just produce a blank report.
     *
     * @return array<string, string>
     */
    private function availableMonths(): array
    {
        return ClaudeApiUsageLog::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym")
            ->distinct()
            ->orderByDesc('ym')
            ->pluck('ym')
            ->mapWithKeys(fn ($ym) => [$ym => Carbon::createFromFormat('Y-m-d', $ym.'-01')->format('F Y')])
            ->all();
    }

    /**
     * The usage report: token totals and USD/MYR spend, grouped by month and then by
     * feature. Aggregated in SQL (one grouped query) rather than by hydrating rows —
     * this table grows by one row per OCR scan and is never paginated on screen.
     *
     * @return array{report: \Illuminate\Support\Collection, byYear: \Illuminate\Support\Collection, chart: \Illuminate\Support\Collection, totals: array, unpricedModels: array}
     */
    private function usageReport(array $period): array
    {
        $query = ClaudeApiUsageLog::query()
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym")
            ->selectRaw('feature')
            ->selectRaw('COUNT(*) as calls')
            ->selectRaw('SUM(input_tokens) as in_tokens')
            ->selectRaw('SUM(output_tokens) as out_tokens')
            ->selectRaw('SUM(cache_creation_input_tokens + cache_read_input_tokens) as cache_tokens')
            ->selectRaw('SUM(input_cost_usd) as in_cost')
            ->selectRaw('SUM(output_cost_usd) as out_cost')
            ->selectRaw('SUM(cost_usd) as cost_usd')
            ->groupBy('ym', 'feature')
            ->orderByDesc('ym')
            ->orderByDesc('cost_usd');

        if ($period['start']) {
            $query->where('created_at', '>=', $period['start']);
        }
        if ($period['end']) {
            $query->where('created_at', '<=', $period['end']);
        }

        $rows = $query->get();
        // MYR is a convenience conversion off config; USD is what Anthropic bills.
        $rate = (float) config('claude.usd_myr_rate', 0);

        // Group into months, each with its feature rows + a month subtotal. Each feature
        // carries the input/output cost split so the accordion can break it down on drop.
        $report = $rows->groupBy('ym')->map(fn ($group, $ym) => [
            'ym' => $ym,
            // Parse with an explicit day-01 — 'Y-m' alone fills the day from today, which
            // overflows into the next month when today is the 29th-31st.
            'label' => Carbon::createFromFormat('Y-m-d', $ym.'-01')->format('F Y'),
            'short' => Carbon::createFromFormat('Y-m-d', $ym.'-01')->format('M'),
            'features' => $group->map(fn ($r) => [
                'feature' => $r->feature,
                'label' => ClaudeApiUsageLog::featureLabel($r->feature),
                'color' => ClaudeApiUsageLog::moduleColor(ClaudeApiUsageLog::moduleLabel($r->feature)),
                'calls' => (int) $r->calls,
                'in_tokens' => (int) $r->in_tokens,
                'out_tokens' => (int) $r->out_tokens,
                'total_tokens' => (int) $r->in_tokens + (int) $r->out_tokens + (int) $r->cache_tokens,
                'in_cost' => (float) $r->in_cost,
                'out_cost' => (float) $r->out_cost,
                'in_cost_myr' => (float) $r->in_cost * $rate,
                'out_cost_myr' => (float) $r->out_cost * $rate,
                'cost_usd' => (float) $r->cost_usd,
                'cost_myr' => (float) $r->cost_usd * $rate,
            ])->values(),
            'calls' => (int) $group->sum('calls'),
            'total_tokens' => (int) $group->sum(fn ($r) => (int) $r->in_tokens + (int) $r->out_tokens + (int) $r->cache_tokens),
            'cost_usd' => (float) $group->sum('cost_usd'),
            'cost_myr' => (float) $group->sum('cost_usd') * $rate,
        ])->values();

        $totalUsd = (float) $report->sum('cost_usd');

        // Wrap the months in years so the report reads year -> month -> feature, the same
        // shape as the claims accordion. report is already newest-month-first, so groupBy
        // yields the newest year first and its months stay in order.
        $byYear = $report->groupBy(fn ($m) => substr($m['ym'], 0, 4))
            ->map(fn ($months, $year) => [
                // (string) — PHP coerces the numeric groupBy key to int; keep it a string
                // so the view's element id ("ucY2026") and label render consistently.
                'year' => (string) $year,
                'calls' => (int) $months->sum('calls'),
                'total_tokens' => (int) $months->sum('total_tokens'),
                'cost_usd' => (float) $months->sum('cost_usd'),
                'cost_myr' => (float) $months->sum('cost_myr'),
                'months' => $months->values(),
            ])->values();

        // Models that logged usage but have no rate in config price at 0 — surface them so
        // the grand total is never quietly understated.
        $unpriced = ClaudeApiUsageLog::query()
            ->select('model')
            ->distinct()
            ->whereNotIn('model', ClaudeModelRate::pricedModels())
            ->pluck('model')
            ->all();

        // Trend series for the column chart: oldest -> newest (time reads left to right,
        // unlike the accordion, which leads with the most recent month). `peak` scales the
        // bars; `isPeak`/`isLatest` mark the only two columns that get a direct label —
        // a number on every column is noise nobody reads.
        $chart = $report->reverse()->values();
        $peak = (float) $chart->max('cost_usd') ?: 0.0;
        $lastYm = $chart->last()['ym'] ?? null;
        $chart = $chart->map(fn ($m) => $m + [
            'height' => $peak > 0 ? max(2.0, ($m['cost_usd'] / $peak) * 100) : 0.0,
            'isPeak' => $peak > 0 && $m['cost_usd'] >= $peak,
            'isLatest' => $m['ym'] === $lastYm,
        ]);

        return [
            'report' => $report,
            'byYear' => $byYear,
            'chart' => $chart,
            'totals' => [
                'calls' => (int) $report->sum('calls'),
                'tokens' => (int) $report->sum('total_tokens'),
                'cost_usd' => $totalUsd,
                'cost_myr' => $totalUsd * $rate,
                'myr_rate' => $rate,
                // What one call costs on average — the number that makes the total
                // meaningful ("is a scan cheap?"), which a bare total never answers.
                'avg_per_call' => $report->sum('calls') > 0 ? $totalUsd / $report->sum('calls') : 0.0,
            ],
            'unpricedModels' => $unpriced,
        ];
    }

    /** The same report as the page, as a downloadable PDF. */
    public function exportUsagePdf(Request $request)
    {
        $this->authorizeSuperadmin();

        $period = $this->resolvePeriod($request);

        $pdf = Pdf::loadView('superadmin.claude-api-usage-pdf', array_merge([
            'periodLabel' => $period['label'],
            'generatedAt' => now(),
            'generatedBy' => Auth::user()?->employee?->full_name ?? Auth::user()?->name,
        ], $this->usageReport($period)))->setPaper('a4');

        // Name the file after what it contains: a single-month export is "…-2026-07.pdf",
        // a rolling window is "…-last-12-months-<today>.pdf" (it depends on when it was run).
        $suffix = $period['isMonth']
            ? $period['key']
            : Str::slug($period['label']).'-'.now()->format('Y-m-d');

        return $pdf->download('claude-api-usage-'.$suffix.'.pdf');
    }

    public function update(Request $request)
    {
        $this->authorizeSuperadmin();

        $data = $request->validate([
            'api_key' => 'nullable|string|max:255',
            'model' => 'required|string|in:'.implode(',', array_keys(ClaudeApiSetting::MODELS)),
            'enabled' => 'nullable|boolean',
        ]);

        $setting = ClaudeApiSetting::current();
        $setting->model = $data['model'];
        $setting->enabled = $request->boolean('enabled');
        // Blank key = keep the existing one (so toggling/changing model never wipes it).
        $newKey = trim((string) ($data['api_key'] ?? ''));
        if ($newKey !== '') {
            $setting->api_key = $newKey;
        }
        $setting->updated_by = Auth::id();
        $setting->save();

        Log::info('Claude API setting updated', [
            'actor_id' => Auth::id(),
            'enabled' => $setting->enabled,
            'model' => $setting->model,
            'has_key' => (bool) $setting->getRawKey(),
        ]);

        $msg = $setting->isActive()
            ? 'Saved. Claude OCR is ACTIVE — receipt scanning now uses '.$setting->modelLabel().'.'
            : (! $setting->getRawKey()
                ? 'Saved. Add an API key to activate receipt OCR.'
                : 'Saved. OCR is switched OFF — turn it on to start scanning receipts.');

        return redirect()->route('superadmin.claude-api.index')->with('success', $msg);
    }

    /**
     * Live "Test key" — validates the key the superadmin typed (or the saved one if the
     * field is left blank) against Anthropic, using the selected model. Returns JSON.
     */
    public function test(Request $request)
    {
        $this->authorizeSuperadmin();

        $data = $request->validate([
            'api_key' => 'nullable|string|max:255',
            'model' => 'required|string|in:'.implode(',', array_keys(ClaudeApiSetting::MODELS)),
        ]);

        $key = trim((string) ($data['api_key'] ?? ''));
        if ($key === '') {
            $key = (string) ClaudeApiSetting::current()->getRawKey();
        }
        if ($key === '') {
            return response()->json(['ok' => false, 'message' => 'Enter an API key first, then test.']);
        }

        return response()->json(ClaimReceiptOcrService::testAnthropicKey($key, $data['model']));
    }
}
