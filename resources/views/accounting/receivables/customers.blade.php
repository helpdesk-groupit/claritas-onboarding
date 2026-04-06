@extends('layouts.app')
@section('title', 'Customers')
@section('page-title', 'Accounts Receivable — Customers')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2">
        <select name="company" class="form-select form-select-sm" style="width:200px;" onchange="this.form.submit()">
            <option value="">All Companies</option>
            @foreach($companies ?? [] as $key => $name)
                <option value="{{ $key }}" {{ request('company') == $key ? 'selected' : '' }}>{{ $name }}</option>
            @endforeach
        </select>
    </form>
    @if(Auth::user()->canManageAccounting())
    <a href="{{ route('accounting.customers.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Customer</a>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Code</th><th>Name</th><th>Email</th><th>Phone</th><th class="text-end">Outstanding</th><th class="text-center">Invoices</th><th></th></tr></thead>
            <tbody>
            @forelse($customers ?? [] as $c)
                <tr>
                    <td class="fw-semibold">{{ $c->customer_code }}</td>
                    <td>{{ $c->name }}</td>
                    <td>{{ $c->email ?? '-' }}</td>
                    <td>{{ $c->phone ?? '-' }}</td>
                    <td class="text-end {{ $c->outstanding_balance > 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format($c->outstanding_balance, 2) }}</td>
                    <td class="text-center">{{ $c->invoices_count ?? 0 }}</td>
                    <td class="text-end">
                        @if(Auth::user()->canManageAccounting())
                        <a href="{{ route('accounting.customers.edit', $c) }}" class="btn btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No customers yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($customers ?? collect(), 'links'))
<div class="mt-3">{{ $customers->withQueryString()->links() }}</div>
@endif
@endsection
