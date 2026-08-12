{{-- Shared filter bar + Chart.js CDN for all report pages --}}
@php
    $canViewFullReports = Auth::user()->isSuperadmin() || Auth::user()->isHrManager() || Auth::user()->isSystemAdmin();

    // Decommissioning is NOT a top-level report — it lives *under* Assets as a sub-view.
    $reportPages = [
        ['route' => 'reports.executive', 'label' => 'Executive Dashboard', 'icon' => 'bi-speedometer2'],
        ['route' => 'reports.workforce', 'label' => 'Workforce', 'icon' => 'bi-people'],
        ['route' => 'reports.financial', 'label' => 'Financial', 'icon' => 'bi-currency-dollar'],
        ['route' => 'reports.leave',     'label' => 'Leave', 'icon' => 'bi-calendar-x'],
        ['route' => 'reports.attendance','label' => 'Attendance', 'icon' => 'bi-clock-history'],
        ['route' => 'reports.assets',    'label' => 'Assets', 'icon' => 'bi-laptop'],
    ];

    // IT managers + Finance can only reach the Decommissioning archive (which now sits
    // under Assets). Give them a single "Assets" tab that lands straight on it.
    if (! $canViewFullReports) {
        $reportPages = [
            ['route' => 'reports.decommission', 'label' => 'Assets', 'icon' => 'bi-laptop'],
        ];
    }

    $onAssetsGroup = request()->routeIs('reports.assets') || request()->routeIs('reports.decommission');
    $reportFilters = array_filter(['year' => $year ?? null, 'company' => $companyFilter ?? null]);
@endphp

{{-- Report Navigation Tabs --}}
<div class="card mb-4" style="border:none;background:linear-gradient(135deg,#0f172a,#1e3a5f);position:sticky;top:57px;z-index:40;">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-graph-up-arrow text-white" style="font-size:22px;"></i>
            <h5 class="text-white mb-0 fw-bold">C-Suite Analytics & Reports</h5>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @foreach($reportPages as $page)
            {{-- The Assets tab stays highlighted while viewing its Decommissioning sub-view. --}}
            @php $isActive = request()->routeIs($page['route']) || ($page['route'] === 'reports.assets' && request()->routeIs('reports.decommission')); @endphp
            <a href="{{ route($page['route'], $reportFilters) }}"
               class="btn btn-sm {{ $isActive ? 'btn-light fw-semibold' : 'btn-outline-light' }}"
               style="font-size:12px;">
                <i class="{{ $page['icon'] }} me-1"></i>{{ $page['label'] }}
            </a>
            @endforeach
        </div>

        {{-- Sub-view strip: Assets → (Overview | Decommissioning) --}}
        @if($onAssetsGroup)
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3 pt-3" style="border-top:1px solid rgba(255,255,255,.15);">
            <span class="text-white-50 small me-1"><i class="bi bi-diagram-3 me-1"></i>Assets:</span>
            @if($canViewFullReports)
            <a href="{{ route('reports.assets', $reportFilters) }}"
               class="btn btn-sm {{ request()->routeIs('reports.assets') ? 'btn-light fw-semibold' : 'btn-outline-light' }}" style="font-size:12px;">
                <i class="bi bi-graph-up me-1"></i>Overview
            </a>
            @endif
            <a href="{{ route('reports.decommission', array_filter(['year' => $year ?? null])) }}"
               class="btn btn-sm {{ request()->routeIs('reports.decommission') ? 'btn-light fw-semibold' : 'btn-outline-light' }}" style="font-size:12px;">
                <i class="bi bi-recycle me-1"></i>Decommissioning
            </a>
        </div>
        @endif
    </div>
</div>

{{-- Filter Bar. A page that ships its own richer filter set passes $hideFilters = true —
     otherwise it renders a second, redundant Year select right above its own (which is
     exactly what the Decommissioning report used to do). --}}
@unless($hideFilters ?? false)
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-center gap-3 flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small fw-semibold text-muted">Year</label>
                <select name="year" class="form-select form-select-sm" style="width:100px;" onchange="this.form.submit()">
                    @for($y = now()->year; $y >= now()->year - 5; $y--)
                    <option value="{{ $y }}" {{ ($year ?? now()->year) == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
            @if(isset($companies) && count($companies) > 0)
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small fw-semibold text-muted">Company</label>
                <select name="company" class="form-select form-select-sm" style="width:200px;" onchange="this.form.submit()">
                    <option value="">All Companies</option>
                    @foreach($companies as $c)
                    <option value="{{ $c }}" {{ ($companyFilter ?? '') === $c ? 'selected' : '' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <a href="{{ url()->current() }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise"></i> Reset
            </a>
        </form>
    </div>
</div>
@endunless
