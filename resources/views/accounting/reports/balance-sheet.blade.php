@extends('layouts.app')
@section('title', 'Balance Sheet')
@section('page-title', 'Balance Sheet')

@section('content')
@include('accounting.partials.nav')
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label">As At Date</label><input type="date" name="as_at" class="form-control" value="{{ request('as_at', now()->toDateString()) }}"></div>
            <div class="col-md-3"><label class="form-label">Company</label>
                <select name="company" class="form-select"><option value="">All</option>
                    @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ request('company') === $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
                </select></div>
            <div class="col-md-2"><button class="btn btn-primary">Generate</button></div>
        </form>
    </div>
</div>
@if(isset($report))
<div class="row g-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Assets</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <tbody>
                    @php $totalAssets = 0; @endphp
                    @foreach($report['assets'] ?? [] as $item)
                        @php $totalAssets += $item['amount']; @endphp
                        <tr><td>{{ $item['code'] }} — {{ $item['name'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="fw-bold table-primary"><td>Total Assets</td><td class="text-end">{{ number_format($totalAssets, 2) }}</td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0">Liabilities</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <tbody>
                    @php $totalLiabilities = 0; @endphp
                    @foreach($report['liabilities'] ?? [] as $item)
                        @php $totalLiabilities += $item['amount']; @endphp
                        <tr><td>{{ $item['code'] }} — {{ $item['name'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                    @endforeach
                    </tbody>
                    <tfoot><tr class="fw-bold"><td>Total Liabilities</td><td class="text-end">{{ number_format($totalLiabilities, 2) }}</td></tr></tfoot>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h6 class="mb-0">Equity</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:13px;">
                    <tbody>
                    @php $totalEquity = 0; @endphp
                    @foreach($report['equity'] ?? [] as $item)
                        @php $totalEquity += $item['amount']; @endphp
                        <tr><td>{{ $item['code'] }} — {{ $item['name'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                    @endforeach
                    @if(isset($report['retained_earnings']))
                        @php $totalEquity += $report['retained_earnings']; @endphp
                        <tr><td>Retained Earnings</td><td class="text-end">{{ number_format($report['retained_earnings'], 2) }}</td></tr>
                    @endif
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold"><td>Total Equity</td><td class="text-end">{{ number_format($totalEquity, 2) }}</td></tr>
                        <tr class="fw-bold table-primary"><td>Total Liabilities + Equity</td><td class="text-end">{{ number_format($totalLiabilities + $totalEquity, 2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@if(abs($totalAssets - ($totalLiabilities + $totalEquity)) > 0.01)
<div class="alert alert-danger mt-3"><i class="bi bi-exclamation-triangle me-1"></i>Balance sheet does not balance. Difference: RM {{ number_format(abs($totalAssets - ($totalLiabilities + $totalEquity)), 2) }}</div>
@endif
@endif
@endsection
