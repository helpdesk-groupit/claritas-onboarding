@extends('layouts.app')
@section('title', 'Profit & Loss')
@section('page-title', 'Profit & Loss Statement')

@section('content')
@include('accounting.partials.nav')
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="{{ request('from', now()->startOfMonth()->toDateString()) }}"></div>
            <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="{{ request('to', now()->toDateString()) }}"></div>
            <div class="col-md-3"><label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">All</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ request('company') === $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                </select></div>
            <div class="col-md-2"><button class="btn btn-primary">Generate</button></div>
        </form>
    </div>
</div>
@if(isset($report))
<div class="card">
    <div class="card-header"><h6 class="mb-0">P&L: {{ $report['from'] ?? request('from') }} → {{ $report['to'] ?? request('to') }}</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <tbody>
                <tr class="table-light"><td colspan="2"><strong>Revenue</strong></td></tr>
                @php $totalRevenue = 0; @endphp
                @foreach($report['revenue'] ?? [] as $item)
                    @php $totalRevenue += $item['amount']; @endphp
                    <tr><td class="ps-4">{{ $item['code'] }} — {{ $item['name'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>Total Revenue</td><td class="text-end">{{ number_format($totalRevenue, 2) }}</td></tr>

                <tr class="table-light"><td colspan="2"><strong>Cost of Goods Sold</strong></td></tr>
                @php $totalCogs = 0; @endphp
                @foreach($report['cogs'] ?? [] as $item)
                    @php $totalCogs += $item['amount']; @endphp
                    <tr><td class="ps-4">{{ $item['code'] }} — {{ $item['name'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>Total COGS</td><td class="text-end">{{ number_format($totalCogs, 2) }}</td></tr>

                <tr class="fw-bold table-info"><td>Gross Profit</td><td class="text-end">{{ number_format($totalRevenue - $totalCogs, 2) }}</td></tr>

                <tr class="table-light"><td colspan="2"><strong>Operating Expenses</strong></td></tr>
                @php $totalExpenses = 0; @endphp
                @foreach($report['expenses'] ?? [] as $item)
                    @php $totalExpenses += $item['amount']; @endphp
                    <tr><td class="ps-4">{{ $item['code'] }} — {{ $item['name'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>Total Expenses</td><td class="text-end">{{ number_format($totalExpenses, 2) }}</td></tr>

                <tr class="fw-bold table-{{ ($totalRevenue - $totalCogs - $totalExpenses) >= 0 ? 'success' : 'danger' }}">
                    <td>Net Profit / (Loss)</td>
                    <td class="text-end">{{ number_format($totalRevenue - $totalCogs - $totalExpenses, 2) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
