@extends('layouts.app')
@section('title', 'Bank Transactions — ' . $bankAccount->account_name)
@section('page-title', 'Bank Transactions — ' . $bankAccount->account_name)

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2">
        <input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}" style="width:150px;">
        <input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}" style="width:150px;">
        <button class="btn btn-sm btn-outline-primary">Filter</button>
    </form>
    <div>
        <span class="fw-bold">Balance: {{ $bankAccount->currency }} {{ number_format($bankAccount->current_balance, 2) }}</span>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-center">Reconciled</th></tr></thead>
            <tbody>
            @forelse($transactions ?? [] as $tx)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($tx->date)->format('d M Y') }}</td>
                    <td>{{ $tx->description }}</td>
                    <td>{{ $tx->reference ?? '-' }}</td>
                    <td class="text-end">{{ $tx->debit > 0 ? number_format($tx->debit, 2) : '' }}</td>
                    <td class="text-end">{{ $tx->credit > 0 ? number_format($tx->credit, 2) : '' }}</td>
                    <td class="text-center">@if($tx->is_reconciled)<i class="bi bi-check-circle text-success"></i>@else<i class="bi bi-circle text-muted"></i>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No transactions</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($transactions ?? collect(), 'links'))
<div class="mt-3">{{ $transactions->withQueryString()->links() }}</div>
@endif
@endsection
