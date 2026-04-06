@extends('layouts.app')
@section('title', 'Fixed Assets')
@section('page-title', 'Fixed Assets')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-2">
        <a href="{{ route('accounting.asset-categories.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-folder me-1"></i>Categories</a>
        <form class="d-flex gap-2">
            <select name="status" class="form-select form-select-sm" style="width:150px;" onchange="this.form.submit()">
                <option value="">All Status</option>
                @foreach(['active','disposed','fully_depreciated'] as $s)<option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ',$s)) }}</option>@endforeach
            </select>
        </form>
    </div>
    @if(Auth::user()->canManageAccounting())
    <div class="d-flex gap-2">
        @if(Auth::user()->canApproveTransactions())
        <form method="POST" action="{{ route('accounting.fixed-assets.run-depreciation') }}" class="d-flex gap-1">
            @csrf
            <input type="month" name="run_month" class="form-control form-control-sm" value="{{ now()->format('Y-m') }}" style="width:150px;">
            <input type="hidden" name="company" value="{{ request('company') }}">
            <button class="btn btn-outline-warning btn-sm"><i class="bi bi-calculator me-1"></i>Run Depreciation</button>
        </form>
        @endif
        <a href="{{ route('accounting.fixed-assets.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Asset</a>
    </div>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Purchase Date</th><th class="text-end">Cost</th><th class="text-end">Current Value</th><th class="text-center">Status</th><th></th></tr></thead>
            <tbody>
            @forelse($assets ?? [] as $a)
                <tr>
                    <td class="fw-semibold">{{ $a->asset_code }}</td>
                    <td>{{ $a->name }}</td>
                    <td>{{ $a->category->name ?? '-' }}</td>
                    <td>{{ \Carbon\Carbon::parse($a->purchase_date)->format('d M Y') }}</td>
                    <td class="text-end">{{ number_format($a->purchase_cost, 2) }}</td>
                    <td class="text-end">{{ number_format($a->current_value, 2) }}</td>
                    <td class="text-center"><span class="badge bg-{{ $a->status === 'active' ? 'success' : ($a->status === 'disposed' ? 'danger' : 'secondary') }}">{{ ucwords(str_replace('_',' ',$a->status)) }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('accounting.fixed-assets.depreciation', $a) }}" class="btn btn-outline-primary btn-sm py-0 px-1" title="Schedule"><i class="bi bi-calendar3"></i></a>
                        @if(Auth::user()->canManageAccounting())
                        <a href="{{ route('accounting.fixed-assets.edit', $a) }}" class="btn btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No fixed assets yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($assets ?? collect(), 'links'))
<div class="mt-3">{{ $assets->withQueryString()->links() }}</div>
@endif
@endsection
