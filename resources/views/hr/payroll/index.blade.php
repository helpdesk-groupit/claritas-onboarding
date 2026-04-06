@extends('layouts.app')
@section('title', 'Pay Runs')
@section('page-title', 'Pay Runs')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Pay Runs</h5>
        <a href="{{ route('hr.payroll.pay-runs.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New Pay Run</a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Reference</th>
                        <th>Title</th>
                        <th>Period</th>
                        <th>Pay Date</th>
                        <th class="text-end">Net Pay (RM)</th>
                        <th class="text-center">Payslips</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($payRuns as $pr)
                    <tr>
                        <td><code>{{ $pr->reference }}</code></td>
                        <td>{{ $pr->title }}</td>
                        <td>{{ \Carbon\Carbon::parse($pr->period_start)->format('d M') }} — {{ \Carbon\Carbon::parse($pr->period_end)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($pr->pay_date)->format('d M Y') }}</td>
                        <td class="text-end fw-bold">{{ number_format($pr->total_net_pay, 2) }}</td>
                        <td class="text-center"><span class="badge bg-secondary">{{ $pr->payslips_count ?? $pr->payslips->count() }}</span></td>
                        <td class="text-center">{!! $pr->statusBadge() !!}</td>
                        <td>
                            <a href="{{ route('hr.payroll.pay-runs.show', $pr) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No pay runs found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $payRuns->links() }}</div>
    </div>
</div>
@endsection
