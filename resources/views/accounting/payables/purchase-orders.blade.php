@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('page-title', 'Accounts Payable — Purchase Orders')

@section('content')
@include('accounting.partials.nav')
<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>PO #</th><th>Vendor</th><th>Date</th><th class="text-end">Total</th><th class="text-center">Status</th></tr></thead>
            <tbody>
            @forelse($orders ?? [] as $o)
                <tr>
                    <td class="fw-semibold">{{ $o->po_number }}</td>
                    <td>{{ $o->vendor->name ?? '-' }}</td>
                    <td>{{ \Carbon\Carbon::parse($o->date)->format('d M Y') }}</td>
                    <td class="text-end">{{ number_format($o->total, 2) }}</td>
                    <td class="text-center"><span class="badge bg-{{ $o->status === 'approved' ? 'success' : ($o->status === 'draft' ? 'warning' : 'secondary') }}">{{ ucfirst($o->status) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No purchase orders yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
