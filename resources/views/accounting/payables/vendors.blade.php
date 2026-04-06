@extends('layouts.app')
@section('title', 'Vendors')
@section('page-title', 'Accounts Payable — Vendors')

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
    <a href="{{ route('accounting.vendors.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Vendor</a>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Code</th><th>Name</th><th>Email</th><th>Phone</th><th class="text-center">Bills</th><th></th></tr></thead>
            <tbody>
            @forelse($vendors ?? [] as $v)
                <tr>
                    <td class="fw-semibold">{{ $v->vendor_code }}</td>
                    <td>{{ $v->name }}</td>
                    <td>{{ $v->email ?? '-' }}</td>
                    <td>{{ $v->phone ?? '-' }}</td>
                    <td class="text-center">{{ $v->bills_count ?? 0 }}</td>
                    <td class="text-end">
                        @if(Auth::user()->canManageAccounting())
                        <a href="{{ route('accounting.vendors.edit', $v) }}" class="btn btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No vendors yet</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@if(method_exists($vendors ?? collect(), 'links'))
<div class="mt-3">{{ $vendors->withQueryString()->links() }}</div>
@endif
@endsection
