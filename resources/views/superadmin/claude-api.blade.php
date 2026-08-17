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
                    <input type="text" name="key_label" id="caKeyLabel" class="form-control mt-2" maxlength="190"
                           placeholder="Label (optional) — e.g. Finance team — John's org"
                           value="{{ old('key_label', optional($currentKeyHistory)->label) }}">
                    <div class="form-text">
                        Create a key at <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a>
                        (the Anthropic API bills separately from any Claude Pro / ChatGPT subscription — add a little credit).
                        The key is stored <strong>encrypted</strong> and never shown again in full.
                        Anthropic has no way to look up whose account a key belongs to, so the label above is
                        yours to set — it's how the Usage &amp; Cost report below can tell keys apart.
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

    @php
        $fmtTok = fn ($n) => number_format((int) $n);
        // Sub-dollar spend is the normal case here (a Haiku scan is ~$0.004), so 4dp
        // below $1 — 2dp would render most real rows as "$0.00". Above $1, cents.
        $fmtUsd = fn ($n) => '$'.number_format((float) $n, (float) $n >= 1 ? 2 : 4);
        $fmtMyr = fn ($n) => 'RM'.number_format((float) $n, 2);
        // Sub-RM1 spend is normal here, so keep 4dp below RM1 (a per-call figure would
        // otherwise round to RM0.00); above RM1, sen. Used for precise per-call/per-half figures.
        $fmtMyrP = fn ($n) => 'RM'.number_format((float) $n, (float) $n >= 1 ? 2 : 4);
        // Compact form for the stat tiles only; tables keep full precision.
        $fmtCompact = function ($n) {
            $n = (int) $n;
            if ($n >= 1_000_000) return number_format($n / 1_000_000, 1).'M';
            if ($n >= 10_000) return number_format($n / 1_000, 1).'K';
            return number_format($n);
        };
    @endphp

    {{-- Key History — every key ever set, unfiltered by period (unlike the Spend by
         Key card below, which is scoped to the report's period/feature filters). This
         is the administrative record: who set what, labeled how, and for how long. --}}
    @if($keyHistory->isNotEmpty())
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-body p-3 p-md-4">
                <div class="uc-lbl mb-2">Key History</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr class="text-muted small">
                                <th>Label</th>
                                <th>Key</th>
                                <th>Active</th>
                                <th>Set by</th>
                                <th class="text-end">Calls</th>
                                <th class="text-end">Lifetime cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($keyHistory as $row)
                                @php $v = $row['version']; @endphp
                                <tr>
                                    <td>
                                        {{ $v->displayLabel() }}
                                        @if($v->isCurrent())
                                            <span class="badge bg-success-subtle text-success-emphasis ms-1">Current</span>
                                        @endif
                                    </td>
                                    <td class="text-muted"><code>{{ $v->masked_key }}</code></td>
                                    <td class="small text-muted">
                                        {{ $v->started_at->format('d M Y') }}
                                        &ndash;
                                        {{ $v->ended_at ? $v->ended_at->format('d M Y') : 'now' }}
                                    </td>
                                    <td class="small text-muted">{{ $v->setBy?->employee?->full_name ?? $v->setBy?->name ?? '—' }}</td>
                                    <td class="text-end">{{ $fmtTok($row['calls']) }}</td>
                                    <td class="text-end">{{ $fmtUsd($row['cost_usd']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- ─────────── Usage & Cost ─────────── --}}
    <div class="d-flex align-items-center gap-3 mt-5 mb-3">
        <div class="ca-hero" style="background:linear-gradient(135deg,#0891b2,#0e7490);"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="flex-grow-1">
            <h5 class="mb-0 fw-bold">Usage &amp; Cost</h5>
            <div class="text-muted small">Tokens spent on Claude, broken down by month and by the feature that used them.</div>
        </div>
    </div>

    {{-- Filters. Both selects submit the one form on change, so period and feature
         narrow the report together. Export lives per-month in the accordion below —
         there is no whole-period export button (download each month instead). --}}
    <form method="GET" action="{{ route('superadmin.claude-api.index') }}" class="d-flex flex-wrap align-items-center gap-3 mb-3" id="caFilterForm">
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-muted">Period</label>
            <select name="period" class="form-select form-select-sm uc-filter" style="width:auto;">
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
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small text-muted">Feature</label>
            <select name="feature" class="form-select form-select-sm uc-filter" style="width:auto;">
                <option value="" @selected($feature === '')>All features</option>
                @forelse($availableFeatures as $key => $label)
                    <option value="{{ $key }}" @selected($feature === $key)>{{ $label }}</option>
                @empty
                    <option disabled>— none recorded yet —</option>
                @endforelse
            </select>
        </div>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button></noscript>
    </form>

    {{-- Summary — one hero figure (total spend) with supporting stats beside it.
         Four identical tiles gave equal weight to four unequal numbers; the question
         this page exists to answer is "what are we spending", so that number leads. --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-5">
                    {{-- RM leads — this is a Malaysian finance figure; USD is shown under it
                         as the currency Anthropic actually bills, so both are one glance apart. --}}
                    <div class="uc-lbl">Total spent · {{ $period['label'] }}</div>
                    <div class="uc-hero">{{ $fmtMyr($totals['cost_myr']) }}</div>
                    <div class="uc-sub">
                        ≈ {{ $fmtUsd($totals['cost_usd']) }} <span class="uc-dim">billed</span>
                        <span class="uc-dim">· RM {{ number_format($totals['myr_rate'], 4) }} / USD</span>
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
                            <div class="uc-stat">{{ $fmtMyrP($totals['avg_per_call'] * $totals['myr_rate']) }}</div>
                            <div class="uc-dim" style="font-size:.72rem;">{{ $fmtUsd($totals['avg_per_call']) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Spend by Key — period/feature-filtered, same as everything else in this
         section (unlike the Key History card above, which is the unfiltered
         administrative record). Hidden with only one row: a single key/bucket just
         duplicates the hero total above with nothing new to say. --}}
    @if($byKey->count() > 1)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="uc-lbl mb-2">Spend by Key · {{ $period['label'] }}</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr class="text-muted small">
                                <th>Key</th>
                                <th class="text-end">Calls</th>
                                <th class="text-end">Tokens</th>
                                <th class="text-end">Cost (USD)</th>
                                <th class="text-end">Cost (MYR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($byKey as $k)
                                <tr>
                                    <td>
                                        {{ $k['label'] }}
                                        @if($k['masked_key'])
                                            <code class="text-muted small ms-1">{{ $k['masked_key'] }}</code>
                                        @endif
                                        @if($k['is_current'])
                                            <span class="badge bg-success-subtle text-success-emphasis ms-1">Current</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $fmtTok($k['calls']) }}</td>
                                    <td class="text-end">{{ $fmtTok($k['total_tokens']) }}</td>
                                    <td class="text-end fw-semibold">{{ $fmtUsd($k['cost_usd']) }}</td>
                                    <td class="text-end uc-dim">{{ $fmtMyr($k['cost_myr']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    @if(!empty($unpricedModels))
        <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>Some usage isn't priced.</strong> These models have logged calls but no rate on file, so they count as
            <strong>$0.00</strong> above:
            @foreach($unpricedModels as $m)<code>{{ $m }}</code>@if(!$loop->last), @endif @endforeach.
            Add each model's rate in <code>config/claude.php</code> to make the totals complete.
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

    {{-- What am I looking at? — native <details>, no JS, CSP-safe. The input/output
         split is front-and-centre in the accordion below, so the explainer stays. --}}
    <details class="uc-explain mb-3">
        <summary><i class="bi bi-question-circle me-1"></i>What do “input” and “output” mean?</summary>
        <div class="uc-explain-body">
            A call is billed in two halves, at different prices.
            <strong>Input</strong> is everything sent to Claude — the instructions plus the receipt image itself
            (the image is the bulk of it). <strong>Output</strong> is what Claude writes back — for a receipt scan,
            just a short line of data like the amount and date.
            Output costs more per token, but far more is sent than comes back, so input usually dominates.
            A typical receipt scan on Haiku is roughly 2,000 input + 400 output tokens ≈ <strong>$0.004</strong>.
        </div>
    </details>

    {{-- Year › Month › Feature accordion. Native Bootstrap collapse (data-bs-toggle),
         so no inline handlers — CSP-safe. Years and the newest month open by default;
         each feature drops down to its input / output token + cost split. --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-3 p-md-4">
            @forelse($byYear as $yi => $year)
                @php $yId = 'ucY'.$year['year']; @endphp
                <div class="uc-year {{ $yi > 0 ? 'mt-2' : '' }}">
                    <button class="uc-yhead" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $yId }}"
                            aria-expanded="true" aria-controls="{{ $yId }}">
                        <i class="bi bi-chevron-right uc-chev"></i>
                        <span class="fw-bold">{{ $year['year'] }}</span>
                        <span class="ms-auto uc-dim small me-2">{{ $fmtTok($year['calls']) }} calls · {{ $fmtTok($year['total_tokens']) }} tokens</span>
                        <span class="uc-amt">{{ $fmtUsd($year['cost_usd']) }}</span>
                    </button>

                    <div class="collapse show" id="{{ $yId }}">
                        @foreach($year['months'] as $mi => $month)
                            @php $mId = 'ucM'.str_replace('-', '', $month['ym']); @endphp
                            <div class="uc-month">
                                <div class="uc-mhead-row">
                                    <button class="uc-mhead" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $mId }}"
                                            aria-expanded="{{ $mi === 0 ? 'true' : 'false' }}" aria-controls="{{ $mId }}">
                                        <i class="bi bi-chevron-right uc-chev"></i>
                                        <span class="fw-semibold">{{ $month['label'] }}</span>
                                        <span class="ms-auto uc-dim small me-2 d-none d-sm-inline">{{ $fmtTok($month['calls']) }} calls · {{ $fmtTok($month['total_tokens']) }} tokens</span>
                                        <span class="uc-amt">{{ $fmtUsd($month['cost_usd']) }}</span>
                                        <span class="uc-dim small ms-2 d-none d-md-inline">({{ $fmtMyr($month['cost_myr']) }})</span>
                                    </button>
                                    {{-- Download THIS month, broken down by feature. A sibling of the
                                         toggle (not nested in the button), so it doesn't toggle the panel.
                                         Carries the feature filter so the PDF matches what's on screen. --}}
                                    <a href="{{ route('superadmin.claude-api.usage-pdf', array_filter(['period' => $month['ym'], 'feature' => $feature])) }}"
                                       class="btn btn-sm btn-outline-danger uc-dl" title="Download {{ $month['label'] }} (by feature) as PDF">
                                        <i class="bi bi-file-earmark-pdf"></i>
                                    </a>
                                </div>

                                <div class="collapse {{ $mi === 0 ? 'show' : '' }}" id="{{ $mId }}">
                                    @foreach($month['features'] as $f)
                                        @php $fId = $mId.'F'.$loop->index; @endphp
                                        <button class="uc-fhead" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $fId }}"
                                                aria-expanded="false" aria-controls="{{ $fId }}">
                                            <i class="bi bi-chevron-right uc-chev"></i>
                                            <span class="uc-key" style="background:{{ $f['color'] }};"></span>
                                            <span class="uc-feat-name">{{ $f['label'] }}</span>
                                            <span class="ms-auto uc-dim small me-2 d-none d-sm-inline">{{ $fmtTok($f['calls']) }} calls · {{ $fmtTok($f['total_tokens']) }} tokens</span>
                                            <span class="fw-semibold">{{ $fmtUsd($f['cost_usd']) }}</span>
                                        </button>
                                        <div class="collapse" id="{{ $fId }}">
                                            {{-- Tinted panel with the feature's colour on its left edge, so the
                                                 breakdown reads as a distinct detail area, not white-on-white. --}}
                                            <div class="uc-split" style="border-left-color:{{ $f['color'] }};">
                                                <div class="uc-splitrow">
                                                    <span class="uc-io"><i class="bi bi-box-arrow-in-down-right"></i>Input</span>
                                                    <span class="uc-io-tok">{{ $fmtTok($f['in_tokens']) }} tokens</span>
                                                    <span class="uc-io-amt">{{ $fmtUsd($f['in_cost']) }}</span>
                                                    <span class="uc-io-myr">{{ $fmtMyr($f['in_cost_myr']) }}</span>
                                                </div>
                                                <div class="uc-splitrow">
                                                    <span class="uc-io"><i class="bi bi-box-arrow-up-right"></i>Output</span>
                                                    <span class="uc-io-tok">{{ $fmtTok($f['out_tokens']) }} tokens</span>
                                                    <span class="uc-io-amt">{{ $fmtUsd($f['out_cost']) }}</span>
                                                    <span class="uc-io-myr">{{ $fmtMyr($f['out_cost_myr']) }}</span>
                                                </div>
                                                <div class="uc-splitrow uc-splittot">
                                                    <span class="uc-io">Total</span>
                                                    <span class="uc-io-tok">{{ $fmtTok($f['total_tokens']) }} tokens</span>
                                                    <span class="uc-io-amt">{{ $fmtUsd($f['cost_usd']) }}</span>
                                                    <span class="uc-io-myr">{{ $fmtMyr($f['cost_myr']) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
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

    .uc-key   { width:10px; height:10px; border-radius:3px; display:inline-block; flex-shrink:0; }
    .uc-amt   { font-weight:600; color:#0b0b0b; font-variant-numeric:tabular-nums; }

    /* "What is input/output?" — native <details>, styled to match the muted chrome. */
    .uc-explain summary { cursor:pointer; font-size:.82rem; color:#52514e; font-weight:500; list-style:none; }
    .uc-explain summary::-webkit-details-marker { display:none; }
    .uc-explain[open] summary { margin-bottom:.4rem; }
    .uc-explain-body { font-size:.82rem; color:#52514e; background:#f9f9f7; border:1px solid #e1e0d9; border-radius:8px; padding:.7rem .9rem; }

    /* ── Year › Month › Feature accordion ────────────────────────────────────────
       Three nested Bootstrap collapses. Every header is a <button> toggled by
       data-bs-toggle (no inline JS — CSP-safe); the chevron rotates off the
       aria-expanded state Bootstrap maintains. */
    .uc-yhead, .uc-mhead, .uc-fhead {
        display:flex; align-items:center; gap:.5rem; width:100%; text-align:left;
        background:transparent; border:0; padding:.55rem .25rem; color:#0b0b0b;
    }
    .uc-yhead { border-bottom:1px solid #e1e0d9; font-size:1rem; }
    .uc-month { border-top:1px solid #f0efec; }
    .uc-month:first-child { border-top:0; }
    .uc-mhead-row { display:flex; align-items:center; padding-left:1.4rem; }
    .uc-mhead { padding-left:0; }
    .uc-fhead { padding-left:2.6rem; font-size:.85rem; border-top:1px solid #f5f5f2; }
    .uc-yhead:hover, .uc-mhead:hover, .uc-fhead:hover { background:#f4f5f7; }
    /* When a header is open, tint it so it reads as connected to the panel it reveals. */
    .uc-mhead[aria-expanded="true"], .uc-fhead[aria-expanded="true"] { background:#eef1f5; }
    .uc-feat-name { color:#52514e; }
    .uc-fhead[aria-expanded="true"] .uc-feat-name { color:#0b0b0b; }
    .uc-dl { padding:.05rem .45rem; margin-left:.35rem; flex-shrink:0; }

    /* Chevron: points right when collapsed, rotates down when the panel is open. */
    .uc-chev { font-size:.7rem; color:#898781; transition:transform .15s ease; flex-shrink:0; }
    [aria-expanded="true"] > .uc-chev { transform:rotate(90deg); color:#0b0b0b; }

    /* The input/output/total split revealed when a feature drops down. A tinted, boxed
       panel (not white-on-white) with the feature's colour on its left edge, and a 4-column
       grid so labels, token counts, USD and MYR line up cleanly. */
    .uc-split { margin:.35rem 1rem .7rem 2.6rem; background:#f5f7fa; border:1px solid #e3e7ec;
                border-left:3px solid #cbd2da; border-radius:10px; padding:.35rem .95rem; }
    .uc-splitrow { display:grid; grid-template-columns:96px 1fr auto auto; align-items:center;
                   gap:.9rem; padding:.4rem 0; font-size:.83rem; }
    .uc-splitrow + .uc-splitrow { border-top:1px solid #e3e7ec; }
    /* Ink + a direction icon, not hue — blue already means "eClaim" via the module dot. */
    .uc-io { font-weight:600; color:#0b0b0b; white-space:nowrap; }
    .uc-io i { color:#6b7280; margin-right:.35rem; }
    .uc-io-tok { color:#52514e; font-variant-numeric:tabular-nums; }
    .uc-io-amt { justify-self:end; min-width:72px; text-align:right; font-weight:600; color:#0b0b0b; font-variant-numeric:tabular-nums; }
    .uc-io-myr { justify-self:end; min-width:66px; text-align:right; color:#5b6470; font-variant-numeric:tabular-nums; }
    /* Total: a firmer rule above it and heavier ink, so the sum stands apart from the two
       halves. Selector matches the sibling rule's specificity (0,2,0) and comes later, so it wins. */
    .uc-splitrow.uc-splittot { border-top:1.5px solid #cbd2da; margin-top:.1rem; padding-top:.5rem; }
    .uc-splittot .uc-io-tok { color:#0b0b0b; font-weight:600; }
    .uc-splittot .uc-io-myr { color:#0b0b0b; }
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

    // Period + Feature selects auto-submit their form (CSP blocks inline onchange —
    // must be addEventListener). Event delegation covers both with one listener.
    const filterForm = document.getElementById('caFilterForm');
    if (filterForm) {
        filterForm.querySelectorAll('select.uc-filter').forEach(function (el) {
            el.addEventListener('change', function () { filterForm.submit(); });
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
