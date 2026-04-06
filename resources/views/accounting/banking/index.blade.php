@extends('layouts.app')
@section('title', 'Bank Accounts')
@section('page-title', 'Banking')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2">
        <select name="company" class="form-select form-select-sm" style="width:200px;" onchange="this.form.submit()">
            <option value="">All Companies</option>
            @foreach($companies ?? [] as $key => $name)<option value="{{ $key }}" {{ request('company') == $key ? 'selected' : '' }}>{{ $name }}</option>@endforeach
        </select>
    </form>
    @if(Auth::user()->canManageAccounting())
    <div class="d-flex gap-2">
        <a href="{{ route('accounting.bank-transfers.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Transfers</a>
        <a href="{{ route('accounting.banking.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Account</a>
    </div>
    @endif
</div>

<div class="row g-3">
@forelse($accounts ?? [] as $ba)
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="mb-0">{{ $ba->account_name }}</h6>
                        <small class="text-muted">{{ $ba->bank_name }} — {{ $ba->account_number }}</small>
                    </div>
                    <span class="badge bg-{{ $ba->is_active ? 'success' : 'secondary' }}">{{ $ba->is_active ? 'Active' : 'Inactive' }}</span>
                </div>
                <div class="fs-4 fw-bold mb-2">{{ $ba->currency }} {{ number_format($ba->current_balance, 2) }}</div>
                <div class="d-flex gap-1">
                    <a href="{{ route('accounting.banking.transactions', $ba) }}" class="btn btn-outline-primary btn-sm flex-fill"><i class="bi bi-list-ul me-1"></i>Transactions</a>
                    @if(Auth::user()->canManageAccounting())
                    <a href="{{ route('accounting.banking.reconciliation', $ba) }}" class="btn btn-outline-secondary btn-sm flex-fill"><i class="bi bi-check2-square me-1"></i>Reconcile</a>
                    <a href="{{ route('accounting.banking.edit', $ba) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i></a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@empty
    <div class="col-12"><div class="text-center text-muted py-5">No bank accounts configured</div></div>
@endforelse
</div>
@endsection
