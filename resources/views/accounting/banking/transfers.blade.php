@extends('layouts.app')
@section('title', 'Bank Transfers')
@section('page-title', 'Bank Transfers')

@section('content')
@include('accounting.partials.nav')

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Date</th><th>From</th><th>To</th><th class="text-end">Amount</th><th>Reference</th></tr></thead>
            <tbody>
            @forelse($transfers ?? [] as $t)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($t->date)->format('d M Y') }}</td>
                    <td>{{ $t->fromAccount->account_name ?? '-' }}</td>
                    <td>{{ $t->toAccount->account_name ?? '-' }}</td>
                    <td class="text-end">{{ number_format($t->amount, 2) }}</td>
                    <td>{{ $t->reference ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No transfers yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
