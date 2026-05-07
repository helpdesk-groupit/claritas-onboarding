{{--
    Card 2 — Avg Resolution Time by PIC (green, filterable by company)

    Each PIC gets a horizontal bar — bar width is proportional to their avg
    resolution time, coloured by health tier (green/amber/red). Faster PICs
    have shorter bars; slower PICs have longer ones in warmer colours. List
    is sorted fastest first.
--}}
<div class="col-lg-4">
    <div class="analytics-card green h-100">
        <div class="card-fancy-header">
            <div class="icon-box"><i class="bi bi-stopwatch-fill"></i></div>
            <div class="header-meta">
                <div class="number">{{ $analytics['totalActive'] }}</div>
                <div class="subtitle">Active Tickets</div>
            </div>
            @if(!empty($analytics['availableCompanies']))
                <select class="filter-select" id="card2CompanyFilter">
                    <option value="__all__">All Companies</option>
                    @foreach($analytics['availableCompanies'] as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="body-section">
            <div class="body-label">Avg Resolution by PIC · Resolved tickets only</div>
            <div id="card2List">
                @php $picsAll = $analytics['picStats']['__all__'] ?? []; @endphp
                @if(empty($picsAll))
                    <div class="text-center text-muted py-3 small">No resolved tickets yet.</div>
                @else
                    @foreach($picsAll as $p)
                        <div class="perf-row">
                            <div class="perf-name">
                                {{ $p['name'] }}
                                <span class="perf-meta">{{ $p['count'] }} resolved</span>
                            </div>
                            <div class="perf-bar-wrap">
                                <div class="perf-bar">
                                    <div class="perf-bar-fill tier-{{ $p['tier'] }}"
                                         style="width: {{ number_format($p['width_pct'], 1) }}%;"
                                         title="Avg {{ $p['formatted'] }} (scale: 0 to 72h)"></div>
                                </div>
                            </div>
                            <div class="perf-time tier-{{ $p['tier'] }}">{{ $p['formatted'] }}</div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
