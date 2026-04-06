@extends('layouts.app')
@section('title', 'Bills')
@section('page-title', 'Accounts Payable — Bills')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2">
        <select name="company" class="form-select form-select-sm" style="width:180px;" onchange="this.form.submit()">
            <option value="">All Companies</option>
            @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ request('company') == $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
        </select>
        <select name="status" class="form-select form-select-sm" style="width:130px;" onchange="this.form.submit()">
            <option value="">All Status</option>
            @foreach(['draft','received','paid','partial','overdue'] as $s)<option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>@endforeach
        </select>
    </form>
    @if(Auth::user()->canManageAccounting())
    <a href="{{ route('accounting.bills.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Bill</a>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Bill #</th><th>Vendor</th><th>Date</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Balance</th><th class="text-center">Status</th><th></th></tr></thead>
            <tbody>
            @forelse($bills ?? [] as $b)
                <tr>
                    <td class="fw-semibold">{{ $b->bill_number }}</td>
                    <td>{{ $b->vendor->name ?? '-' }}</td>
                    <td>{{ \Carbon\Carbon::parse($b->date)->format('d M Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($b->due_date)->format('d M Y') }}</td>
                    <td class="text-end">{{ number_format($b->total, 2) }}</td>
                    <td class="text-end {{ $b->balance_due > 0 ? 'text-danger' : '' }}">{{ number_format($b->balance_due, 2) }}</td>
                    <td class="text-center">
                        @if($b->status === 'paid')<span class="badge bg-success">Paid</span>
                        @elseif($b->status === 'received')<span class="badge bg-primary">Received</span>
                        @elseif($b->status === 'partial')<span class="badge bg-info">Partial</span>
                        @else<span class="badge bg-warning text-dark">{{ ucfirst($b->status) }}</span>@endif
                    </td>
                    <td class="text-end"><a href="{{ route('accounting.bills.show', $b) }}" class="btn btn-outline-primary btn-sm py-0 px-1"><i class="bi bi-eye"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No bills yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($bills ?? collect(), 'links'))
<div class="mt-3">{{ $bills->withQueryString()->links() }}</div>
@endif
@endsection
