@extends('layouts.app')
@section('title', 'Budgets')
@section('page-title', 'Budget Management')

@section('content')
@include('accounting.partials.nav')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Budgets</h6>
        <a href="{{ route('accounting.budgets.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Budget</a>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <thead><tr><th>Name</th><th>Fiscal Year</th><th>Company</th><th>Status</th><th>Total Budget (RM)</th><th></th></tr></thead>
            <tbody>
            @forelse($budgets ?? [] as $budget)
                <tr>
                    <td><a href="{{ route('accounting.budgets.show', $budget) }}">{{ $budget->name }}</a></td>
                    <td>{{ $budget->fiscalYear->name ?? '—' }}</td>
                    <td>{{ $budget->company ?? '—' }}</td>
                    <td><span class="badge bg-{{ $budget->status === 'approved' ? 'success' : ($budget->status === 'draft' ? 'warning' : 'secondary') }}">{{ ucfirst($budget->status) }}</span></td>
                    <td>{{ number_format($budget->lines->sum('total_amount') ?? 0, 2) }}</td>
                    <td>
                        <a href="{{ route('accounting.budgets.show', $budget) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        @if($budget->status === 'draft')
                        <a href="{{ route('accounting.budgets.edit', $budget) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">No budgets created yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
