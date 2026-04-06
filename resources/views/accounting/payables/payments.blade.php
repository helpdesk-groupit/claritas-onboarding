@extends('layouts.app')
@section('title', 'Vendor Payments')
@section('page-title', 'Accounts Payable — Payments')

@section('content')
@include('accounting.partials.nav')
<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Payment #</th><th>Vendor</th><th>Date</th><th>Method</th><th class="text-end">Amount</th><th>Reference</th></tr></thead>
            <tbody>
            @forelse($payments ?? [] as $p)
                <tr>
                    <td class="fw-semibold">{{ $p->payment_number }}</td>
                    <td>{{ $p->vendor->name ?? '-' }}</td>
                    <td>{{ \Carbon\Carbon::parse($p->date)->format('d M Y') }}</td>
                    <td>{{ ucwords(str_replace('_',' ',$p->payment_method)) }}</td>
                    <td class="text-end">{{ number_format($p->amount, 2) }}</td>
                    <td>{{ $p->reference ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No payments yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($payments ?? collect(), 'links'))
<div class="mt-3">{{ $payments->withQueryString()->links() }}</div>
@endif
@endsection
