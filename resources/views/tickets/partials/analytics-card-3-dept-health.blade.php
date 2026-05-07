{{--
    Card 3 — Department Health (orange, filterable by company)

    Top: tier summary chips — quick glance at how many depts are in each band.
    Below: horizontal bar chart, one row per dept, bar width proportional to
    avg resolution time, colour-coded by tier:
        Good   (green)  — avg ≤ 24h
        Amber  (amber)  — avg 24h–72h
        Poor   (red)    — avg > 72h
        No data (gray)  — no resolved tickets yet

    Bar fills 100% at 72h+ — anything slower is "off-the-chart" poor.
    Thresholds: Ticket::HEALTH_GOOD_MAX_MINUTES + HEALTH_AMBER_MAX_MINUTES.
--}}
<div class="col-lg-4">
    <div class="analytics-card orange h-100">
        <div class="card-fancy-header">
            <div class="icon-box"><i class="bi bi-heart-pulse-fill"></i></div>
            <div class="header-meta">
                <div class="number">{{ $analytics['totalActive'] }}</div>
                <div class="subtitle">Active Tickets</div>
            </div>
            @if(!empty($analytics['availableCompanies']))
                <select class="filter-select" id="card3CompanyFilter">
                    <option value="__all__">All Companies</option>
                    @foreach($analytics['availableCompanies'] as $c)
                        <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="body-section">
            <div class="body-label">Department Health · Avg Resolution Time</div>

            {{-- Tier summary chips at top — quick scan of dept distribution --}}
            <div class="tier-summary" id="card3TierSummary">
                @php $tierCounts = $analytics['deptTierCounts']['__all__'] ?? ['good'=>0,'amber'=>0,'poor'=>0,'nodata'=>0]; @endphp
                <span class="tier-summary-chip tier-good"><span class="dot"></span><span class="cnt">{{ $tierCounts['good'] }}</span> Good</span>
                <span class="tier-summary-chip tier-amber"><span class="dot"></span><span class="cnt">{{ $tierCounts['amber'] }}</span> Amber</span>
                <span class="tier-summary-chip tier-poor"><span class="dot"></span><span class="cnt">{{ $tierCounts['poor'] }}</span> Poor</span>
                <span class="tier-summary-chip tier-nodata"><span class="dot"></span><span class="cnt">{{ $tierCounts['nodata'] }}</span> No data</span>
            </div>

            <div id="card3List">
                @php $deptsAll = $analytics['deptStats']['__all__'] ?? []; @endphp
                @if(empty($deptsAll))
                    <div class="text-center text-muted py-3 small">No data yet.</div>
                @else
                    @foreach($deptsAll as $d)
                        <div class="perf-row">
                            <div class="perf-name">
                                {{ $d['department'] }}
                                @if($d['count'] > 0)
                                    <span class="perf-meta">{{ $d['count'] }} resolved</span>
                                @else
                                    <span class="perf-meta">no resolutions yet</span>
                                @endif
                            </div>
                            <div class="perf-bar-wrap">
                                <div class="perf-bar">
                                    <div class="perf-bar-fill tier-{{ $d['tier'] }}"
                                         style="width: {{ number_format($d['width_pct'], 1) }}%;"
                                         title="Avg {{ $d['formatted'] }} (scale: 0 to 72h)"></div>
                                </div>
                            </div>
                            <div class="perf-time tier-{{ $d['tier'] }}">{{ $d['formatted'] }}</div>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
