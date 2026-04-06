@extends('layouts.app')
@section('title', 'Aged Payables')
@section('page-title', 'Aged Payables Report')

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
<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <thead><tr><th>Vendor</th><th class="text-end">Current</th><th class="text-end">1-30 Days</th><th class="text-end">31-60 Days</th><th class="text-end">61-90 Days</th><th class="text-end">90+ Days</th><th class="text-end">Total</th></tr></thead>
            <tbody>
            @php $totals = ['current'=>0,'d30'=>0,'d60'=>0,'d90'=>0,'over90'=>0,'total'=>0]; @endphp
            @foreach($report as $row)
                @php foreach(['current','d30','d60','d90','over90','total'] as $k) $totals[$k] += $row[$k] ?? 0; @endphp
                <tr>
                    <td>{{ $row['vendor'] }}</td>
                    <td class="text-end">{{ number_format($row['current'] ?? 0, 2) }}</td>
                    <td class="text-end">{{ number_format($row['d30'] ?? 0, 2) }}</td>
                    <td class="text-end">{{ number_format($row['d60'] ?? 0, 2) }}</td>
                    <td class="text-end">{{ number_format($row['d90'] ?? 0, 2) }}</td>
                    <td class="text-end {{ ($row['over90'] ?? 0) > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row['over90'] ?? 0, 2) }}</td>
                    <td class="text-end fw-bold">{{ number_format($row['total'] ?? 0, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold table-light">
                    <td>Total</td>
                    <td class="text-end">{{ number_format($totals['current'], 2) }}</td>
                    <td class="text-end">{{ number_format($totals['d30'], 2) }}</td>
                    <td class="text-end">{{ number_format($totals['d60'], 2) }}</td>
                    <td class="text-end">{{ number_format($totals['d90'], 2) }}</td>
                    <td class="text-end">{{ number_format($totals['over90'], 2) }}</td>
                    <td class="text-end">{{ number_format($totals['total'], 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endif
@endsection
