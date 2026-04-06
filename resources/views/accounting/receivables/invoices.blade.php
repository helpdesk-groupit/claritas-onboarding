@extends('layouts.app')
@section('title', 'Sales Invoices')
@section('page-title', 'Accounts Receivable — Invoices')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2">
        <select name="company" class="form-select form-select-sm" style="width:180px;" onchange="this.form.submit()">
            <option value="">All Companies</option>
            @foreach($companies ?? [] as $key => $name)
                <option value="{{ $key }}" {{ request('company') == $key ? 'selected' : '' }}>{{ $name }}</option>
            @endforeach
        </select>
        <select name="status" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
            <option value="">All Status</option>
            @foreach(['draft','sent','paid','partial','overdue','voided'] as $s)
                <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </form>
    @if(Auth::user()->canManageAccounting())
    <a href="{{ route('accounting.invoices.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Invoice</a>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Invoice #</th><th>Customer</th><th>Date</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Balance</th><th class="text-center">Status</th><th></th></tr></thead>
            <tbody>
            @forelse($invoices ?? [] as $inv)
                <tr>
                    <td class="fw-semibold">{{ $inv->invoice_number }}</td>
                    <td>{{ $inv->customer->name ?? '-' }}</td>
                    <td>{{ \Carbon\Carbon::parse($inv->date)->format('d M Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($inv->due_date)->format('d M Y') }}</td>
                    <td class="text-end">{{ number_format($inv->total, 2) }}</td>
                    <td class="text-end {{ $inv->balance_due > 0 ? 'text-danger' : '' }}">{{ number_format($inv->balance_due, 2) }}</td>
                    <td class="text-center">
                        @if($inv->status === 'paid')<span class="badge bg-success">Paid</span>
                        @elseif($inv->status === 'overdue')<span class="badge bg-danger">Overdue</span>
                        @elseif($inv->status === 'partial')<span class="badge bg-info">Partial</span>
                        @elseif($inv->status === 'sent')<span class="badge bg-primary">Sent</span>
                        @elseif($inv->status === 'voided')<span class="badge bg-secondary">Voided</span>
                        @else<span class="badge bg-warning text-dark">Draft</span>@endif
                    </td>
                    <td class="text-end"><a href="{{ route('accounting.invoices.show', $inv) }}" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-eye"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No invoices yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($invoices ?? collect(), 'links'))
<div class="mt-3">{{ $invoices->withQueryString()->links() }}</div>
@endif
@endsection
