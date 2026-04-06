@extends('layouts.app')
@section('title', 'Team Leave')
@section('page-title', 'Team Leave Requests')

@section('content')

{{-- Header --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-1"><i class="bi bi-people me-2"></i>Team Leave Applications</h5>
        <p class="text-muted mb-0 small">Review and approve/reject leave requests from your direct reports.</p>
    </div>
</div>

@if(isset($applications) && $applications instanceof \Illuminate\Pagination\LengthAwarePaginator && $applications->isEmpty() && (!isset($directReports) || $directReports->isEmpty()))
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-people" style="font-size:48px;color:#94a3b8;"></i>
        <h5 class="mt-3 mb-2 text-muted">No Direct Reports</h5>
        <p class="text-muted">You currently have no employees reporting to you. Contact HR if you believe this is incorrect.</p>
    </div>
</div>
@else

{{-- Filter --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('user.leave.team') }}" class="d-flex gap-2 align-items-center">
            <label class="small fw-semibold text-muted me-1">Status:</label>
            <select name="status" class="form-select form-select-sm" style="width:160px;" onchange="this.form.submit()">
                <option value="">All</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
            </select>
            @if(request('status'))
            <a href="{{ route('user.leave.team') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            @endif
        </form>
    </div>
</div>

{{-- Pending count badge --}}
@php
    $pendingCount = isset($applications) && $applications instanceof \Illuminate\Pagination\LengthAwarePaginator
        ? \App\Models\LeaveApplication::whereIn('employee_id', $directReports->pluck('id'))->where('status', 'pending')->count()
        : 0;
@endphp
@if($pendingCount > 0)
<div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>{{ $pendingCount }}</strong>&nbsp;pending leave request(s) require your attention.
</div>
@endif

{{-- Applications Table --}}
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th class="text-center">Days</th>
                    <th class="text-center">Status</th>
                    <th>Applied</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($applications as $app)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $app->employee?->full_name ?? '—' }}</div>
                        <small class="text-muted">{{ $app->employee?->designation ?? '' }}</small>
                    </td>
                    <td>
                        <span class="badge bg-primary bg-opacity-10 text-primary">{{ $app->leaveType?->name ?? '—' }}</span>
                    </td>
                    <td>{{ $app->start_date->format('d M Y') }}</td>
                    <td>{{ $app->end_date->format('d M Y') }}</td>
                    <td class="text-center">
                        {{ $app->total_days }}
                        @if($app->is_half_day)
                        <br><small class="text-muted">({{ ucfirst($app->half_day_period) }})</small>
                        @endif
                    </td>
                    <td class="text-center">
                        <span class="badge bg-{{ $app->statusBadge() }}">{{ ucfirst($app->status) }}</span>
                        @if($app->manager_status !== 'pending' && $app->status === 'pending')
                        <br><small class="text-muted">Mgr: {{ ucfirst($app->manager_status) }}</small>
                        @endif
                    </td>
                    <td><small class="text-muted">{{ $app->created_at->format('d M Y') }}</small></td>
                    <td class="text-end">
                        @if($app->status === 'pending')
                        <div class="d-flex gap-1 justify-content-end">
                            {{-- Approve --}}
                            <form method="POST" action="{{ route('user.leave.team.approve', $app) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success" title="Approve"
                                        onclick="return confirm('Approve this leave request?')">
                                    <i class="bi bi-check-lg"></i> Approve
                                </button>
                            </form>

                            {{-- Reject (with modal) --}}
                            <button type="button" class="btn btn-sm btn-danger" title="Reject"
                                    data-bs-toggle="modal" data-bs-target="#rejectModal{{ $app->id }}">
                                <i class="bi bi-x-lg"></i> Reject
                            </button>
                        </div>

                        {{-- Reject Modal --}}
                        <div class="modal fade" id="rejectModal{{ $app->id }}" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="POST" action="{{ route('user.leave.team.reject', $app) }}">
                                        @csrf
                                        <div class="modal-header">
                                            <h5 class="modal-title">Reject Leave — {{ $app->employee?->full_name }}</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body text-start">
                                            <div class="mb-3">
                                                <p class="text-muted small mb-2">
                                                    {{ $app->leaveType?->name }} · {{ $app->start_date->format('d M Y') }} to {{ $app->end_date->format('d M Y') }} ({{ $app->total_days }} days)
                                                </p>
                                                @if($app->reason)
                                                <p class="small"><strong>Employee's reason:</strong> {{ $app->reason }}</p>
                                                @endif
                                            </div>
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Reason for Rejection <span class="text-danger">*</span></label>
                                                <textarea name="manager_remarks" class="form-control" rows="3"
                                                          placeholder="Please provide a clear reason for rejecting this request (required under Employment Act 1955)..."
                                                          required maxlength="500"></textarea>
                                                <div class="form-text">This reason will be shared with the employee via email.</div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">
                                                <i class="bi bi-x-lg me-1"></i>Confirm Rejection
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @else
                            @if($app->status === 'approved')
                            <span class="text-success small"><i class="bi bi-check-circle"></i> Approved</span>
                            @elseif($app->status === 'rejected')
                            <span class="text-danger small" title="{{ $app->rejection_reason ?? $app->manager_remarks }}">
                                <i class="bi bi-x-circle"></i> Rejected
                            </span>
                            @else
                            <span class="text-muted small">{{ ucfirst($app->status) }}</span>
                            @endif
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-calendar-check" style="font-size:24px;"></i>
                        <div class="mt-2">No leave applications found.</div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($applications instanceof \Illuminate\Pagination\LengthAwarePaginator && $applications->hasPages())
<div class="mt-3 d-flex justify-content-center">
    {{ $applications->withQueryString()->links() }}
</div>
@endif

{{-- Malaysian Employment Act Notice --}}
<div class="card mt-4 border-info">
    <div class="card-body small text-muted">
        <i class="bi bi-info-circle text-info me-1"></i>
        <strong>Employment Act 1955 (Malaysia):</strong>
        Employees are entitled to annual leave (8–16 days based on tenure), sick leave (14–22 days),
        hospitalization leave (60 days), maternity leave (98 days), and paternity leave (7 days).
        Leave rejections must be justified and communicated clearly to the employee.
    </div>
</div>

@endif
@endsection
