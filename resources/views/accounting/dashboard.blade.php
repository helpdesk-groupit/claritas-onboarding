@extends('layouts.app')
@section('title', 'Accounting Dashboard')
@section('page-title', 'Accounting Dashboard')

@section('content')
@include('accounting.partials.nav')

{{-- Company Filter --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <div></div>
    <form class="d-flex gap-2">
        <select name="company" class="form-select form-select-sm" style="width:200px;" onchange="this.form.submit()">
            <option value="">All Companies</option>
            @foreach($companies ?? [] as $key => $name)
                <option value="{{ $key }}" {{ request('company') == $key ? 'selected' : '' }}>{{ $name }}</option>
            @endforeach
        </select>
    </form>
</div>

{{-- KPI Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Monthly Revenue</div>
                <div class="fs-4 fw-bold text-success">RM {{ number_format($monthlyRevenue ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Monthly Expenses</div>
                <div class="fs-4 fw-bold text-danger">RM {{ number_format($monthlyExpenses ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Receivable Outstanding</div>
                <div class="fs-4 fw-bold text-primary">RM {{ number_format($totalReceivable ?? 0, 2) }}</div>
                @if(($overdueInvoices ?? 0) > 0)
                    <span class="badge bg-danger">{{ $overdueInvoices }} overdue</span>
                @endif
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Payable Outstanding</div>
                <div class="fs-4 fw-bold text-warning">RM {{ number_format($totalPayable ?? 0, 2) }}</div>
                @if(($overdueBills ?? 0) > 0)
                    <span class="badge bg-danger">{{ $overdueBills }} overdue</span>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Net Profit (YTD)</div>
                <div class="fs-4 fw-bold {{ ($netProfit ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                    RM {{ number_format($netProfit ?? 0, 2) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Bank Balances</div>
                <div class="fs-4 fw-bold">RM {{ number_format($totalBankBalance ?? 0, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Pending Invoices</div>
                <div class="fs-4 fw-bold">{{ $pendingInvoices ?? 0 }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Pending Bills</div>
                <div class="fs-4 fw-bold">{{ $pendingBills ?? 0 }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Revenue Trend Chart --}}
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white"><strong>12-Month Revenue vs Expenses</strong></div>
            <div class="card-body">
                <canvas id="revenueTrendChart" height="120"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header bg-white"><strong>Recent Invoices</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:13px;">
                    <thead><tr><th>Invoice</th><th>Customer</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @forelse($recentInvoices ?? [] as $inv)
                        <tr>
                            <td>{{ $inv->invoice_number }}</td>
                            <td>{{ $inv->customer->name ?? '-' }}</td>
                            <td class="text-end">{{ number_format($inv->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted text-center py-3">No invoices yet</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white"><strong>Recent Bills</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:13px;">
                    <thead><tr><th>Bill #</th><th>Vendor</th><th>Due</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    @forelse($recentBills ?? [] as $bill)
                        <tr>
                            <td>{{ $bill->bill_number }}</td>
                            <td>{{ $bill->vendor->name ?? '-' }}</td>
                            <td>{{ \Carbon\Carbon::parse($bill->due_date)->format('d M') }}</td>
                            <td class="text-end">{{ number_format($bill->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center py-3">No bills yet</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-white"><strong>Bank Accounts</strong></div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:13px;">
                    <thead><tr><th>Bank</th><th>Account</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                    @forelse($bankAccounts ?? [] as $ba)
                        <tr>
                            <td>{{ $ba->bank_name }}</td>
                            <td>{{ $ba->account_name }}</td>
                            <td class="text-end fw-semibold">{{ number_format($ba->current_balance, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted text-center py-3">No bank accounts</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const trend = @json($revenueTrend ?? []);
    const labels = trend.map(t => t.month);
    const rev = trend.map(t => t.revenue);
    const exp = trend.map(t => t.expenses);

    new Chart(document.getElementById('revenueTrendChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label: 'Revenue', data: rev, backgroundColor: 'rgba(34,197,94,.6)' },
                { label: 'Expenses', data: exp, backgroundColor: 'rgba(239,68,68,.5)' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top' } },
            scales: { y: { beginAtZero: true, ticks: { callback: v => 'RM ' + v.toLocaleString() } } }
        }
    });
});
</script>
@endpush
