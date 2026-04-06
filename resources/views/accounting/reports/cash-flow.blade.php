@extends('layouts.app')
@section('title', 'Cash Flow Statement')
@section('page-title', 'Cash Flow Statement')

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
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <tbody>
                <tr class="table-light fw-bold"><td colspan="2">Operating Activities</td></tr>
                @foreach($report['operating'] ?? [] as $item)
                <tr><td class="ps-4">{{ $item['label'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>Net Cash from Operating</td><td class="text-end">{{ number_format($report['operating_total'] ?? 0, 2) }}</td></tr>

                <tr class="table-light fw-bold"><td colspan="2">Investing Activities</td></tr>
                @foreach($report['investing'] ?? [] as $item)
                <tr><td class="ps-4">{{ $item['label'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>Net Cash from Investing</td><td class="text-end">{{ number_format($report['investing_total'] ?? 0, 2) }}</td></tr>

                <tr class="table-light fw-bold"><td colspan="2">Financing Activities</td></tr>
                @foreach($report['financing'] ?? [] as $item)
                <tr><td class="ps-4">{{ $item['label'] }}</td><td class="text-end">{{ number_format($item['amount'], 2) }}</td></tr>
                @endforeach
                <tr class="fw-bold"><td>Net Cash from Financing</td><td class="text-end">{{ number_format($report['financing_total'] ?? 0, 2) }}</td></tr>

                <tr class="table-primary fw-bold">
                    <td>Net Change in Cash</td>
                    <td class="text-end">{{ number_format(($report['operating_total'] ?? 0) + ($report['investing_total'] ?? 0) + ($report['financing_total'] ?? 0), 2) }}</td>
                </tr>
                <tr><td>Opening Cash Balance</td><td class="text-end">{{ number_format($report['opening_cash'] ?? 0, 2) }}</td></tr>
                <tr class="fw-bold table-success"><td>Closing Cash Balance</td><td class="text-end">{{ number_format($report['closing_cash'] ?? 0, 2) }}</td></tr>
            </tbody>
        </table>
    </div>
</div>
@endif
@endsection
