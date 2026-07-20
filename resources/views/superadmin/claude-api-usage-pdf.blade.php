@php
    // dompdf conventions: DejaVu Sans is the only unicode-safe bundled font, and all
    // styling must be inline/in-document (no external stylesheets are fetched).
    $fmtTok = fn ($n) => number_format((int) $n);
    $fmtUsd = fn ($n) => '$'.number_format((float) $n, 4);
    $fmtMyr = fn ($n) => 'RM'.number_format((float) $n, 2);
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 10px; color: #1e293b; margin: 0; }
    .title { font-size: 16px; font-weight: bold; margin: 0 0 2px; }
    .sub { color: #555; font-size: 9px; margin-bottom: 12px; }
    .cards { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .cards td { border: 1px solid #cbd5e1; padding: 7px 9px; width: 25%; }
    .cards .lbl { color: #64748b; font-size: 8.5px; }
    .cards .val { font-size: 14px; font-weight: bold; }
    table.usage { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.usage th, table.usage td { border: 1px solid #94a3b8; padding: 4px 6px; }
    table.usage th { background: #f1f5f9; font-size: 8.5px; text-align: left; }
    .mrow td { background: #e2e8f0; font-weight: bold; }
    .r { text-align: right; }
    .warn { border: 1px solid #f59e0b; background: #fffbeb; padding: 6px 9px; font-size: 8.5px; margin-bottom: 12px; }
    .empty { text-align: center; color: #64748b; padding: 30px 0; }
    .sec { font-size: 11px; font-weight: bold; margin: 4px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #334155; }
    .foot { color: #64748b; font-size: 8px; margin-top: 16px; border-top: 1px solid #cbd5e1; padding-top: 6px; }
</style>
</head>
<body>

    <div class="title">Claude API — Token Usage &amp; Cost</div>
    <div class="sub">
        Period: {{ $periodLabel }}
        &nbsp;·&nbsp; Generated {{ fmt_datetime($generatedAt) }}
        @if($generatedBy) &nbsp;·&nbsp; by {{ $generatedBy }} @endif
    </div>

    <table class="cards">
        <tr>
            <td><div class="lbl">Calls</div><div class="val">{{ $fmtTok($totals['calls']) }}</div></td>
            <td><div class="lbl">Tokens</div><div class="val">{{ $fmtTok($totals['tokens']) }}</div></td>
            <td><div class="lbl">Spent (USD)</div><div class="val">{{ $fmtUsd($totals['cost_usd']) }}</div></td>
            <td>
                <div class="lbl">Approx. (MYR)</div>
                <div class="val">{{ $fmtMyr($totals['cost_myr']) }}</div>
                <div class="lbl">@ {{ number_format($totals['myr_rate'], 4) }} / USD</div>
            </td>
        </tr>
    </table>

    @if(!empty($unpricedModels))
        <div class="warn">
            <strong>Incomplete pricing:</strong> {{ implode(', ', $unpricedModels) }}
            @if(count($unpricedModels) === 1) has @else have @endif logged usage but no rate on file,
            so {{ count($unpricedModels) === 1 ? 'its' : 'their' }} cost is counted as $0.00 above.
        </div>
    @endif

    <div class="sec">Spend by feature</div>
    @forelse($byModule as $mod)
        <table class="usage">
            <tr class="mrow">
                <td colspan="2">{{ $mod['module'] }} ({{ number_format($mod['share'], 1) }}% of spend)</td>
                <td class="r">{{ $fmtTok($mod['calls']) }}</td>
                <td class="r">{{ $fmtTok($mod['total_tokens']) }}</td>
                <td class="r">{{ $fmtUsd($mod['cost_usd']) }}</td>
                <td class="r">{{ $fmtMyr($mod['cost_myr']) }}</td>
            </tr>
            <tr>
                <th style="width:32%;">Feature</th>
                <th class="r">In / Out tokens</th>
                <th class="r">Calls</th>
                <th class="r">Total tokens</th>
                <th class="r">USD</th>
                <th class="r">MYR</th>
            </tr>
            @foreach($mod['features'] as $f)
                <tr>
                    <td>{{ $f['label'] }}</td>
                    <td class="r">{{ $fmtTok($f['in_tokens']) }} / {{ $fmtTok($f['out_tokens']) }}</td>
                    <td class="r">{{ $fmtTok($f['calls']) }}</td>
                    <td class="r">{{ $fmtTok($f['total_tokens']) }}</td>
                    <td class="r">{{ $fmtUsd($f['cost_usd']) }}</td>
                    <td class="r">{{ $fmtMyr($f['cost_myr']) }}</td>
                </tr>
            @endforeach
        </table>
    @empty
        <div class="empty">No Claude usage recorded for this period.</div>
    @endforelse

    <div class="sec">Spend by month</div>
    @forelse($report as $month)
        <table class="usage">
            <tr class="mrow">
                <td colspan="2">{{ $month['label'] }}</td>
                <td class="r">{{ $fmtTok($month['calls']) }}</td>
                <td class="r">{{ $fmtTok($month['total_tokens']) }}</td>
                <td class="r">{{ $fmtUsd($month['cost_usd']) }}</td>
                <td class="r">{{ $fmtMyr($month['cost_myr']) }}</td>
            </tr>
            <tr>
                <th style="width:32%;">Feature</th>
                <th class="r">In / Out tokens</th>
                <th class="r">Calls</th>
                <th class="r">Total tokens</th>
                <th class="r">USD</th>
                <th class="r">MYR</th>
            </tr>
            @foreach($month['features'] as $f)
                <tr>
                    <td>{{ $f['label'] }}</td>
                    <td class="r">{{ $fmtTok($f['in_tokens']) }} / {{ $fmtTok($f['out_tokens']) }}</td>
                    <td class="r">{{ $fmtTok($f['calls']) }}</td>
                    <td class="r">{{ $fmtTok($f['total_tokens']) }}</td>
                    <td class="r">{{ $fmtUsd($f['cost_usd']) }}</td>
                    <td class="r">{{ $fmtMyr($f['cost_myr']) }}</td>
                </tr>
            @endforeach
        </table>
    @empty
        <div class="empty">No Claude usage recorded for this period.</div>
    @endforelse

    <div class="foot">
        Costs are computed from the per-model rates in force at the time of each call, so past months
        are unaffected by later price changes. USD is the billed currency; MYR is an approximate
        conversion at the rate shown above.
    </div>

</body>
</html>
