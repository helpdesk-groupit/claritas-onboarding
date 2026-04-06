@extends('layouts.app')
@section('title', 'Financial Report')
@section('page-title', 'Financial Analytics')

@push('styles')
<style>
    .chart-card { border: 1px solid #e2e8f0; border-radius: 12px; }
    .chart-card .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 13px; }
    .mini-table th { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; border-top: none; }
    .mini-table td { font-size: 13px; }
    .kpi-value { font-size: 24px; font-weight: 700; line-height: 1; color: #1e293b; }
    .kpi-label { font-size: 12px; color: #64748b; margin-top: 2px; }
</style>
@endpush

@section('content')
@include('reports.partials.report-header')

{{-- KPI Row --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #8b5cf6;">
            <div class="card-body p-3">
                <div class="kpi-value">RM {{ number_format($ytdGross, 0) }}</div>
                <div class="kpi-label">YTD Gross Payroll</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #2563eb;">
            <div class="card-body p-3">
                <div class="kpi-value">RM {{ number_format($ytdNet, 0) }}</div>
                <div class="kpi-label">YTD Net Payroll</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #ef4444;">
            <div class="card-body p-3">
                <div class="kpi-value">RM {{ number_format($ytdEmployerCost, 0) }}</div>
                <div class="kpi-label">YTD Employer Cost</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #10b981;">
            <div class="card-body p-3">
                @php $totalClaimsApproved = collect($claimsTrend)->sum('amount'); @endphp
                <div class="kpi-value">RM {{ number_format($totalClaimsApproved, 0) }}</div>
                <div class="kpi-label">YTD Claims Paid</div>
            </div>
        </div>
    </div>
</div>

{{-- Payroll Trend --}}
<div class="row g-3 mb-4">
    <div class="col-lg-12">
        <div class="card chart-card">
            <div class="card-header py-2"><i class="bi bi-bar-chart me-1"></i>Monthly Payroll — {{ $year }}</div>
            <div class="card-body"><canvas id="payrollTrendChart" height="300"></canvas></div>
        </div>
    </div>
</div>

{{-- Statutory Trend + Claims Trend --}}
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-bank me-1"></i>Monthly Statutory Contributions — {{ $year }}</div>
            <div class="card-body"><canvas id="statutoryChart" height="300"></canvas></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-receipt me-1"></i>Monthly Claims (Approved) — {{ $year }}</div>
            <div class="card-body"><canvas id="claimsTrendChart" height="300"></canvas></div>
        </div>
    </div>
</div>

{{-- Salary by Department + Claims by Category --}}
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-building me-1"></i>Salary Distribution by Department</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th class="text-end">Headcount</th>
                            <th class="text-end">Avg Salary</th>
                            <th class="text-end">Min</th>
                            <th class="text-end">Max</th>
                            <th class="text-end">Total Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($salaryByDept as $row)
                    <tr>
                        <td>{{ $row->dept }}</td>
                        <td class="text-end">{{ $row->headcount }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row->avg_salary, 0) }}</td>
                        <td class="text-end">{{ number_format($row->min_salary, 0) }}</td>
                        <td class="text-end">{{ number_format($row->max_salary, 0) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row->total_salary, 0) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-muted text-center">No salary data</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-pie-chart me-1"></i>Claims by Category</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="claimsByCatChart" height="280"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- Top Earners --}}
<div class="row g-3 mb-4">
    <div class="col-lg-12">
        <div class="card chart-card">
            <div class="card-header py-2"><i class="bi bi-trophy me-1"></i>Top 10 Earners (by Basic Salary)</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead>
                        <tr><th>#</th><th>Name</th><th>Designation</th><th>Department</th><th>Company</th><th class="text-end">Basic Salary (RM)</th></tr>
                    </thead>
                    <tbody>
                    @forelse($topEarners as $i => $emp)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td class="fw-semibold">{{ $emp->full_name }}</td>
                        <td>{{ $emp->designation }}</td>
                        <td>{{ $emp->department }}</td>
                        <td>{{ $emp->company }}</td>
                        <td class="text-end fw-semibold">{{ number_format($emp->basic_salary, 2) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-muted text-center">No salary data</td></tr>
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

    // Payroll Trend (stacked: gross, deductions, net)
    const prData = @json($payrollTrend);
    new Chart(document.getElementById('payrollTrendChart'), {
        type: 'bar',
        data: {
            labels: prData.map(d => d.month),
            datasets: [
                { label: 'Gross', data: prData.map(d => d.gross), backgroundColor: '#8b5cf6', borderRadius: 4, stack: 'a' },
                { label: 'Net Pay', data: prData.map(d => d.net), backgroundColor: '#2563eb', borderRadius: 4, stack: 'b' },
                { label: 'Employer Cost', data: prData.map(d => d.employer_cost), backgroundColor: '#ef4444', borderRadius: 4, stack: 'c' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => 'RM ' + v.toLocaleString() } } } }
    });

    // Statutory Trend (stacked area)
    const stData = @json($statutoryTrend);
    new Chart(document.getElementById('statutoryChart'), {
        type: 'line',
        data: {
            labels: stData.map(d => d.month),
            datasets: [
                { label: 'EPF', data: stData.map(d => d.epf), borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.1)', fill: true, tension: 0.3 },
                { label: 'SOCSO', data: stData.map(d => d.socso), borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.1)', fill: true, tension: 0.3 },
                { label: 'EIS', data: stData.map(d => d.eis), borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.1)', fill: true, tension: 0.3 },
                { label: 'PCB', data: stData.map(d => d.pcb), borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,0.1)', fill: true, tension: 0.3 },
                { label: 'HRDF', data: stData.map(d => d.hrdf), borderColor: '#8b5cf6', backgroundColor: 'rgba(139,92,246,0.1)', fill: true, tension: 0.3 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: v => 'RM ' + v.toLocaleString() } } } }
    });

    // Claims Trend
    const clData = @json($claimsTrend);
    new Chart(document.getElementById('claimsTrendChart'), {
        type: 'bar',
        data: {
            labels: clData.map(d => d.month),
            datasets: [{ label: 'Claims (RM)', data: clData.map(d => d.amount), backgroundColor: '#10b981', borderRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => 'RM ' + v.toLocaleString() } } } }
    });

    // Claims by Category
    const ccData = @json($claimsByCategory);
    if (ccData.length > 0) {
        new Chart(document.getElementById('claimsByCatChart'), {
            type: 'doughnut',
            data: { labels: ccData.map(d => d.category), datasets: [{ data: ccData.map(d => d.total), backgroundColor: COLORS, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '50%', plugins: { legend: { position: 'bottom', labels: { padding: 8, font: { size: 10 } } } } }
        });
    }
});
</script>
@endsection
