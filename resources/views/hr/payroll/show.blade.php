@extends('layouts.app')
@section('title', 'Pay Run — ' . $payRun->reference)
@section('page-title', $payRun->title)

@section('content')
<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <div class="text-muted small">Status</div>
                <div class="mt-1">{!! $payRun->statusBadge() !!}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <div class="text-muted small">Total Gross</div>
                <div class="h5 mb-0">RM {{ number_format($payRun->total_gross_pay, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <div class="text-muted small">Total Deductions</div>
                <div class="h5 mb-0 text-danger">RM {{ number_format($payRun->total_deductions, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <div class="text-muted small">Total Net Pay</div>
                <div class="h5 mb-0 text-success">RM {{ number_format($payRun->total_net_pay, 2) }}</div>
            </div>
        </div>
    </div>
</div>

<!-- Actions -->
<div class="card mb-4">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <strong>{{ $payRun->reference }}</strong> &middot;
            {{ \Carbon\Carbon::parse($payRun->period_start)->format('d M') }} — {{ \Carbon\Carbon::parse($payRun->period_end)->format('d M Y') }} &middot;
            Pay Date: {{ \Carbon\Carbon::parse($payRun->pay_date)->format('d M Y') }}
        </div>
        <div class="d-flex gap-2">
            @if($payRun->status === 'draft')
            <form method="POST" action="{{ route('hr.payroll.pay-runs.generate', $payRun) }}">
                @csrf
                <button class="btn btn-sm btn-success" onclick="return confirm('Generate payslips for all active employees?')"><i class="bi bi-gear me-1"></i>Generate Payslips</button>
            </form>
            @endif
            @if($payRun->status === 'processing')
            <form method="POST" action="{{ route('hr.payroll.pay-runs.approve', $payRun) }}">
                @csrf
                <button class="btn btn-sm btn-primary" onclick="return confirm('Approve this pay run?')"><i class="bi bi-check-lg me-1"></i>Approve</button>
            </form>
            @endif
            @if($payRun->status === 'approved')
            <form method="POST" action="{{ route('hr.payroll.pay-runs.mark-paid', $payRun) }}">
                @csrf
                <button class="btn btn-sm btn-success" onclick="return confirm('Mark as paid?')"><i class="bi bi-cash me-1"></i>Mark Paid</button>
            </form>
            @endif
        </div>
    </div>
</div>

<!-- Payslips Table -->
<div class="card">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Payslips ({{ $payRun->payslips->count() }})</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Payslip #</th>
                        <th>Employee</th>
                        <th class="text-end">Basic (RM)</th>
                        <th class="text-end">Earnings (RM)</th>
                        <th class="text-end">Deductions (RM)</th>
                        <th class="text-end">Net Pay (RM)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($payRun->payslips as $slip)
                    <tr>
                        <td><code>{{ $slip->payslip_number }}</code></td>
                        <td>{{ $slip->employee->full_name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($slip->basic_salary, 2) }}</td>
                        <td class="text-end text-success">{{ number_format($slip->total_earnings, 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($slip->total_deductions, 2) }}</td>
                        <td class="text-end fw-bold">{{ number_format($slip->net_pay, 2) }}</td>
                        <td><a href="{{ route('hr.payroll.payslip', $slip) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No payslips generated yet. Click "Generate Payslips" above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <a href="{{ route('hr.payroll.pay-runs.index') }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Pay Runs</a>
</div>
@endsection
