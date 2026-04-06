@extends('layouts.app')
@section('title', 'Leave Entitlements')
@section('page-title', 'Leave Entitlements')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-award me-2"></i>Leave Entitlements (Tenure-Based)</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addEntitlementModal"><i class="bi bi-plus-lg me-1"></i>Add Entitlement</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Leave Type</th><th>Tenure (Months)</th><th>Entitled Days</th><th>Carry Forward Limit</th></tr>
                </thead>
                <tbody>
                    @forelse($entitlements as $ent)
                    <tr>
                        <td><span class="badge bg-info">{{ $ent->leaveType->name ?? '—' }}</span></td>
                        <td>{{ $ent->min_tenure_months }}{{ $ent->max_tenure_months ? ' – ' . $ent->max_tenure_months : '+' }} months</td>
                        <td><strong>{{ $ent->entitled_days }}</strong> days</td>
                        <td>{{ $ent->carry_forward_limit }} days</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No entitlements configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addEntitlementModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('leave.entitlements.store') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header"><h5 class="modal-title">Add Entitlement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select name="leave_type_id" class="form-select" required>
                        <option value="">Select type</option>
                        @foreach($leaveTypes as $lt)<option value="{{ $lt->id }}">{{ $lt->name }}</option>@endforeach
                    </select>
                </div>
                <div class="row mb-3">
                    <div class="col"><label class="form-label">Min. Tenure (Months)</label><input type="number" name="min_tenure_months" class="form-control" value="0" min="0" required></div>
                    <div class="col"><label class="form-label">Max. Tenure (Months)</label><input type="number" name="max_tenure_months" class="form-control" placeholder="Leave blank for unlimited"></div>
                </div>
                <div class="row mb-3">
                    <div class="col"><label class="form-label">Entitled Days <span class="text-danger">*</span></label><input type="number" name="entitled_days" class="form-control" step="0.5" required></div>
                    <div class="col"><label class="form-label">Carry Forward Limit</label><input type="number" name="carry_forward_limit" class="form-control" step="0.5" value="0"></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Create</button></div>
        </form>
    </div>
</div>
@endsection
