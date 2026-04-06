@extends('layouts.app')
@section('title', 'Employee Salaries')
@section('page-title', 'Employee Salaries')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-wallet2 me-2"></i>Employee Salaries</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSalaryModal"><i class="bi bi-plus-lg me-1"></i>Set Salary</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th class="text-end">Basic Salary (RM)</th>
                        <th>Payment Method</th>
                        <th>Bank</th>
                        <th>Effective From</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($salaries as $salary)
                    <tr>
                        <td>{{ $salary->employee->full_name ?? '—' }}</td>
                        <td class="text-end fw-bold">{{ number_format($salary->basic_salary, 2) }}</td>
                        <td>{{ ucfirst($salary->payment_method) }}</td>
                        <td>{{ $salary->bank_name ?? '—' }}<br><small class="text-muted">{{ $salary->bank_account_number ?? '' }}</small></td>
                        <td>{{ \Carbon\Carbon::parse($salary->effective_from)->format('d M Y') }}</td>
                        <td class="text-center"><span class="badge bg-{{ $salary->is_active ? 'success' : 'secondary' }}">{{ $salary->is_active ? 'Active' : 'Inactive' }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No salary records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $salaries->links() }}</div>
    </div>
</div>

<!-- Add Salary Modal -->
<div class="modal fade" id="addSalaryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('hr.payroll.salaries.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Set Employee Salary</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Employee</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">— Select —</option>
                                @foreach($employees as $emp)
                                <option value="{{ $emp->id }}">{{ $emp->full_name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Basic Salary (RM)</label>
                            <input type="number" name="basic_salary" class="form-control" step="0.01" min="0" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cheque">Cheque</option>
                                <option value="cash">Cash</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Account Number</label>
                            <input type="text" name="bank_account_number" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Effective From</label>
                        <input type="date" name="effective_from" class="form-control" value="{{ now()->format('Y-m-d') }}" required>
                    </div>

                    <hr>
                    <h6>Recurring Items (Allowances & Deductions)</h6>
                    <div id="salaryItems">
                        @foreach($payrollItems as $pi)
                        <div class="row mb-2">
                            <div class="col-6"><label class="form-label">{{ $pi->name }} <span class="badge bg-{{ $pi->type === 'earning' ? 'success' : 'danger' }} badge-sm">{{ $pi->type }}</span></label></div>
                            <div class="col-6"><input type="number" name="items[{{ $pi->id }}]" class="form-control form-control-sm" step="0.01" min="0" placeholder="0.00"></div>
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
            </div>
        </form>
    </div>
</div>
@endsection
