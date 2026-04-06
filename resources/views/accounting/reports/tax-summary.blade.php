@extends('layouts.app')
@section('title', 'Tax Summary')
@section('page-title', 'Tax Summary Report')

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
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-primary"><div class="card-body text-center">
            <div class="text-muted small">Output Tax (Sales)</div>
            <div class="fs-4 fw-bold text-primary">RM {{ number_format($report['total_output'] ?? 0, 2) }}</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-danger"><div class="card-body text-center">
            <div class="text-muted small">Input Tax (Purchases)</div>
            <div class="fs-4 fw-bold text-danger">RM {{ number_format($report['total_input'] ?? 0, 2) }}</div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-{{ ($report['net_tax'] ?? 0) >= 0 ? 'warning' : 'success' }}"><div class="card-body text-center">
            <div class="text-muted small">{{ ($report['net_tax'] ?? 0) >= 0 ? 'Tax Payable' : 'Tax Refundable' }}</div>
            <div class="fs-4 fw-bold">RM {{ number_format(abs($report['net_tax'] ?? 0), 2) }}</div>
        </div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Output Tax (Sales)</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <thead><tr><th>Tax Code</th><th>Rate</th><th class="text-end">Taxable Amount</th><th class="text-end">Tax Amount</th></tr></thead>
                    <tbody>
                    @foreach($report['output_lines'] ?? [] as $line)
                        <tr><td>{{ $line['code'] }}</td><td>{{ $line['rate'] }}%</td><td class="text-end">{{ number_format($line['taxable'], 2) }}</td><td class="text-end">{{ number_format($line['tax'], 2) }}</td></tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="fw-bold"><td colspan="2">Total</td><td class="text-end">{{ number_format($report['output_taxable'] ?? 0, 2) }}</td><td class="text-end">{{ number_format($report['total_output'] ?? 0, 2) }}</td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Input Tax (Purchases)</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <thead><tr><th>Tax Code</th><th>Rate</th><th class="text-end">Taxable Amount</th><th class="text-end">Tax Amount</th></tr></thead>
                    <tbody>
                    @foreach($report['input_lines'] ?? [] as $line)
                        <tr><td>{{ $line['code'] }}</td><td>{{ $line['rate'] }}%</td><td class="text-end">{{ number_format($line['taxable'], 2) }}</td><td class="text-end">{{ number_format($line['tax'], 2) }}</td></tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="fw-bold"><td colspan="2">Total</td><td class="text-end">{{ number_format($report['input_taxable'] ?? 0, 2) }}</td><td class="text-end">{{ number_format($report['total_input'] ?? 0, 2) }}</td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
@endsection
