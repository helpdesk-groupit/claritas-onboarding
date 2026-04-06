@extends('layouts.app')
@section('title', 'Executive Dashboard')
@section('page-title', 'Executive Dashboard')

@push('styles')
<style>
    .kpi-card { border: none; border-radius: 12px; transition: transform 0.15s; }
    .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .kpi-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .kpi-value { font-size: 28px; font-weight: 700; line-height: 1; color: #1e293b; }
    .kpi-label { font-size: 12px; color: #64748b; margin-top: 2px; }
    .section-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
    .chart-card { border: 1px solid #e2e8f0; border-radius: 12px; }
    .chart-card .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 13px; }
    .mini-table th { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; border-top: none; }
    .mini-table td { font-size: 13px; }
    .stat-pill { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
</style>
@endpush

@section('content')
@include('reports.partials.report-header')

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- ROW 1: TOP-LINE KPIs --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
<div class="mb-2"><span class="section-title"><i class="bi bi-speedometer2 me-1"></i>Key Performance Indicators — {{ $year }}</span></div>
<div class="row g-3 mb-4">
    {{-- Active Employees --}}
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card kpi-card h-100" style="border-left:4px solid #2563eb;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="kpi-icon" style="background:#dbeafe;"><i class="bi bi-people-fill" style="font-size:20px;color:#2563eb;"></i></div>
                </div>
                <div class="kpi-value">{{ number_format($totalActive) }}</div>
                <div class="kpi-label">Active Employees</div>
            </div>
        </div>
    </div>
    {{-- New Hires YTD --}}
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card kpi-card h-100" style="border-left:4px solid #10b981;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="kpi-icon" style="background:#d1fae5;"><i class="bi bi-person-plus-fill" style="font-size:20px;color:#10b981;"></i></div>
                </div>
                <div class="kpi-value">{{ number_format($totalNewHires) }}</div>
                <div class="kpi-label">New Hires YTD</div>
            </div>
        </div>
    </div>
    {{-- Exits YTD --}}
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card kpi-card h-100" style="border-left:4px solid #ef4444;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="kpi-icon" style="background:#fee2e2;"><i class="bi bi-person-dash-fill" style="font-size:20px;color:#ef4444;"></i></div>
                </div>
                <div class="kpi-value">{{ number_format($totalExits) }}</div>
                <div class="kpi-label">Exits YTD</div>
            </div>
        </div>
    </div>
    {{-- Turnover Rate --}}
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card kpi-card h-100" style="border-left:4px solid #f59e0b;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="kpi-icon" style="background:#fef3c7;"><i class="bi bi-arrow-repeat" style="font-size:20px;color:#f59e0b;"></i></div>
                </div>
                <div class="kpi-value">{{ $turnoverRate }}%</div>
                <div class="kpi-label">Turnover Rate</div>
            </div>
        </div>
    </div>
    {{-- YTD Payroll --}}
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card kpi-card h-100" style="border-left:4px solid #8b5cf6;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="kpi-icon" style="background:#ede9fe;"><i class="bi bi-cash-stack" style="font-size:20px;color:#8b5cf6;"></i></div>
                </div>
                <div class="kpi-value" style="font-size:20px;">RM {{ number_format($payrollStats->gross ?? 0, 0) }}</div>
                <div class="kpi-label">YTD Gross Payroll</div>
            </div>
        </div>
    </div>
    {{-- Avg Salary --}}
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card kpi-card h-100" style="border-left:4px solid #06b6d4;">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="kpi-icon" style="background:#cffafe;"><i class="bi bi-wallet2" style="font-size:20px;color:#06b6d4;"></i></div>
                </div>
                <div class="kpi-value" style="font-size:20px;">RM {{ number_format($avgSalary ?? 0, 0) }}</div>
                <div class="kpi-label">Average Salary</div>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- ROW 2: Secondary KPIs --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">
    {{-- Attendance Rate --}}
    <div class="col-6 col-md-3">
        <div class="card kpi-card h-100" style="border-left:4px solid #14b8a6;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ $attendanceRate }}%</div>
                <div class="kpi-label">Attendance Rate</div>
            </div>
        </div>
    </div>
    {{-- Leave Days Taken --}}
    <div class="col-6 col-md-3">
        <div class="card kpi-card h-100" style="border-left:4px solid #ec4899;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($leaveStats->total_days_taken ?? 0, 0) }}</div>
                <div class="kpi-label">Leave Days Taken YTD</div>
            </div>
        </div>
    </div>
    {{-- Total Assets --}}
    <div class="col-6 col-md-3">
        <div class="card kpi-card h-100" style="border-left:4px solid #f97316;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($assetStats['total']) }}</div>
                <div class="kpi-label">Total Assets</div>
                <div class="mt-1">
                    <span class="stat-pill" style="background:#d1fae5;color:#059669;">{{ $assetStats['available'] }} avail</span>
                    <span class="stat-pill" style="background:#dbeafe;color:#2563eb;">{{ $assetStats['assigned'] }} in use</span>
                </div>
            </div>
        </div>
    </div>
    {{-- Claims Approved --}}
    <div class="col-6 col-md-3">
        <div class="card kpi-card h-100" style="border-left:4px solid #a855f7;">
            <div class="card-body p-3">
                <div class="kpi-value" style="font-size:20px;">RM {{ number_format($claimsStats->approved_amount ?? 0, 0) }}</div>
                <div class="kpi-label">Claims Approved YTD</div>
                @if(($claimsStats->pending_amount ?? 0) > 0)
                <div class="mt-1"><span class="stat-pill" style="background:#fef3c7;color:#d97706;">RM {{ number_format($claimsStats->pending_amount, 0) }} pending</span></div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- ROW 3: CHARTS --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}

<div class="row g-3 mb-4">
    {{-- Headcount Trend (Hires vs Exits) --}}
    <div class="col-lg-8">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-graph-up me-1"></i>Headcount Movement — {{ $year }}</div>
            <div class="card-body"><canvas id="headcountChart" height="260"></canvas></div>
        </div>
    </div>
    {{-- Employment Type --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-pie-chart me-1"></i>Employment Type</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="empTypeChart" height="220"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    {{-- Payroll Trend --}}
    <div class="col-lg-8">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-bar-chart me-1"></i>Monthly Gross Payroll — {{ $year }}</div>
            <div class="card-body"><canvas id="payrollChart" height="260"></canvas></div>
        </div>
    </div>
    {{-- Gender Distribution --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-gender-ambiguous me-1"></i>Gender Distribution</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="genderChart" height="220"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    {{-- Department Distribution --}}
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-building me-1"></i>Headcount by Department</div>
            <div class="card-body"><canvas id="deptChart" height="280"></canvas></div>
        </div>
    </div>
    {{-- Tenure Distribution --}}
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-hourglass-split me-1"></i>Tenure Distribution</div>
            <div class="card-body"><canvas id="tenureChart" height="280"></canvas></div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- ROW 4: Company Distribution + Leave by Type + Claims by Category --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">
    {{-- Company Distribution --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-buildings me-1"></i>Headcount by Company</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Company</th><th class="text-end">Count</th><th class="text-end">%</th></tr></thead>
                    <tbody>
                    @foreach($companyDistribution as $row)
                    <tr>
                        <td>{{ $row->comp }}</td>
                        <td class="text-end fw-semibold">{{ $row->total }}</td>
                        <td class="text-end">{{ $totalActive > 0 ? round($row->total / $totalActive * 100, 1) : 0 }}%</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {{-- Leave by Type --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-calendar-x me-1"></i>Leave Taken by Type</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Type</th><th class="text-end">Days</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    @forelse($leaveByType as $row)
                    <tr>
                        <td>{{ $row->type_name }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row->total_days, 1) }}</td>
                        <td class="text-end">{{ $row->count }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="text-muted text-center">No data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {{-- Claims by Category --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-receipt me-1"></i>Claims by Category</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Category</th><th class="text-end">Amount (RM)</th></tr></thead>
                    <tbody>
                    @forelse($claimsByCategory as $row)
                    <tr>
                        <td>{{ $row->category }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row->total, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="2" class="text-muted text-center">No data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════ --}}
{{-- ROW 5: Statutory + Onboarding Pipeline + Asset Summary --}}
{{-- ═══════════════════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">
    {{-- Statutory Contributions --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-bank me-1"></i>Statutory Contributions YTD</div>
            <div class="card-body">
                @php $st = $statutoryTotals; @endphp
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Contribution</th><th class="text-end">Employee (RM)</th><th class="text-end">Employer (RM)</th></tr></thead>
                    <tbody>
                    <tr><td>EPF</td><td class="text-end">{{ number_format($st->epf_ee ?? 0, 2) }}</td><td class="text-end">{{ number_format($st->epf_er ?? 0, 2) }}</td></tr>
                    <tr><td>SOCSO</td><td class="text-end">{{ number_format($st->socso_ee ?? 0, 2) }}</td><td class="text-end">{{ number_format($st->socso_er ?? 0, 2) }}</td></tr>
                    <tr><td>EIS</td><td class="text-end">{{ number_format($st->eis_ee ?? 0, 2) }}</td><td class="text-end">{{ number_format($st->eis_er ?? 0, 2) }}</td></tr>
                    <tr><td>PCB (Tax)</td><td class="text-end">{{ number_format($st->pcb ?? 0, 2) }}</td><td class="text-end">—</td></tr>
                    <tr><td>HRDF</td><td class="text-end">—</td><td class="text-end">{{ number_format($st->hrdf ?? 0, 2) }}</td></tr>
                    </tbody>
                    <tfoot>
                    <tr class="fw-bold">
                        <td>Total</td>
                        <td class="text-end">{{ number_format(($st->epf_ee ?? 0)+($st->socso_ee ?? 0)+($st->eis_ee ?? 0)+($st->pcb ?? 0), 2) }}</td>
                        <td class="text-end">{{ number_format(($st->epf_er ?? 0)+($st->socso_er ?? 0)+($st->eis_er ?? 0)+($st->hrdf ?? 0), 2) }}</td>
                    </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    {{-- Onboarding Pipeline --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-funnel me-1"></i>Onboarding Pipeline</div>
            <div class="card-body">
                <div class="d-flex flex-column gap-3">
                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold">Pending</span>
                            <span class="badge bg-warning text-dark">{{ $pipelineStats['pending'] }}</span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar bg-warning" style="width:{{ max(($pipelineStats['pending']/max(array_sum($pipelineStats),1))*100,2) }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold">Active (In Progress)</span>
                            <span class="badge bg-primary">{{ $pipelineStats['active'] }}</span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar bg-primary" style="width:{{ max(($pipelineStats['active']/max(array_sum($pipelineStats),1))*100,2) }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="small fw-semibold">Completed ({{ $year }})</span>
                            <span class="badge bg-success">{{ $pipelineStats['completed'] }}</span>
                        </div>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar bg-success" style="width:{{ max(($pipelineStats['completed']/max(array_sum($pipelineStats),1))*100,2) }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- Asset Summary --}}
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-laptop me-1"></i>Asset Portfolio</div>
            <div class="card-body">
                <canvas id="assetStatusChart" height="180"></canvas>
                <div class="d-flex justify-content-between mt-3 small">
                    <div><span class="text-muted">Total Value:</span> <strong>RM {{ number_format($assetCostTotal, 0) }}</strong></div>
                    <div><span class="text-muted">Monthly Rental:</span> <strong>RM {{ number_format($rentalCostMonthly, 0) }}</strong></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Chart.js --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const COLORS = ['#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#f97316','#14b8a6','#6366f1'];
    Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.font.size = 11;

    // ── Headcount Trend ─────────────────────────────────
    const hcData = @json($headcountTrend);
    new Chart(document.getElementById('headcountChart'), {
        type: 'bar',
        data: {
            labels: hcData.map(d => d.month),
            datasets: [
                { label: 'New Hires', data: hcData.map(d => d.hires), backgroundColor: '#10b981', borderRadius: 4 },
                { label: 'Exits', data: hcData.map(d => d.exits), backgroundColor: '#ef4444', borderRadius: 4 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // ── Employment Type Doughnut ────────────────────────
    const etData = @json($empTypeBreakdown);
    new Chart(document.getElementById('empTypeChart'), {
        type: 'doughnut',
        data: {
            labels: etData.map(d => d.etype.charAt(0).toUpperCase() + d.etype.slice(1)),
            datasets: [{ data: etData.map(d => d.total), backgroundColor: COLORS, borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { padding: 12 } } } }
    });

    // ── Payroll Trend ───────────────────────────────────
    const prData = @json($payrollTrend);
    new Chart(document.getElementById('payrollChart'), {
        type: 'bar',
        data: {
            labels: prData.map(d => d.month),
            datasets: [{
                label: 'Gross Payroll (RM)',
                data: prData.map(d => d.amount),
                backgroundColor: '#8b5cf6',
                borderRadius: 4
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => 'RM ' + v.toLocaleString() } } } }
    });

    // ── Gender Doughnut ─────────────────────────────────
    const gdData = @json($genderDistribution);
    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut',
        data: {
            labels: gdData.map(d => d.gender.charAt(0).toUpperCase() + d.gender.slice(1)),
            datasets: [{ data: gdData.map(d => d.total), backgroundColor: ['#2563eb','#ec4899','#94a3b8'], borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { padding: 12 } } } }
    });

    // ── Department Bar ──────────────────────────────────
    const dpData = @json($deptDistribution);
    new Chart(document.getElementById('deptChart'), {
        type: 'bar',
        data: {
            labels: dpData.map(d => d.dept),
            datasets: [{ label: 'Headcount', data: dpData.map(d => d.total), backgroundColor: '#06b6d4', borderRadius: 4 }]
        },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // ── Tenure Bar ──────────────────────────────────────
    const tnData = @json($tenureBuckets);
    new Chart(document.getElementById('tenureChart'), {
        type: 'bar',
        data: {
            labels: Object.keys(tnData),
            datasets: [{ label: 'Employees', data: Object.values(tnData), backgroundColor: ['#dbeafe','#93c5fd','#60a5fa','#3b82f6','#1d4ed8'], borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // ── Asset Status Doughnut ───────────────────────────
    const asData = @json($assetStats);
    new Chart(document.getElementById('assetStatusChart'), {
        type: 'doughnut',
        data: {
            labels: ['Available','Assigned','Maintenance','Disposed'],
            datasets: [{ data: [asData.available, asData.assigned, asData.maintenance, asData.disposed], backgroundColor: ['#10b981','#2563eb','#f59e0b','#94a3b8'], borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '50%', plugins: { legend: { position: 'bottom', labels: { padding: 8, font: { size: 10 } } } } }
    });
});
</script>
@endsection
