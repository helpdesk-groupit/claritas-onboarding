@extends('layouts.app')
@section('title', 'My Payslips')
@section('page-title', 'My Payslips')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>My Payslips</h5>
        <form method="GET" class="d-flex gap-2">
            <select name="year" class="form-select form-select-sm" style="width:120px" onchange="this.form.submit()">
                @for($y = now()->year - 2; $y <= now()->year; $y++)
                <option value="{{ $y }}" {{ request('year', now()->year) == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Payslip #</th>
                        <th>Period</th>
                        <th>Pay Date</th>
                        <th class="text-end">Basic (RM)</th>
                        <th class="text-end">Earnings (RM)</th>
                        <th class="text-end">Deductions (RM)</th>
                        <th class="text-end">Net Pay (RM)</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($payslips as $slip)
                    <tr>
                        <td><code>{{ $slip->payslip_number }}</code></td>
                        <td>{{ \Carbon\Carbon::create($slip->payRun->year, $slip->payRun->month)->format('F Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($slip->payRun->pay_date)->format('d M Y') }}</td>
                        <td class="text-end">{{ number_format($slip->basic_salary, 2) }}</td>
                        <td class="text-end text-success">{{ number_format($slip->total_earnings, 2) }}</td>
                        <td class="text-end text-danger">{{ number_format($slip->total_deductions, 2) }}</td>
                        <td class="text-end fw-bold">{{ number_format($slip->net_pay, 2) }}</td>
                        <td><a href="{{ route('user.payroll.payslip', $slip) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No payslips available.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
