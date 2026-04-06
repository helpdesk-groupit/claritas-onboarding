@extends('layouts.app')
@section('title', 'EA Forms (CP.8D)')
@section('page-title', 'EA Forms — Borang EA (CP.8D)')

@section('content')

{{-- Action Bar --}}
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div class="d-flex align-items-center gap-2">
        <label class="form-label mb-0 fw-semibold">Tax Year:</label>
        <form method="GET" action="{{ route('hr.payroll.ea-forms.index') }}" class="d-flex gap-2">
            <select name="year" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                @foreach($availableYears as $y)
                    <option value="{{ $y }}" {{ $y == $year ? 'selected' : '' }}>{{ $y }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('hr.payroll.ea-forms.generate') }}" class="d-inline">
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Generate EA forms for {{ $year }}? This aggregates all approved/paid payslips.')">
                <i class="bi bi-gear me-1"></i>Generate EA Forms
            </button>
        </form>
        @if($eaForms->where('status', 'draft')->count() > 0)
        <form method="POST" action="{{ route('hr.payroll.ea-forms.bulk-finalize') }}" class="d-inline">
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Finalize ALL draft EA forms for {{ $year }}? Employees will be able to view them.')">
                <i class="bi bi-check2-all me-1"></i>Finalize All Drafts
            </button>
        </form>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-1"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('info'))
    <div class="alert alert-info alert-dismissible fade show"><i class="bi bi-info-circle me-1"></i>{{ session('info') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- Summary Stats --}}
@php
    $allForms = $eaForms;
    $totalGross = $allForms->sum(fn($f) => (float) $f->total_remuneration);
    $totalPcb   = $allForms->sum(fn($f) => (float) $f->pcb_paid);
    $totalEpf   = $allForms->sum(fn($f) => (float) $f->epf_employee);
    $draftCount = $allForms->where('status', 'draft')->count();
    $finalCount = $allForms->where('status', 'finalized')->count();
@endphp
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-3">
                <div class="h4 mb-0">{{ $eaForms->total() }}</div>
                <small class="text-muted">Total EA Forms</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-3">
                <div class="h4 mb-0">RM {{ number_format($totalGross, 2) }}</div>
                <small class="text-muted">Total Remuneration</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-3">
                <div class="h4 mb-0">RM {{ number_format($totalPcb, 2) }}</div>
                <small class="text-muted">Total PCB/MTD</small>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="card border-0 bg-light">
            <div class="card-body text-center py-3">
                <span class="badge bg-secondary">{{ $draftCount }} Draft</span>
                <span class="badge bg-success">{{ $finalCount }} Finalized</span>
            </div>
        </div>
    </div>
</div>

{{-- EA Forms Table --}}
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Tax No.</th>
                        <th>IC / Passport</th>
                        <th class="text-end">Gross Remuneration</th>
                        <th class="text-end">EPF (Employee)</th>
                        <th class="text-end">PCB/MTD</th>
                        <th class="text-end">Net Pay</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($eaForms as $ea)
                    <tr>
                        <td>
                            <strong>{{ $ea->employee_name }}</strong>
                            @if($ea->designation)
                                <br><small class="text-muted">{{ $ea->designation }}</small>
                            @endif
                        </td>
                        <td><small>{{ $ea->employee_tax_no ?: '—' }}</small></td>
                        <td><small>{{ $ea->employee_ic_no ?: '—' }}</small></td>
                        <td class="text-end">{{ number_format($ea->total_remuneration, 2) }}</td>
                        <td class="text-end">{{ number_format($ea->epf_employee, 2) }}</td>
                        <td class="text-end">{{ number_format($ea->pcb_paid, 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($ea->net_pay, 2) }}</td>
                        <td class="text-center">{!! $ea->statusBadge() !!}</td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('hr.payroll.ea-forms.show', $ea) }}" class="btn btn-outline-primary" title="View / Print">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if($ea->status === 'draft')
                                <form method="POST" action="{{ route('hr.payroll.ea-forms.finalize', $ea) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-success" title="Finalize" onclick="return confirm('Finalize EA form for {{ $ea->employee_name }}?')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('hr.payroll.ea-forms.delete', $ea) }}" class="d-inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger" title="Delete" onclick="return confirm('Delete this EA form?')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">
                            <i class="bi bi-file-earmark-text" style="font-size:2rem"></i>
                            <p class="mt-2 mb-0">No EA forms generated for {{ $year }}.<br>
                            Click <strong>Generate EA Forms</strong> to create them from approved pay runs.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($eaForms->hasPages())
    <div class="card-footer">
        {{ $eaForms->appends(['year' => $year])->links() }}
    </div>
    @endif
</div>

<div class="mt-3">
    <a href="{{ route('hr.payroll.pay-runs.index') }}" class="btn btn-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Payroll</a>
</div>
@endsection
