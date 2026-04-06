@extends('layouts.app')
@section('title', 'Asset Report')
@section('page-title', 'Asset Analytics')

@push('styles')
<style>
    .chart-card { border: 1px solid #e2e8f0; border-radius: 12px; }
    .chart-card .card-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; font-size: 13px; }
    .mini-table th { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; border-top: none; }
    .mini-table td { font-size: 13px; }
    .kpi-value { font-size: 24px; font-weight: 700; line-height: 1; color: #1e293b; }
    .kpi-label { font-size: 12px; color: #64748b; margin-top: 2px; }
    .alert-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
</style>
@endpush

@section('content')
@include('reports.partials.report-header')

{{-- KPI Row --}}
@php $totalAssets = collect($statusBreakdown)->sum('total'); @endphp
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card h-100" style="border-left:4px solid #2563eb;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($totalAssets) }}</div>
                <div class="kpi-label">Total Assets</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card h-100" style="border-left:4px solid #10b981;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($ownership['company_count']) }}</div>
                <div class="kpi-label">Company Owned</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card h-100" style="border-left:4px solid #f59e0b;">
            <div class="card-body p-3">
                <div class="kpi-value">{{ number_format($ownership['rental_count']) }}</div>
                <div class="kpi-label">Rental</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #8b5cf6;">
            <div class="card-body p-3">
                <div class="kpi-value">RM {{ number_format($ownership['company_cost'], 0) }}</div>
                <div class="kpi-label">Total Purchase Cost</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100" style="border-left:4px solid #ef4444;">
            <div class="card-body p-3">
                <div class="kpi-value">RM {{ number_format($ownership['rental_monthly'], 0) }}</div>
                <div class="kpi-label">Monthly Rental Cost</div>
            </div>
        </div>
    </div>
</div>

{{-- Status + Type Charts --}}
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-pie-chart me-1"></i>Asset Status</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="statusChart" height="260"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-laptop me-1"></i>Assets by Type</div>
            <div class="card-body"><canvas id="typeChart" height="260"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-tools me-1"></i>Asset Condition</div>
            <div class="card-body d-flex align-items-center justify-content-center"><canvas id="conditionChart" height="260"></canvas></div>
        </div>
    </div>
</div>

{{-- By Type Detailed --}}
<div class="row g-3 mb-4">
    <div class="col-lg-12">
        <div class="card chart-card">
            <div class="card-header py-2"><i class="bi bi-table me-1"></i>Asset Breakdown by Type</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Asset Type</th><th class="text-end">Total</th><th class="text-end">Available</th><th class="text-end">Assigned</th><th class="text-end">Utilization</th></tr></thead>
                    <tbody>
                    @foreach($byType as $row)
                    @php $util = $row->total > 0 ? round($row->assigned / $row->total * 100, 1) : 0; @endphp
                    <tr>
                        <td class="fw-semibold">{{ ucwords(str_replace('_', ' ', $row->asset_type)) }}</td>
                        <td class="text-end">{{ $row->total }}</td>
                        <td class="text-end text-success">{{ $row->available }}</td>
                        <td class="text-end text-primary">{{ $row->assigned }}</td>
                        <td class="text-end">
                            <span class="alert-badge" style="background:{{ $util > 80 ? '#fee2e2' : ($util > 50 ? '#fef3c7' : '#d1fae5') }};color:{{ $util > 80 ? '#dc2626' : ($util > 50 ? '#d97706' : '#059669') }};">
                                {{ $util }}%
                            </span>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Ownership Tables --}}
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-building me-1"></i>Company-Owned by Entity</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Company</th><th class="text-end">Count</th><th class="text-end">Purchase Cost (RM)</th></tr></thead>
                    <tbody>
                    @forelse($byCompanyOwned as $row)
                    <tr>
                        <td>{{ $row->label }}</td>
                        <td class="text-end">{{ $row->total }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row->cost, 0) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="text-muted text-center">No company-owned assets</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card chart-card h-100">
            <div class="card-header py-2"><i class="bi bi-truck me-1"></i>Rental by Vendor</div>
            <div class="card-body">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Vendor</th><th class="text-end">Count</th><th class="text-end">Monthly Cost (RM)</th></tr></thead>
                    <tbody>
                    @forelse($byRentalVendor as $row)
                    <tr>
                        <td>{{ $row->label }}</td>
                        <td class="text-end">{{ $row->total }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row->monthly_cost, 0) }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="3" class="text-muted text-center">No rental assets</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Expiring Warranties + Rental Contracts --}}
@if($warrantyExpiring->count() > 0 || $rentalExpiring->count() > 0)
<div class="row g-3 mb-4">
    @if($warrantyExpiring->count() > 0)
    <div class="col-lg-6">
        <div class="card chart-card h-100 border-warning">
            <div class="card-header py-2 text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Warranties Expiring (Next 90 Days) — {{ $warrantyExpiring->count() }}</div>
            <div class="card-body" style="max-height:300px;overflow-y:auto;">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Asset Tag</th><th>Type</th><th>Brand / Model</th><th>Expires</th></tr></thead>
                    <tbody>
                    @foreach($warrantyExpiring as $asset)
                    <tr>
                        <td class="fw-semibold">{{ $asset->asset_tag }}</td>
                        <td>{{ ucwords(str_replace('_',' ',$asset->asset_type)) }}</td>
                        <td>{{ $asset->brand }} {{ $asset->model }}</td>
                        <td>
                            <span class="alert-badge" style="background:#fef3c7;color:#d97706;">
                                {{ \Carbon\Carbon::parse($asset->warranty_expiry_date)->format('d M Y') }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif
    @if($rentalExpiring->count() > 0)
    <div class="col-lg-6">
        <div class="card chart-card h-100 border-danger">
            <div class="card-header py-2 text-danger"><i class="bi bi-exclamation-circle me-1"></i>Rental Contracts Expiring (Next 90 Days) — {{ $rentalExpiring->count() }}</div>
            <div class="card-body" style="max-height:300px;overflow-y:auto;">
                <table class="table table-sm mini-table mb-0">
                    <thead><tr><th>Asset Tag</th><th>Type</th><th>Vendor</th><th>Monthly (RM)</th><th>Expires</th></tr></thead>
                    <tbody>
                    @foreach($rentalExpiring as $asset)
                    <tr>
                        <td class="fw-semibold">{{ $asset->asset_tag }}</td>
                        <td>{{ ucwords(str_replace('_',' ',$asset->asset_type)) }}</td>
                        <td>{{ $asset->rental_vendor }}</td>
                        <td class="text-end">{{ number_format($asset->rental_cost_per_month, 0) }}</td>
                        <td>
                            <span class="alert-badge" style="background:#fee2e2;color:#dc2626;">
                                {{ \Carbon\Carbon::parse($asset->rental_end_date)->format('d M Y') }}
                            </span>
                        </td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif
</div>
@endif

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const COLORS = ['#10b981','#2563eb','#f59e0b','#ef4444','#8b5cf6','#94a3b8','#06b6d4','#ec4899'];
    const STATUS_COLORS = { available: '#10b981', assigned: '#2563eb', unavailable: '#6366f1', under_maintenance: '#f59e0b', disposed: '#94a3b8', returned: '#06b6d4' };
    Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    Chart.defaults.font.size = 11;

    // Status
    const sbData = @json($statusBreakdown);
    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: { labels: sbData.map(d => d.status.replace('_',' ').replace(/(^|\s)\S/g, l => l.toUpperCase())), datasets: [{ data: sbData.map(d => d.total), backgroundColor: sbData.map(d => STATUS_COLORS[d.status] || '#94a3b8'), borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '50%', plugins: { legend: { position: 'bottom', labels: { padding: 8, font: { size: 10 } } } } }
    });

    // By Type
    const btData = @json($byType);
    new Chart(document.getElementById('typeChart'), {
        type: 'bar',
        data: { labels: btData.map(d => d.asset_type.replace('_',' ').replace(/(^|\s)\S/g, l => l.toUpperCase())), datasets: [
            { label: 'Available', data: btData.map(d => d.available), backgroundColor: '#10b981', borderRadius: 4 },
            { label: 'Assigned', data: btData.map(d => d.assigned), backgroundColor: '#2563eb', borderRadius: 4 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    // Condition
    const cdData = @json($conditionBreakdown);
    new Chart(document.getElementById('conditionChart'), {
        type: 'doughnut',
        data: { labels: cdData.map(d => d.cond.replace(/(^|\s)\S/g, l => l.toUpperCase())), datasets: [{ data: cdData.map(d => d.total), backgroundColor: COLORS, borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '50%', plugins: { legend: { position: 'bottom', labels: { padding: 8, font: { size: 10 } } } } }
    });
});
</script>
@endsection
