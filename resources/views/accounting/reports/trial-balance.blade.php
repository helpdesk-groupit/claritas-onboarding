@extends('layouts.app')
@section('title', 'Trial Balance')
@section('page-title', 'Trial Balance')

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
    <div class="card-header"><h6 class="mb-0">Trial Balance as at {{ $report['as_at'] ?? request('as_at') }}</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <thead><tr><th>Account Code</th><th>Account Name</th><th class="text-end">Debit (RM)</th><th class="text-end">Credit (RM)</th></tr></thead>
            <tbody>
            @php $totalDebit = 0; $totalCredit = 0; @endphp
            @foreach($report['accounts'] ?? [] as $acc)
                @php $totalDebit += $acc['debit']; $totalCredit += $acc['credit']; @endphp
                <tr>
                    <td>{{ $acc['code'] }}</td><td>{{ $acc['name'] }}</td>
                    <td class="text-end">{{ $acc['debit'] ? number_format($acc['debit'], 2) : '' }}</td>
                    <td class="text-end">{{ $acc['credit'] ? number_format($acc['credit'], 2) : '' }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-bold"><td colspan="2">Total</td><td class="text-end">{{ number_format($totalDebit, 2) }}</td><td class="text-end">{{ number_format($totalCredit, 2) }}</td></tr>
                @if(abs($totalDebit - $totalCredit) > 0.01)
                <tr class="text-danger"><td colspan="2">Difference</td><td colspan="2" class="text-end">{{ number_format(abs($totalDebit - $totalCredit), 2) }}</td></tr>
                @endif
            </tfoot>
        </table>
    </div>
</div>
@endif
@endsection
