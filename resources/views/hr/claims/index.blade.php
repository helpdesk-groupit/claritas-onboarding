@extends('layouts.app')
@section('title', 'Expense Claims')
@section('page-title', 'Expense Claims')

@section('content')
<div class="container-fluid">

    {{-- Stats Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-warning mb-1"><i class="bi bi-hourglass-split" style="font-size:1.5rem;"></i></div>
                    <h4 class="mb-0">{{ $stats['pending'] ?? 0 }}</h4>
                    <small class="text-muted">Pending Review</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-success mb-1"><i class="bi bi-check-circle" style="font-size:1.5rem;"></i></div>
                    <h4 class="mb-0">{{ $stats['approved'] ?? 0 }}</h4>
                    <small class="text-muted">HR Approved</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-primary mb-1"><i class="bi bi-cash-stack" style="font-size:1.5rem;"></i></div>
                    <h4 class="mb-0">RM {{ number_format($stats['total_approved'] ?? 0, 2) }}</h4>
                    <small class="text-muted">Approved This Month</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="text-info mb-1"><i class="bi bi-receipt" style="font-size:1.5rem;"></i></div>
                    <h4 class="mb-0">{{ $stats['total'] ?? 0 }}</h4>
                    <small class="text-muted">Total Claims</small>
                </div>
            </div>
        </div>
    </div>

    {{-- Main Claims Card --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-receipt-cutoff me-2"></i>All Expense Claims</h5>
            <div class="d-flex gap-2">
                <a href="{{ route('hr.claims.export', request()->query()) }}" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-download me-1"></i>Export CSV
                </a>
                <a href="{{ route('hr.claims.categories') }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-tags me-1"></i>Categories
                </a>
                <a href="{{ route('hr.claims.policy') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-gear me-1"></i>Policy
                </a>
            </div>
        </div>
        <div class="card-body">
            {{-- Filters --}}
            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        @foreach(['draft','submitted','manager_approved','manager_rejected','hr_approved','hr_rejected','paid','cancelled'] as $s)
                        <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ str_replace('_',' ', ucfirst($s)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="employee_id" class="form-select form-select-sm">
                        <option value="">All Employees</option>
                        @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" {{ request('employee_id') == $emp->id ? 'selected' : '' }}>{{ $emp->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="month" class="form-select form-select-sm">
                        <option value="">All Months</option>
                        @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" {{ request('month') == $m ? 'selected' : '' }}>{{ date('F', mktime(0,0,0,$m)) }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="year" class="form-select form-select-sm">
                        <option value="">All Years</option>
                        @for($y = now()->year; $y >= now()->year - 2; $y--)
                        <option value="{{ $y }}" {{ request('year') == $y ? 'selected' : '' }}>{{ $y }}</option>
                        @endfor
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('hr.claims.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>

            {{-- Bulk Approve --}}
            <form id="bulkForm" action="{{ route('hr.claims.bulk-approve') }}" method="POST">
                @csrf
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="30"><input type="checkbox" id="checkAll" class="form-check-input"></th>
                                <th>Claim No.</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Period</th>
                                <th>Items</th>
                                <th class="text-end">Total (w/ GST)</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($claims as $claim)
                            <tr>
                                <td>
                                    @if($claim->status === 'manager_approved')
                                    <input type="checkbox" name="claim_ids[]" value="{{ $claim->id }}" class="form-check-input bulk-check">
                                    @endif
                                </td>
                                <td class="fw-semibold">{{ $claim->claim_number }}</td>
                                <td>{{ $claim->employee->full_name ?? '—' }}</td>
                                <td>{{ $claim->employee->department ?? '—' }}</td>
                                <td>{{ \Carbon\Carbon::create($claim->year, $claim->month)->format('M Y') }}</td>
                                <td>{{ $claim->item_count }}</td>
                                <td class="text-end fw-bold">RM {{ number_format($claim->total_with_gst, 2) }}</td>
                                <td><span class="badge bg-{{ $claim->statusBadge()['class'] }}">{{ $claim->statusBadge()['label'] }}</span></td>
                                <td>{{ $claim->submitted_at?->format('d M Y') ?? '—' }}</td>
                                <td>
                                    <a href="{{ route('hr.claims.show', $claim) }}" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a>
                                </td>
                            </tr>
                            @empty
                            <tr><td colspan="10" class="text-center text-muted py-4">No claims found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($claims->where('status', 'manager_approved')->count() > 0)
                <div class="mt-3">
                    <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve all selected claims?')">
                        <i class="bi bi-check-all me-1"></i>Bulk Approve Selected
                    </button>
                </div>
                @endif
            </form>

            {{ $claims->links() }}
        </div>
    </div>
</div>

<script>
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.bulk-check').forEach(c => c.checked = this.checked);
});
</script>
@endsection
