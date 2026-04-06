@extends('layouts.app')
@section('title', 'General Ledger Report')
@section('page-title', 'General Ledger Report')

@section('content')
@include('accounting.partials.nav')
<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3"><label class="form-label">Account</label>
                <select name="account_id" class="form-select">
                    <option value="">All Accounts</option>
                    @foreach($accounts ?? [] as $acc)<option value="{{ $acc->id }}" {{ request('account_id') == $acc->id ? 'selected' : '' }}>{{ $acc->account_code }} — {{ $acc->name }}</option>@endforeach
                </select></div>
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
@if(isset($ledger))
@foreach($ledger as $accountLedger)
<div class="card mb-3">
    <div class="card-header"><h6 class="mb-0">{{ $accountLedger['account_code'] }} — {{ $accountLedger['account_name'] }}</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <thead><tr><th>Date</th><th>JE #</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            @php $bal = $accountLedger['opening_balance'] ?? 0; @endphp
            <tr class="table-light"><td colspan="5"><em>Opening Balance</em></td><td class="text-end fw-bold">{{ number_format($bal, 2) }}</td></tr>
            @foreach($accountLedger['entries'] ?? [] as $entry)
                @php $bal += $entry['debit'] - $entry['credit']; @endphp
                <tr>
                    <td>{{ $entry['date'] }}</td>
                    <td>{{ $entry['entry_number'] }}</td>
                    <td>{{ $entry['description'] }}</td>
                    <td class="text-end">{{ $entry['debit'] ? number_format($entry['debit'], 2) : '' }}</td>
                    <td class="text-end">{{ $entry['credit'] ? number_format($entry['credit'], 2) : '' }}</td>
                    <td class="text-end">{{ number_format($bal, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr class="fw-bold"><td colspan="5">Closing Balance</td><td class="text-end">{{ number_format($bal, 2) }}</td></tr></tfoot>
        </table>
    </div>
</div>
@endforeach
@endif
@endsection
