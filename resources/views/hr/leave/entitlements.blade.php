@extends('layouts.app')
@section('title', 'Leave Entitlements')
@section('page-title', 'Leave Entitlements')

@section('content')
@include('hr.leave.partials.nav-tabs')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-award me-2"></i>Leave Entitlements (Tenure-Based)</h5>
        @if(auth()->user()->canManageLeave())
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addEntitlementModal"><i class="bi bi-plus-lg me-1"></i>Add Entitlement</button>
        @endif
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Leave Type</th>
                        <th>Tenure (Months)</th>
                        <th>Entitled Days</th>
                        <th>Carry Forward Limit</th>
                        @if(auth()->user()->canManageLeave())<th class="text-end">Actions</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($entitlements as $ent)
                    <tr>
                        <td><span class="badge bg-info">{{ $ent->leaveType->name ?? '—' }}</span></td>
                        <td>{{ $ent->min_tenure_months }}{{ $ent->max_tenure_months ? ' – ' . $ent->max_tenure_months : '+' }} months</td>
                        <td><strong>{{ $ent->entitled_days }}</strong> days</td>
                        <td>{{ $ent->carry_forward_limit }} days</td>
                        @if(auth()->user()->canManageLeave())
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editEntitlementModal{{ $ent->id }}" title="Edit"><i class="bi bi-pencil"></i></button>
                            <form action="{{ route('hr.leave.entitlements.destroy', $ent) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this entitlement?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr><td colspan="{{ auth()->user()->canManageLeave() ? 5 : 4 }}" class="text-center text-muted py-4">No entitlements configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Edit Modals --}}
@if(auth()->user()->canManageLeave())
@foreach($entitlements as $ent)
<div class="modal fade" id="editEntitlementModal{{ $ent->id }}" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('hr.leave.entitlements.update', $ent) }}" method="POST" class="modal-content">
            @csrf @method('PUT')
            <div class="modal-header"><h5 class="modal-title">Edit Entitlement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select name="leave_type_id" class="form-select" required>
                        <option value="">Select type</option>
                        @foreach($leaveTypes as $lt)<option value="{{ $lt->id }}" {{ $ent->leave_type_id == $lt->id ? 'selected' : '' }}>{{ $lt->name }}</option>@endforeach
                    </select>
                </div>
                <div class="row mb-3">
                    <div class="col"><label class="form-label">Min. Tenure (Months)</label><input type="number" name="min_tenure_months" class="form-control" value="{{ $ent->min_tenure_months }}" min="0" required></div>
                    <div class="col"><label class="form-label">Max. Tenure (Months)</label><input type="number" name="max_tenure_months" class="form-control" value="{{ $ent->max_tenure_months }}" placeholder="Leave blank for unlimited"></div>
                </div>
                <div class="row mb-3">
                    <div class="col"><label class="form-label">Entitled Days <span class="text-danger">*</span></label><input type="number" name="entitled_days" class="form-control" step="0.5" value="{{ $ent->entitled_days }}" required></div>
                    <div class="col"><label class="form-label">Carry Forward Limit</label><input type="number" name="carry_forward_limit" class="form-control" step="0.5" value="{{ $ent->carry_forward_limit }}"></div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
        </form>
    </div>
</div>
@endforeach
@endif

{{-- Add Modal --}}
@if(auth()->user()->canManageLeave())
<div class="modal fade" id="addEntitlementModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('hr.leave.entitlements.store') }}" method="POST" class="modal-content">
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
@endif
@endsection
