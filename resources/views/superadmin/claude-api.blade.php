@extends('layouts.app')
@section('title', 'Claude API')
@section('page-title', 'Claude API')

@section('content')
<div class="container-fluid" style="max-width:960px;">

    <div class="d-flex align-items-center gap-3 mb-3">
        <div class="ca-hero"><i class="bi bi-robot"></i></div>
        <div>
            <h5 class="mb-0 fw-bold">Claude API</h5>
            <div class="text-muted small">The Anthropic API key that powers <strong>receipt scanning (OCR)</strong> on the claim forms. Superadmin only.</div>
        </div>
    </div>

    {{-- Current status --}}
    @php $active = $setting->isActive(); $hasKey = (bool) $setting->getRawKey(); @endphp
    <div class="alert d-flex align-items-center gap-2 py-2 {{ $active ? 'alert-success' : ($hasKey ? 'alert-warning' : 'alert-secondary') }}">
        <i class="bi {{ $active ? 'bi-check-circle-fill' : ($hasKey ? 'bi-pause-circle-fill' : 'bi-slash-circle') }}"></i>
        <div class="small">
            @if($active)
                <strong>OCR is active</strong> — receipts are scanned with <strong>{{ $setting->modelLabel() }}</strong>.
            @elseif($hasKey)
                <strong>A key is saved, but OCR is switched off.</strong> Turn it on below to start scanning.
            @else
                <strong>OCR is inactive.</strong> Enter an API key below and switch it on to activate receipt scanning.
            @endif
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="{{ route('superadmin.claude-api.update') }}" id="caForm">
                @csrf

                {{-- Enable toggle --}}
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" role="switch" id="caEnabled" name="enabled" value="1" @checked($setting->enabled)>
                    <label class="form-check-label fw-semibold" for="caEnabled">Enable receipt OCR (scan receipts with Claude)</label>
                    <div class="form-text">When off, the Scan button is hidden and users type receipt details manually. Nothing else is affected.</div>
                </div>

                {{-- API key --}}
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-1">Anthropic API Key</label>
                    <div class="d-flex gap-2 flex-wrap">
                        <input type="password" name="api_key" id="caKey" class="form-control" autocomplete="off"
                               style="min-width:280px;flex:1;"
                               placeholder="{{ $hasKey ? 'Key saved ('.$setting->maskedKey().') — leave blank to keep it' : 'sk-ant-…' }}">
                        <button type="button" class="btn btn-outline-primary" id="caTest"><i class="bi bi-lightning-charge me-1"></i>Test key</button>
                    </div>
                    <div class="form-text">
                        Create a key at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>
                        (the Anthropic API bills separately from any Claude Pro / ChatGPT subscription — add a little credit).
                        The key is stored <strong>encrypted</strong> and never shown again in full.
                    </div>
                    <div id="caTestResult" class="small mt-2 d-none py-2 px-3 rounded"></div>
                </div>

                {{-- Model --}}
                <div class="mb-4">
                    <label class="form-label fw-semibold mb-1">Model</label>
                    <select name="model" id="caModel" class="form-select" style="max-width:420px;">
                        @foreach($models as $id => $label)
                            <option value="{{ $id }}" @selected($setting->model === $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Haiku is the cheapest and handles receipts well; pick a stronger model only if you need more accuracy on messy receipts.</div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>

    <div class="text-muted small mt-3">
        <i class="bi bi-info-circle me-1"></i>This overrides any provider set in the server's <code>.env</code>. Cost is roughly a fraction of a cent per receipt on Haiku.
    </div>

    {{-- ─────────── Usage & Cost ─────────── --}}
    @php
        $fmtTok = fn ($n) => number_format((int) $n);
        // Sub-dollar spend is the normal case here (a Haiku scan is ~$0.004), so 4dp
        // below $1 — 2dp would render most real rows as "$0.00". Above $1, cents.
        $fmtUsd = fn ($n) => '$'.number_format((float) $n, (float) $n >= 1 ? 2 : 4);
        $fmtMyr = fn ($n) => 'RM'.number_format((float) $n, 2);
        // Compact form for the stat tiles only; tables keep full precision.
        $fmtCompact = function ($n) {
            $n = (int) $n;
            if ($n >= 1_000_000) return number_format($n / 1_000_000, 1).'M';
            if ($n >= 10_000) return number_format($n / 1_000, 1).'K';
            return number_format($n);
        };
    @endphp

    <div class="d-flex align-items-center gap-3 mt-5 mb-3">
        <div class="ca-hero" style="background:linear-gradient(135deg,#0891b2,#0e7490);"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="flex-grow-1">
            <h5 class="mb-0 fw-bold">Usage &amp; Cost</h5>
            <div class="text-muted small">Tokens spent on Claude, broken down by month and by the feature that used them.</div>
        </div>
    </div>

    {{-- Period filter + export --}}
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <form method="GET" action="{{ route('superadmin.claude-api.index') }}" class="d-flex align-items-center gap-2" id="caPeriodForm">
            <label class="form-label mb-0 small text-muted">Period</label>
            <select name="period" class="form-select form-select-sm" style="width:auto;" id="caPeriod">
                <optgroup label="Rolling">
                    @foreach($periods as $value => $label)
                        <option value="{{ $value }}" @selected($period['key'] === (string) $value)>{{ $label }}</option>
                    @endforeach
                </optgroup>
                {{-- Months are data-driven: only ones with recorded usage are offered, since
                     picking an empty month would just render a blank report. Say so explicitly
                     when there are none — a silently missing group reads as a broken control. --}}
                <optgroup label="Single month">
                    @forelse($availableMonths as $ym => $label)
                        <option value="{{ $ym }}" @selected($period['key'] === $ym)>{{ $label }}</option>
                    @empty
                        <option disabled>— none recorded yet —</option>
                    @endforelse
                </optgroup>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
        </form>
        <div class="ms-auto">
            <a href="{{ route('superadmin.claude-api.usage-pdf', ['period' => $period['key']]) }}" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-file-earmark-pdf me-1"></i>Export PDF<span class="d-none d-sm-inline"> — {{ $period['label'] }}</span>
            </a>
        </div>
    </div>

    {{-- Summary — one hero figure (total spend) with supporting stats beside it.
         Four identical tiles gave equal weight to four unequal numbers; the question
         this page exists to answer is "what are we spending", so that number leads. --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-5">
                    <div class="uc-lbl">Total spent · {{ $period['label'] }}</div>
                    <div class="uc-hero">{{ $fmtUsd($totals['cost_usd']) }}</div>
                    <div class="uc-sub">
                        ≈ {{ $fmtMyr($totals['cost_myr']) }}
                        <span class="uc-dim">at {{ number_format($totals['myr_rate'], 4) }} / USD</span>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="row g-0 uc-stats">
                        <div class="col-4">
                            <div class="uc-lbl">Calls</div>
                            <div class="uc-stat">{{ $fmtCompact($totals['calls']) }}</div>
                        </div>
                        <div class="col-4">
                            <div class="uc-lbl">Tokens</div>
                            <div class="uc-stat">{{ $fmtCompact($totals['tokens']) }}</div>
                        </div>
                        <div class="col-4">
                            <div class="uc-lbl">Avg / call</div>
                            <div class="uc-stat">{{ $fmtUsd($totals['avg_per_call']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($unpricedModels))
        <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>Some usage isn't priced.</strong> These models have logged calls but no rate on file, so they count as
            <strong>$0.00</strong> above:
            @foreach($unpricedModels as $m)<code>{{ $m }}</code>@if(!$loop->last), @endif @endforeach.
            Add them in the pricing table below to make the totals complete.
        </div>
    @endif

    {{-- Trend — a single series, so one hue and no legend; the heading names it.
         Rendered only from two months on: one column is a one-bar bar chart, and the
         hero figure above already is that number. --}}
    @if($chart->count() >= 2)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="uc-lbl mb-1">Monthly spend (USD)</div>
                <div class="uc-chart">
                    @foreach($chart as $c)
                        <div class="uc-col">
                            <div class="uc-tip">{{ $c['label'] }} · {{ $fmtUsd($c['cost_usd']) }} · {{ $fmtTok($c['calls']) }} calls</div>
                            {{-- Label only the peak and the latest month; a value on every
                                 column is noise the reader skips. The rest are in the table. --}}
                            <div class="uc-track">
                                {{-- The wrapper carries the bar's height so the value label can
                                     anchor to the TOP OF THE BAR. Anchored to the column instead,
                                     a full-height (peak) bar would render underneath its own label. --}}
                                <div class="uc-barwrap" style="height:{{ number_format($c['height'], 2, '.', '') }}%;">
                                    @if($c['isPeak'] || $c['isLatest'])
                                        <div class="uc-colval">{{ $fmtUsd($c['cost_usd']) }}</div>
                                    @endif
                                    <div class="uc-bar"></div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="uc-axis">
                    @foreach($chart as $c)
                        <div class="uc-tick">{{ $c['short'] }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Breakdown 1: by feature (what does eClaim OCR cost us?) --}}
    <div class="uc-head"><i class="bi bi-pie-chart me-1"></i>Spend by feature</div>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            @if($byModule->isNotEmpty())
                {{-- Part-to-whole: one stacked bar, 2px surface gaps doing the separating
                     (never a border). Segments are unlabelled — the legend below carries
                     identity, so a thin segment never has to clip its own text. --}}
                <div class="uc-stack mb-3">
                    @foreach($byModule as $mod)
                        <div class="uc-seg" style="width:{{ number_format(max(0.5, $mod['share']), 2, '.', '') }}%; background:{{ $mod['color'] }};"
                             title="{{ $mod['module'] }} — {{ number_format($mod['share'], 1) }}%"></div>
                    @endforeach
                </div>
            @endif

            @forelse($byModule as $mod)
                <div class="uc-mod {{ $loop->first ? '' : 'mt-3 pt-3 border-top' }}">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="uc-key" style="background:{{ $mod['color'] }};"></span>
                        <span class="fw-semibold">{{ $mod['module'] }}</span>
                        <span class="uc-pct">{{ number_format($mod['share'], 1) }}%</span>
                        <span class="ms-auto uc-dim small">{{ $fmtTok($mod['calls']) }} calls · {{ $fmtTok($mod['total_tokens']) }} tokens</span>
                        <span class="uc-amt">{{ $fmtUsd($mod['cost_usd']) }}</span>
                        <span class="uc-dim small">({{ $fmtMyr($mod['cost_myr']) }})</span>
                    </div>
                    <table class="table table-sm uc-tbl mb-0 mt-2">
                        <tbody>
                        @foreach($mod['features'] as $f)
                            <tr>
                                <td class="uc-feat">{{ $f['label'] }}</td>
                                <td class="text-end uc-dim">{{ $fmtTok($f['calls']) }} calls</td>
                                <td class="text-end uc-dim">{{ $fmtTok($f['in_tokens']) }} in</td>
                                <td class="text-end uc-dim">{{ $fmtTok($f['out_tokens']) }} out</td>
                                <td class="text-end">{{ $fmtTok($f['total_tokens']) }} total</td>
                                <td class="text-end fw-semibold">{{ $fmtUsd($f['cost_usd']) }}</td>
                                <td class="text-end uc-dim">{{ $fmtMyr($f['cost_myr']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <div class="text-center text-muted py-5">
                    <i class="bi bi-inbox fs-3 d-block mb-2 uc-dim"></i>
                    <div class="small">
                        No Claude usage recorded for this period.
                        @if(!$active)
                            {{-- Name the actual blocker: with OCR off, nothing will ever be recorded. --}}
                            <br><span class="text-warning-emphasis">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i>OCR is switched off above, so no calls are being made yet.
                            </span>
                        @else
                            <br>Scans run through Claude are recorded here automatically — the first one will appear within seconds.
                        @endif
                    </div>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Breakdown 2: by month (what did July cost?) --}}
    <div class="uc-head"><i class="bi bi-calendar3 me-1"></i>Spend by month</div>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            @forelse($report as $month)
                <div class="uc-mod {{ $loop->first ? '' : 'mt-3 pt-3 border-top' }}">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <span class="fw-semibold">{{ $month['label'] }}</span>
                        <span class="ms-auto uc-dim small">{{ $fmtTok($month['calls']) }} calls · {{ $fmtTok($month['total_tokens']) }} tokens</span>
                        <span class="uc-amt">{{ $fmtUsd($month['cost_usd']) }}</span>
                        <span class="uc-dim small">({{ $fmtMyr($month['cost_myr']) }})</span>
                        {{-- Export just this month, without changing the period filter first. --}}
                        <a href="{{ route('superadmin.claude-api.usage-pdf', ['period' => $month['ym']]) }}"
                           class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
                           title="Export {{ $month['label'] }} as PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                    </div>
                    <table class="table table-sm uc-tbl mb-0 mt-2">
                        <tbody>
                        @foreach($month['features'] as $f)
                            <tr>
                                <td class="uc-feat">
                                    <span class="uc-key" style="background:{{ \App\Models\ClaudeApiUsageLog::moduleColor(\App\Models\ClaudeApiUsageLog::moduleLabel($f['feature'])) }};"></span>
                                    {{ $f['label'] }}
                                </td>
                                <td class="text-end uc-dim">{{ $fmtTok($f['calls']) }} calls</td>
                                <td class="text-end uc-dim">{{ $fmtTok($f['in_tokens']) }} in</td>
                                <td class="text-end uc-dim">{{ $fmtTok($f['out_tokens']) }} out</td>
                                <td class="text-end">{{ $fmtTok($f['total_tokens']) }} total</td>
                                <td class="text-end fw-semibold">{{ $fmtUsd($f['cost_usd']) }}</td>
                                <td class="text-end uc-dim">{{ $fmtMyr($f['cost_myr']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                {{-- Terse here: the by-feature card above already explains the empty state. --}}
                <div class="text-center text-muted small py-4">Nothing recorded for this period.</div>
            @endforelse
        </div>
    </div>
    {{-- ─────────── Pricing ─────────── --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h6 class="fw-bold mb-1">Pricing</h6>
            <div class="text-muted small mb-3">
                Anthropic's rates in <strong>USD per million tokens</strong>. Each call is costed when it happens,
                so changing a rate affects future calls only — past months keep what they actually cost.
                <strong>You only need to touch this when Anthropic changes its prices</strong> —
                check them at <a href="https://platform.claude.com/docs/en/pricing" target="_blank" rel="noopener">the pricing page</a>.
            </div>

            {{-- The Input/Output split is not self-evident to anyone who hasn't used the API. --}}
            <div class="bg-light rounded p-3 mb-3 small">
                <div class="mb-1"><i class="bi bi-question-circle me-1"></i><strong>What are Input and Output?</strong></div>
                <div class="text-muted">
                    A call is billed in two halves, at different prices.
                    <strong>Input</strong> is everything sent to Claude — the instructions plus the receipt image itself
                    (an image is the bulk of it). <strong>Output</strong> is what Claude writes back — for a receipt scan,
                    just a short line of data like the amount and date.
                    Output costs more per token, but far more is sent than comes back, so input usually dominates.
                    A typical receipt scan on Haiku is roughly 2,000 input + 400 output tokens ≈ <strong>$0.004</strong>.
                </div>
            </div>

            <form method="POST" action="{{ route('superadmin.claude-api.rates') }}">
                @csrf
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr class="small text-muted">
                                <th>Model</th><th style="width:22%;">Input $/M</th><th style="width:22%;">Output $/M</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($rates as $i => $rate)
                            <tr>
                                <td>
                                    <input type="hidden" name="rates[{{ $i }}][model]" value="{{ $rate->model }}">
                                    <input type="hidden" name="rates[{{ $i }}][label]" value="{{ $rate->label }}">
                                    <div class="fw-semibold small">{{ $rate->model }}</div>
                                    @if($rate->label)<div class="text-muted" style="font-size:.72rem;">{{ $rate->label }}</div>@endif
                                </td>
                                <td><input type="number" step="0.0001" min="0" class="form-control form-control-sm"
                                           name="rates[{{ $i }}][input_per_mtok]" value="{{ (float) $rate->input_per_mtok }}"></td>
                                <td><input type="number" step="0.0001" min="0" class="form-control form-control-sm"
                                           name="rates[{{ $i }}][output_per_mtok]" value="{{ (float) $rate->output_per_mtok }}"></td>
                            </tr>
                        @endforeach
                        {{-- One blank row so a newly-used model can be priced without a deploy. --}}
                        <tr>
                            <td><input type="text" class="form-control form-control-sm" name="rates[new][model]"
                                       placeholder="add a model id, e.g. claude-haiku-4-5"></td>
                            <td><input type="number" step="0.0001" min="0" class="form-control form-control-sm" name="rates[new][input_per_mtok]" placeholder="0.0000"></td>
                            <td><input type="number" step="0.0001" min="0" class="form-control form-control-sm" name="rates[new][output_per_mtok]" placeholder="0.0000"></td>
                        </tr>
                        </tbody>
                    </table>
                </div>

                <div class="row align-items-end g-3 mt-1">
                    <div class="col-sm-5">
                        <label class="form-label fw-semibold mb-1 small">USD → MYR rate</label>
                        <input type="number" step="0.0001" min="0" class="form-control form-control-sm"
                               name="usd_myr_rate" value="{{ (float) $setting->usd_myr_rate }}">
                        <div class="form-text" style="font-size:.72rem;">Used only for the approximate MYR column. USD is what Anthropic bills.</div>
                    </div>
                    <div class="col-sm-7 text-end">
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Save pricing</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .ca-hero { width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,#7c3aed,#4f46e5); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; box-shadow:0 4px 10px rgba(79,70,229,.3); }

    /* ── Usage & Cost ───────────────────────────────────────────────────────────
       Ink and chrome tokens are fixed values rather than Bootstrap utilities so the
       chart marks, the table text and the section headers stay on one scale.
       Categorical hues live in PHP (ClaudeApiUsageLog::MODULE_COLORS) because colour
       must follow the module, not its rank in a sorted list. */
    .uc-lbl   { font-size:.7rem; letter-spacing:.06em; text-transform:uppercase; color:#898781; font-weight:600; }
    .uc-dim   { color:#898781; }
    .uc-head  { font-size:.72rem; letter-spacing:.06em; text-transform:uppercase; color:#898781; font-weight:600; margin-bottom:.5rem; }

    /* Hero figure — the one number the page leads with. System sans, never a display face. */
    .uc-hero  { font-size:3rem; line-height:1.05; font-weight:600; color:#0b0b0b; letter-spacing:-.02em; margin-top:.15rem; }
    .uc-sub   { color:#52514e; font-size:.9rem; margin-top:.3rem; }
    .uc-stat  { font-size:1.5rem; font-weight:600; color:#0b0b0b; line-height:1.15; margin-top:.15rem; }
    .uc-stats > [class^="col"] { padding-left:1.1rem; border-left:1px solid #e1e0d9; }
    .uc-stats > [class^="col"]:first-child { border-left:0; padding-left:0; }
    @media (max-width: 991.98px) { .uc-stats > [class^="col"]:first-child { padding-left:1.1rem; border-left:1px solid #e1e0d9; } }

    /* Column chart: single hue, thin marks, 4px rounded data-end square at the baseline. */
    /* padding-top reserves room for the value label that sits above the tallest bar. */
    .uc-chart { display:flex; align-items:flex-end; gap:10px; height:150px; padding-top:26px; }
    .uc-col   { flex:1 1 0; height:100%; display:flex; flex-direction:column; justify-content:flex-end;
                align-items:center; position:relative; min-width:0; }
    .uc-track { width:100%; max-width:24px; height:100%; display:flex; align-items:flex-end; }
    .uc-barwrap { position:relative; width:100%; display:flex; align-items:flex-end; }
    .uc-bar   { width:100%; height:100%; background:#2a78d6; border-radius:4px 4px 0 0; transition:filter .12s ease; }
    .uc-col:hover .uc-bar { filter:brightness(1.12); }
    /* Anchored to the bar's top edge, not the column's, so it never sits over the mark. */
    .uc-colval{ position:absolute; bottom:100%; left:50%; transform:translateX(-50%); margin-bottom:4px;
                font-size:.72rem; font-weight:600; color:#52514e; white-space:nowrap; }
    /* Axis is a solid hairline one step off the surface — never dashed. */
    .uc-axis  { display:flex; gap:10px; border-top:1px solid #c3c2b7; padding-top:6px; }
    .uc-tick  { flex:1 1 0; min-width:0; text-align:center; font-size:.72rem; color:#898781; font-variant-numeric:tabular-nums; }
    /* Per-mark hover tooltip — an HTML chart should be interactive by default. */
    .uc-tip   { position:absolute; bottom:100%; margin-bottom:8px; background:#0b0b0b; color:#fff;
                font-size:.72rem; padding:4px 9px; border-radius:6px; white-space:nowrap;
                opacity:0; pointer-events:none; transition:opacity .12s ease; z-index:5; }
    .uc-col:hover .uc-tip { opacity:1; }

    /* Part-to-whole bar. The 2px flex gap is the separator — never a border on a mark. */
    .uc-stack { display:flex; gap:2px; height:10px; border-radius:5px; overflow:hidden; background:#f0efec; }
    .uc-seg   { height:100%; }
    .uc-key   { width:10px; height:10px; border-radius:3px; display:inline-block; flex-shrink:0; }
    .uc-pct   { font-size:.72rem; font-weight:600; color:#52514e; background:#f0efec; border-radius:10px; padding:.05rem .45rem; }
    .uc-amt   { font-weight:600; color:#0b0b0b; font-variant-numeric:tabular-nums; }

    /* Feature tables: values in ink tokens, identity from the coloured dot beside them. */
    .uc-tbl td { border:0; padding:.3rem .5rem; font-size:.82rem; font-variant-numeric:tabular-nums; }
    .uc-tbl tr + tr td { border-top:1px solid #f0efec; }
    .uc-tbl td:first-child { padding-left:0; }
    .uc-tbl td:last-child  { padding-right:0; }
    .uc-feat  { width:34%; font-variant-numeric:normal; color:#52514e; }
    .uc-feat .uc-key { margin-right:.4rem; vertical-align:baseline; }
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    const testBtn = document.getElementById('caTest');
    const keyEl   = document.getElementById('caKey');
    const modelEl = document.getElementById('caModel');
    const resultEl = document.getElementById('caTestResult');
    const token   = document.querySelector('#caForm input[name="_token"]').value;

    function showResult(ok, message) {
        resultEl.className = 'small mt-2 py-2 px-3 rounded ' + (ok
            ? 'bg-success-subtle text-success-emphasis'
            : 'bg-danger-subtle text-danger-emphasis');
        resultEl.innerHTML = '<i class="bi ' + (ok ? 'bi-check-circle-fill' : 'bi-x-circle-fill') + ' me-1"></i>' + message;
    }

    // Period selector auto-submits (CSP blocks inline onchange — must be addEventListener).
    const periodEl = document.getElementById('caPeriod');
    if (periodEl) {
        periodEl.addEventListener('change', function () {
            document.getElementById('caPeriodForm').submit();
        });
    }

    testBtn.addEventListener('click', function () {
        const original = testBtn.innerHTML;
        testBtn.disabled = true;
        testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
        resultEl.classList.add('d-none');

        const fd = new FormData();
        fd.append('_token', token);
        fd.append('api_key', keyEl.value);      // blank -> server tests the saved key
        fd.append('model', modelEl.value);

        fetch('{{ route('superadmin.claude-api.test') }}', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        .then(r => r.json())
        .then(d => {
            resultEl.classList.remove('d-none');
            showResult(!!d.ok, (d.message || 'Unknown response') + '');
        })
        .catch(() => {
            resultEl.classList.remove('d-none');
            showResult(false, 'Test request failed — please try again.');
        })
        .finally(() => {
            testBtn.disabled = false;
            testBtn.innerHTML = original;
        });
    });
})();
</script>
@endpush
