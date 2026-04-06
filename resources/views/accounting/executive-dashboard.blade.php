@extends('layouts.app')
@section('title', 'Executive Financial Dashboard')
@section('page-title', 'Executive Financial Dashboard')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-end mb-3">
    <form class="d-flex gap-2">
        <select name="company" class="form-select form-select-sm" style="width:200px;" onchange="this.form.submit()">
            <option value="">All Companies</option>
            @foreach($companies ?? [] as $key => $name)
                <option value="{{ $key }}" {{ request('company') == $key ? 'selected' : '' }}>{{ $name }}</option>
            @endforeach
        </select>
    </form>
</div>

{{-- Financial Ratios --}}
<div class="row g-3 mb-4">
    <div class="col-md-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">Current Ratio</div>
                <div class="fs-3 fw-bold {{ ($ratios['currentRatio'] ?? 0) >= 1 ? 'text-success' : 'text-danger' }}">
                    {{ number_format($ratios['currentRatio'] ?? 0, 2) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">Profit Margin</div>
                <div class="fs-3 fw-bold {{ ($ratios['profitMargin'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ number_format(($ratios['profitMargin'] ?? 0) * 100, 1) }}%
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">YTD Revenue</div>
                <div class="fs-5 fw-bold text-primary">RM {{ number_format($ytdRevenue ?? 0, 0) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">YTD Expenses</div>
                <div class="fs-5 fw-bold text-danger">RM {{ number_format($ytdExpenses ?? 0, 0) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">YTD Net Profit</div>
                <div class="fs-5 fw-bold {{ ($ytdNetProfit ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                    RM {{ number_format($ytdNetProfit ?? 0, 0) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card text-center h-100">
            <div class="card-body">
                <div class="text-muted small">Cash Position</div>
                <div class="fs-5 fw-bold">RM {{ number_format($cashPosition ?? 0, 0) }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Charts Row --}}
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white"><strong>Monthly Revenue vs Expenses (12 Months)</strong></div>
            <div class="card-body"><canvas id="monthlyTrend" height="100"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white"><strong>Expense Breakdown</strong></div>
            <div class="card-body"><canvas id="expenseBreakdown" height="200"></canvas></div>
        </div>
    </div>
</div>

{{-- Aged AR & AP --}}
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white"><strong>Aged Receivables</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <thead><tr><th>Customer</th><th class="text-end">Current</th><th class="text-end">31-60</th><th class="text-end">61-90</th><th class="text-end">90+</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    @forelse($agedReceivables ?? [] as $ar)
                        <tr>
                            <td>{{ $ar['customer'] }}</td>
                            <td class="text-end">{{ number_format($ar['current'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($ar['31_60'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($ar['61_90'] ?? 0, 2) }}</td>
                            <td class="text-end text-danger">{{ number_format($ar['over_90'] ?? 0, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($ar['total'] ?? 0, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No outstanding receivables</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header bg-white"><strong>Aged Payables</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <thead><tr><th>Vendor</th><th class="text-end">Current</th><th class="text-end">31-60</th><th class="text-end">61-90</th><th class="text-end">90+</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    @forelse($agedPayables ?? [] as $ap)
                        <tr>
                            <td>{{ $ap['vendor'] }}</td>
                            <td class="text-end">{{ number_format($ap['current'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($ap['31_60'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($ap['61_90'] ?? 0, 2) }}</td>
                            <td class="text-end text-danger">{{ number_format($ap['over_90'] ?? 0, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($ap['total'] ?? 0, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-3">No outstanding payables</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Balance Sheet Summary --}}
<div class="row g-3">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><strong>Balance Sheet - Assets</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between"><span>Total Assets</span><strong>RM {{ number_format($balanceSheet['totalAssets'] ?? 0, 2) }}</strong></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><strong>Balance Sheet - Liabilities</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between"><span>Total Liabilities</span><strong>RM {{ number_format($balanceSheet['totalLiabilities'] ?? 0, 2) }}</strong></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-white"><strong>Balance Sheet - Equity</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between"><span>Total Equity</span><strong>RM {{ number_format($balanceSheet['totalEquity'] ?? 0, 2) }}</strong></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const trend = @json($monthlyTrend ?? []);
    new Chart(document.getElementById('monthlyTrend'), {
        type: 'line',
        data: {
            labels: trend.map(t => t.month),
            datasets: [
                { label: 'Revenue', data: trend.map(t => t.revenue), borderColor: '#22c55e', fill: false, tension: 0.3 },
                { label: 'Expenses', data: trend.map(t => t.expenses), borderColor: '#ef4444', fill: false, tension: 0.3 },
                { label: 'Net Profit', data: trend.map(t => t.revenue - t.expenses), borderColor: '#3b82f6', borderDash: [5,5], fill: false, tension: 0.3 }
            ]
        },
        options: { responsive: true, scales: { y: { ticks: { callback: v => 'RM ' + v.toLocaleString() } } } }
    });

    const expData = @json($expenseBreakdown ?? []);
    if (expData.length) {
        new Chart(document.getElementById('expenseBreakdown'), {
            type: 'doughnut',
            data: {
                labels: expData.map(e => e.name),
                datasets: [{ data: expData.map(e => e.amount), backgroundColor: ['#3b82f6','#ef4444','#f59e0b','#22c55e','#8b5cf6','#ec4899','#14b8a6','#f97316'] }]
            },
            options: { responsive: true, plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } }
        });
    }
});
</script>
@endpush
