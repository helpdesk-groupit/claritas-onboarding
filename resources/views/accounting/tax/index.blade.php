@extends('layouts.app')
@section('title', 'Tax Codes')
@section('page-title', 'Tax Management')

@section('content')
@include('accounting.partials.nav')

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="{{ route('accounting.tax-returns.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>Tax Returns</a>
    @if(Auth::user()->canManageAccounting())
    <a href="{{ route('accounting.tax.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Tax Code</a>
    @endif
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:13px;">
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th class="text-end">Rate (%)</th><th class="text-center">Status</th><th></th></tr></thead>
            <tbody>
            @forelse($codes ?? [] as $tc)
                <tr>
                    <td class="fw-semibold">{{ $tc->code }}</td>
                    <td>{{ $tc->name }}</td>
                    <td>{{ ucfirst($tc->type) }}</td>
                    <td class="text-end">{{ number_format($tc->rate, 2) }}%</td>
                    <td class="text-center"><span class="badge bg-{{ $tc->is_active ? 'success' : 'secondary' }}">{{ $tc->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-end">
                        @if(Auth::user()->canManageAccounting())
                        <a href="{{ route('accounting.tax.edit', $tc) }}" class="btn btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-pencil"></i></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No tax codes configured</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
