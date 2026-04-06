@extends('layouts.app')
@section('title', 'Salary History — ' . $employee->full_name)
@section('page-title', 'Salary Adjustment History')

@section('content')
<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>{{ $employee->full_name }} — Salary History</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th class="text-end">Previous (RM)</th>
                        <th class="text-end">New (RM)</th>
                        <th class="text-end">Change (RM)</th>
                        <th>Reason</th>
                        <th>Adjusted By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($adjustments as $adj)
                    <tr>
                        <td>{{ $adj->effective_date->format('d M Y') }}</td>
                        <td><span class="badge bg-info">{{ ucfirst($adj->type) }}</span></td>
                        <td class="text-end">{{ number_format($adj->previous_salary, 2) }}</td>
                        <td class="text-end">{{ number_format($adj->new_salary, 2) }}</td>
                        <td class="text-end {{ $adj->new_salary > $adj->previous_salary ? 'text-success' : 'text-danger' }}">
                            {{ ($adj->new_salary > $adj->previous_salary ? '+' : '') . number_format($adj->new_salary - $adj->previous_salary, 2) }}
                        </td>
                        <td>{{ $adj->reason ?? '—' }}</td>
                        <td>{{ $adj->adjustedBy->name ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No salary adjustments recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <a href="{{ route('hr.payroll.salaries') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Salary Setup</a>
</div>
@endsection
