@extends('layouts.app')
@section('title', 'Payslip — ' . $payslip->payslip_number)
@section('page-title', 'Payslip Detail')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>{{ $payslip->payslip_number }}</h5>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print</button>
    </div>
    <div class="card-body">
        <div class="row mb-4">
            <div class="col-md-6">
                <h6 class="text-muted">Employee</h6>
                <p class="mb-1 fw-bold">{{ $payslip->employee->full_name ?? '—' }}</p>
                <p class="mb-0 text-muted">{{ $payslip->employee->department ?? '' }} &middot; {{ $payslip->employee->designation ?? '' }}</p>
            </div>
            <div class="col-md-6 text-md-end">
                <h6 class="text-muted">Pay Period</h6>
                <p class="mb-1">{{ \Carbon\Carbon::parse($payslip->payRun->period_start)->format('d M Y') }} — {{ \Carbon\Carbon::parse($payslip->payRun->period_end)->format('d M Y') }}</p>
                <p class="mb-0 text-muted">Pay Date: {{ \Carbon\Carbon::parse($payslip->payRun->pay_date)->format('d M Y') }}</p>
            </div>
        </div>

        <div class="row">
            <!-- Earnings -->
            <div class="col-md-6">
                <div class="card border-success mb-3">
                    <div class="card-header bg-success bg-opacity-10"><strong>Earnings</strong></div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr><td>Basic Salary</td><td class="text-end">{{ number_format($payslip->basic_salary, 2) }}</td></tr>
                                @foreach($payslip->items->where('type', 'earning') as $item)
                                <tr><td>{{ $item->description }}</td><td class="text-end">{{ number_format($item->amount, 2) }}</td></tr>
                                @endforeach
                                @if($payslip->overtime > 0)
                                <tr><td>Overtime</td><td class="text-end">{{ number_format($payslip->overtime, 2) }}</td></tr>
                                @endif
                            </tbody>
                            <tfoot class="table-success">
                                <tr><td class="fw-bold">Total Earnings</td><td class="text-end fw-bold">RM {{ number_format($payslip->total_earnings, 2) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Deductions -->
            <div class="col-md-6">
                <div class="card border-danger mb-3">
                    <div class="card-header bg-danger bg-opacity-10"><strong>Deductions</strong></div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                                @if($payslip->epf_employee > 0)
                                <tr><td>EPF (Employee)</td><td class="text-end">{{ number_format($payslip->epf_employee, 2) }}</td></tr>
                                @endif
                                @if($payslip->socso_employee > 0)
                                <tr><td>SOCSO (Employee)</td><td class="text-end">{{ number_format($payslip->socso_employee, 2) }}</td></tr>
                                @endif
                                @if($payslip->eis_employee > 0)
                                <tr><td>EIS (Employee)</td><td class="text-end">{{ number_format($payslip->eis_employee, 2) }}</td></tr>
                                @endif
                                @if($payslip->pcb > 0)
                                <tr><td>PCB / MTD</td><td class="text-end">{{ number_format($payslip->pcb, 2) }}</td></tr>
                                @endif
                                @if($payslip->unpaid_leave > 0)
                                <tr><td>Unpaid Leave Deduction</td><td class="text-end">{{ number_format($payslip->unpaid_leave, 2) }}</td></tr>
                                @endif
                                @foreach($payslip->items->where('type', 'deduction') as $item)
                                <tr><td>{{ $item->description }}</td><td class="text-end">{{ number_format($item->amount, 2) }}</td></tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-danger">
                                <tr><td class="fw-bold">Total Deductions</td><td class="text-end fw-bold">RM {{ number_format($payslip->total_deductions, 2) }}</td></tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employer Contributions -->
        <div class="card border-info mb-3">
            <div class="card-header bg-info bg-opacity-10"><strong>Employer Contributions</strong></div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col"><small class="text-muted d-block">EPF</small><strong>RM {{ number_format($payslip->epf_employer, 2) }}</strong></div>
                    <div class="col"><small class="text-muted d-block">SOCSO</small><strong>RM {{ number_format($payslip->socso_employer, 2) }}</strong></div>
                    <div class="col"><small class="text-muted d-block">EIS</small><strong>RM {{ number_format($payslip->eis_employer, 2) }}</strong></div>
                    <div class="col"><small class="text-muted d-block">HRDF</small><strong>RM {{ number_format($payslip->hrdf, 2) }}</strong></div>
                </div>
            </div>
        </div>

        <!-- Net Pay -->
        <div class="card bg-primary text-white">
            <div class="card-body text-center">
                <h6 class="mb-1">Net Pay</h6>
                <h2 class="mb-0">RM {{ number_format($payslip->net_pay, 2) }}</h2>
            </div>
        </div>
    </div>
</div>

<a href="{{ route('hr.payroll.pay-runs.show', $payslip->payRun) }}" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Pay Run</a>
@endsection
