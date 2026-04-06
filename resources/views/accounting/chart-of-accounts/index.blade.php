@extends('layouts.app')
@section('title', 'Chart of Accounts')
@section('page-title', 'Chart of Accounts')

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
    <a href="{{ route('accounting.chart-of-accounts.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Account</a>
    @endif
</div>

@foreach(['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'revenue' => 'Revenue', 'cogs' => 'Cost of Goods Sold', 'expense' => 'Expenses'] as $type => $label)
    @php $filtered = ($accounts ?? collect())->where('type', $type); @endphp
    @if($filtered->count())
    <div class="card mb-3">
        <div class="card-header bg-white py-2"><strong>{{ $label }}</strong> <span class="badge bg-secondary">{{ $filtered->count() }}</span></div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:13px;">
                <thead><tr><th>Code</th><th>Name</th><th>Sub-Type</th><th class="text-end">Balance</th><th class="text-center">Status</th><th></th></tr></thead>
                <tbody>
                @foreach($filtered->sortBy('account_code') as $acc)
                    <tr>
                        <td class="fw-semibold">{{ $acc->account_code }}</td>
                        <td>{{ str_repeat('— ', $acc->parent_id ? 1 : 0) }}{{ $acc->name }}</td>
                        <td>{{ $acc->sub_type ?? '-' }}</td>
                        <td class="text-end">{{ number_format($acc->balance, 2) }}</td>
                        <td class="text-center">
                            @if($acc->is_active)<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Inactive</span>@endif
                        </td>
                        <td class="text-end">
                            @if(Auth::user()->canManageAccounting())
                            <a href="{{ route('accounting.chart-of-accounts.edit', $acc) }}" class="btn btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
@endforeach
@endsection
