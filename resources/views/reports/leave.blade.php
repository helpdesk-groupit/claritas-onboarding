@extends('layouts.app')
@section('title', 'Leave Report')
@section('page-title', 'Leave Analytics')

@push('styles')
<style>
    .chart-card { border: 1px solid #e2e8f0; border-radius: 12px; }
    .chart-card .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 13px; }
    .mini-table th { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; border-top: none; }
    .mini-table td { font-size: 13px; }
    .kpi-value { font-size: 28px; font-weight: 700; line-height: 1; color: #1e293b; }
    .kpi-label { font-size: 12px; color: #64748b; margin-top: 2px; }
    .utilization-bar { height: 8px; border-radius: 4px; background: #e2e8f0; overflow: hidden; }
    .utilization-bar .fill { height: 100%; border-radius: 4px; }
</style>
@endpush

@section('content')
@include('reports.partials.report-header')

{{-- KPI Row --}}
@php
    $totalDays = $leaveTrend ? collect($leaveTrend)->sum('days') : 0;
    $totalApps = collect($byType)->sum('count');
@endphp
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #ec4899;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($totalDays, 1) }}</div>
                <div class="kpi-label">Total Leave Days (Approved)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #2563eb;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($totalApps) }}</div>
                <div class="kpi-label">Approved Applications</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #f59e0b;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ count($byType) }}</div>
                <div class="kpi-label">Leave Types Used</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #10b981;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ count($byDepartment) }}</div>
                <div class="kpi-label">Departments with Leave</div>
            </div>
        </div>
    </div>
</div>

{{-- Leave Trend + By Type --}}
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-graph-up me-1"></i>Monthly Leave Days — {{ $year }}</div>
            <div class="card-body"><canvas id="leaveTrendChart" height="280"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-pie-chart me-1"></i>Leave by Type</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="leaveTypeChart" height="280"></canvas></div>
        </div>
    </div>
</div>

{{-- Balance Utilization --}}
<div class="row g-3 mb-4">
    <div class="col-lg-12">
        <div class="card chart-card">
            <div class="card-header py-2"><i class="bi bi-bar-chart-steps me-1"></i>Leave Balance Utilization — {{ $year }}</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead>
                        <tr><th>Leave Type</th><th class="text-end">Entitled</th><th class="text-end">Taken</th><th class="text-end">Carry Fwd</th><th class="text-end">Utilization</th><th style="width:200px;">Progress</th></tr>
                    </thead>
                    <tbody>
                    @forelse($balanceUtilization as $row)
                    @php $util = ($row->total_entitled ?? 0) > 0 ? round($row->total_taken / $row->total_entitled * 100, 1) : 0; @endphp
                    <tr>
                        <td class="fw-semibold">{{ $row->type_name }}</td>
                        <td class="text-end">{{ number_format($row->total_entitled, 1) }}</td>
                        <td class="text-end">{{ number_format($row->total_taken, 1) }}</td>
                        <td class="text-end">{{ number_format($row->total_cf, 1) }}</td>
                        <td class="text-end fw-semibold">{{ $util }}%</td>
                        <td>
                            <div class="utilization-bar">
                                <div class="fill" style="width:{{ min($util, 100) }}%;background:{{ $util > 80 ? '#ef4444' : ($util > 50 ? '#f59e0b' : '#10b981') }};"></div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-muted text-center">No balance data for {{ $year }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- By Department + Top Leave Takers --}}
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-building me-1"></i>Leave by Department</div>
            <div class="card-body"><canvas id="leaveDeptChart" height="300"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-person-lines-fill me-1"></i>Top 15 Leave Takers</div>
            <div class="card-body" style="max-height:400px;overflow-y:auto;">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>#</th><th>Employee</th><th>Department</th><th>Company</th><th class="text-end">Days</th></tr></thead>
                    <tbody>
                    @forelse($topLeaveTakers as $i => $row)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td class="fw-semibold">{{ $row->full_name }}</td>
                        <td>{{ $row->department }}</td>
                        <td>{{ $row->company }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row->total_days, 1) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-muted text-center">No data</td></tr>
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

    // Leave Trend
    const ltData = @json($leaveTrend);
    new Chart(document.getElementById('leaveTrendChart'), {
        type: 'line',
        data: { labels: ltData.map(d => d.month), datasets: [{ label: 'Leave Days', data: ltData.map(d => d.days), borderColor: '#ec4899', backgroundColor: 'rgba(236,72,153,0.1)', fill: true, tension: 0.3 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    // By Type
    const btData = @json($byType);
    if (btData.length > 0) {
        new Chart(document.getElementById('leaveTypeChart'), {
            type: 'doughnut',
            data: { labels: btData.map(d => d.type_name), datasets: [{ data: btData.map(d => d.total_days), backgroundColor: COLORS, borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '50%', plugins: { legend: { position: 'bottom', labels: { padding: 8, font: { size: 10 } } } } }
        });
    }

    // By Department
    const ldData = @json($byDepartment);
    new Chart(document.getElementById('leaveDeptChart'), {
        type: 'bar',
        data: { labels: ldData.map(d => d.dept), datasets: [{ label: 'Leave Days', data: ldData.map(d => d.total_days), backgroundColor: '#8b5cf6', borderRadius: 4 }] },
        options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
    });
});
</script>
@endsection
