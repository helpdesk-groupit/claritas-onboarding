@extends('layouts.app')
@section('title', 'Expense Claims')
@section('page-title', 'Expense Claims')

@section('content')
<div class="container-fluid">

    {{-- Stats Cards --}}
    @include('partials.dashboard-widgets-style')
    <div class="section-header">
        <div class="section-icon" style="background:#fef3c7;">
            <i class="bi bi-receipt-cutoff" style="font-size:16px;color:#d97706;"></i>
        </div>
        <h6>Claims Overview</h6>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card dash-widget h-100">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number">{{ $stats['pending'] ?? 0 }}</div>
                            <div class="widget-label">Pending Review</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dash-widget h-100">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-check-circle-fill"></i></div>
                        <div>
                            <div class="widget-number">{{ $stats['approved'] ?? 0 }}</div>
                            <div class="widget-label">HR Approved</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dash-widget h-100">
                <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-cash-stack"></i></div>
                        <div>
                            <div class="widget-number" style="font-size:24px;">RM {{ number_format($stats['total_approved'] ?? 0, 2) }}</div>
                            <div class="widget-label">Approved This Month</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card dash-widget h-100">
                <div class="widget-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-receipt"></i></div>
                        <div>
                            <div class="widget-number">{{ $stats['total'] ?? 0 }}</div>
                            <div class="widget-label">Total Claims</div>
                        </div>
                    </div>
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
                <a href="{{ route('hr.claims.download-zip', request()->query()) }}" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-file-earmark-zip me-1"></i>Approved PDFs (ZIP)
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
                        @foreach(['submitted','manager_approved','manager_rejected','hr_approved','hr_rejected','paid','cancelled'] as $s)
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
                                <th class="text-end">Total (w/ SST)</th>
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
                                <td>{{ $claim->submitted_at?->format('d/m/Y') ?? '—' }}</td>
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

<script nonce="{{ $cspNonce ?? '' }}">
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.bulk-check').forEach(c => c.checked = this.checked);
});
</script>
@endsection
