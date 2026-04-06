@extends('layouts.app')
@section('title', 'Workforce Report')
@section('page-title', 'Workforce Analytics')

@push('styles')
<style>
    .chart-card { border: 1px solid #e2e8f0; border-radius: 12px; }
    .chart-card .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 13px; }
    .mini-table th { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; border-top: none; }
    .mini-table td { font-size: 13px; }
    .section-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
    .kpi-value { font-size: 28px; font-weight: 700; line-height: 1; color: #1e293b; }
    .kpi-label { font-size: 12px; color: #64748b; margin-top: 2px; }
</style>
@endpush

@section('content')
@include('reports.partials.report-header')

{{-- KPI Row --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #2563eb;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($totalActive) }}</div>
                <div class="kpi-label">Total Active Employees</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #10b981;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ count($byDepartment) }}</div>
                <div class="kpi-label">Departments</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #f59e0b;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ count($byCompany) }}</div>
                <div class="kpi-label">Companies</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #8b5cf6;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ count($byDesignation) }}</div>
                <div class="kpi-label">Unique Designations</div>
            </div>
        </div>
    </div>
</div>

{{-- Hires vs Exits Trend --}}
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-graph-up me-1"></i>Monthly Hires vs Exits — {{ $year }}</div>
            <div class="card-body"><canvas id="hiresExitsChart" height="280"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-pie-chart me-1"></i>Employment Type</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="empTypeChart" height="250"></canvas></div>
        </div>
    </div>
</div>

{{-- Demographics Row --}}
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-gender-ambiguous me-1"></i>Gender Split</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="genderChart" height="250"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-heart me-1"></i>Marital Status</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="maritalChart" height="250"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-person me-1"></i>Age Distribution</div>
            <div class="card-body"><canvas id="ageChart" height="250"></canvas></div>
        </div>
    </div>
</div>

{{-- Department + Tenure --}}
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-building me-1"></i>Headcount by Department</div>
            <div class="card-body"><canvas id="deptChart" height="300"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-hourglass-split me-1"></i>Tenure Distribution</div>
            <div class="card-body"><canvas id="tenureChart" height="300"></canvas></div>
        </div>
    </div>
</div>

{{-- Company + Designation Tables --}}
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-buildings me-1"></i>By Company</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Company</th><th class="text-end">Count</th><th class="text-end">%</th></tr></thead>
                    <tbody>
                    @foreach($byCompany as $row)
                    <tr>
                        <td>{{ $row->label }}</td>
                        <td class="text-end fw-semibold">{{ $row->total }}</td>
                        <td class="text-end">{{ $totalActive > 0 ? round($row->total / $totalActive * 100, 1) : 0 }}%</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-briefcase me-1"></i>Top Designations</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Designation</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    @foreach($byDesignation as $row)
                    <tr><td>{{ $row->label }}</td><td class="text-end fw-semibold">{{ $row->total }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-box-arrow-right me-1"></i>Top Resignation Reasons</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Reason</th><th class="text-end">Count</th></tr></thead>
                    <tbody>
                    @forelse($resignReasons as $row)
                    <tr><td>{{ $row->label }}</td><td class="text-end fw-semibold">{{ $row->total }}</td></tr>
                    @empty
                    <tr><td colspan="2" class="text-muted text-center">No exit data for {{ $year }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const COLORS = ['#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#ec4899','#f97316','#14b8a6','#6366f1'];
    Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.font.size = 11;

    // Hires vs Exits
    const heData = @json($hiresExitsTrend);
    new Chart(document.getElementById('hiresExitsChart'), {
        type: 'line',
        data: {
            labels: heData.map(d => d.month),
            datasets: [
                { label: 'Hires', data: heData.map(d => d.hires), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.3 },
                { label: 'Exits', data: heData.map(d => d.exits), borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.1)', fill: true, tension: 0.3 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Employment type
    const etData = @json($byEmpType);
    new Chart(document.getElementById('empTypeChart'), {
        type: 'doughnut',
        data: { labels: etData.map(d => d.label.charAt(0).toUpperCase()+d.label.slice(1)), datasets: [{ data: etData.map(d => d.total), backgroundColor: COLORS, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom' } } }
    });

    // Gender
    const gdData = @json($byGender);
    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut',
        data: { labels: gdData.map(d => d.label.charAt(0).toUpperCase()+d.label.slice(1)), datasets: [{ data: gdData.map(d => d.total), backgroundColor: ['#2563eb','#ec4899','#94a3b8'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom' } } }
    });

    // Marital
    const msData = @json($byMarital);
    new Chart(document.getElementById('maritalChart'), {
        type: 'doughnut',
        data: { labels: msData.map(d => d.label.charAt(0).toUpperCase()+d.label.slice(1)), datasets: [{ data: msData.map(d => d.total), backgroundColor: COLORS.slice(2), borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom' } } }
    });

    // Age
    const ageData = @json($ageBuckets);
    new Chart(document.getElementById('ageChart'), {
        type: 'bar',
        data: { labels: Object.keys(ageData), datasets: [{ label: 'Employees', data: Object.values(ageData), backgroundColor: '#06b6d4', borderRadius: 4 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Department
    const dpData = @json($byDepartment);
    new Chart(document.getElementById('deptChart'), {
        type: 'bar',
        data: { labels: dpData.map(d => d.label), datasets: [{ label: 'Headcount', data: dpData.map(d => d.total), backgroundColor: '#2563eb', borderRadius: 4 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Tenure
    const tnData = @json($tenureBuckets);
    new Chart(document.getElementById('tenureChart'), {
        type: 'bar',
        data: { labels: Object.keys(tnData), datasets: [{ label: 'Employees', data: Object.values(tnData), backgroundColor: ['#dbeafe','#93c5fd','#60a5fa','#3b82f6','#1d4ed8'], borderRadius: 4 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
});
</script>
@endsection
