@extends('layouts.app')
@section('title', 'Leave Applications')
@section('page-title', 'Leave Applications')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Leave Applications</h5>
    </div>
    <div class="card-body">
        {{-- Filters --}}
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    @foreach(['pending','approved','rejected','cancelled'] as $s)
                        <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">All Employees</option>
                    @foreach($employees as $emp)
                        <option value="{{ $emp->id }}" {{ request('employee_id') == $emp->id ? 'selected' : '' }}>{{ $emp->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-primary">Filter</button>
                <a href="{{ route('hr.leave.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Days</th>
                        <th>Status</th>
                        <th>Applied</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($applications as $app)
                    <tr>
                        <td>{{ $app->employee->full_name ?? '—' }}</td>
                        <td><span class="badge bg-info">{{ $app->leaveType->name ?? '—' }}</span></td>
                        <td>{{ $app->start_date->format('d M Y') }}</td>
                        <td>{{ $app->end_date->format('d M Y') }}</td>
                        <td>{{ $app->total_days }}{{ $app->is_half_day ? ' (½)' : '' }}</td>
                        <td>
                            <span class="badge bg-{{ $app->statusBadge() }}">{{ ucfirst($app->status) }}</span>
                            @if($app->manager_status && $app->manager_status !== 'pending')
                            <br><small class="text-muted">Mgr: {{ ucfirst($app->manager_status) }}</small>
                            @endif
                        </td>
                        <td>{{ $app->created_at->format('d M Y') }}</td>
                        <td>
                            @if($app->status === 'pending')
                            <form action="{{ route('hr.leave.approve', $app) }}" method="POST" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-success" title="Approve"><i class="bi bi-check-lg"></i></button>
                            </form>
                            <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $app->id }}" title="Reject"><i class="bi bi-x-lg"></i></button>

                            {{-- Reject Modal --}}
                            <div class="modal fade" id="rejectModal{{ $app->id }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <form action="{{ route('hr.leave.reject', $app) }}" method="POST" class="modal-content">
                                        @csrf
                                        <div class="modal-header"><h5 class="modal-title">Reject Leave</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">
                                            <p>Rejecting leave for <strong>{{ $app->employee->full_name }}</strong></p>
                                            <label class="form-label">Reason <span class="text-danger">*</span></label>
                                            <textarea name="rejection_reason" class="form-control" rows="3" required></textarea>
                                        </div>
                                        <div class="modal-footer">
                                            <button class="btn btn-danger">Reject</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            @else
                                @if($app->approver)
                                    <small class="text-muted">by {{ $app->approver->name }}</small>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No leave applications found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $applications->links() }}
    </div>
</div>
@endsection
