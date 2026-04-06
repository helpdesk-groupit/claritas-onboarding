{{-- Shared filter bar + Chart.js CDN for all report pages --}}
@php
    $reportPages = [
        ['route' => 'reports.executive', 'label' => 'Executive Dashboard', 'icon' => 'bi-speedometer2'],
        ['route' => 'reports.workforce', 'label' => 'Workforce', 'icon' => 'bi-people'],
        ['route' => 'reports.financial', 'label' => 'Financial', 'icon' => 'bi-currency-dollar'],
        ['route' => 'reports.leave',     'label' => 'Leave', 'icon' => 'bi-calendar-x'],
        ['route' => 'reports.attendance','label' => 'Attendance', 'icon' => 'bi-clock-history'],
        ['route' => 'reports.assets',    'label' => 'Assets', 'icon' => 'bi-laptop'],
    ];
@endphp

{{-- Report Navigation Tabs --}}
<div class="card mb-4" style="border:none;background:linear-gradient(135deg,#0f172a,#1e3a5f);">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-graph-up-arrow text-white" style="font-size:22px;"></i>
            <h5 class="text-white mb-0 fw-bold">C-Suite Analytics & Reports</h5>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @foreach($reportPages as $page)
            <a href="{{ route($page['route'], array_filter(['year' => $year ?? null, 'company' => $companyFilter ?? null])) }}"
               class="btn btn-sm {{ request()->routeIs($page['route']) ? 'btn-light fw-semibold' : 'btn-outline-light' }}"
               style="font-size:12px;">
                <i class="{{ $page['icon'] }} me-1"></i>{{ $page['label'] }}
            </a>
            @endforeach
        </div>
    </div>
</div>

{{-- Filter Bar --}}
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
