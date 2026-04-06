@extends('layouts.app')
@section('title', 'Leave Types')
@section('page-title', 'Leave Types')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-tags me-2"></i>Leave Types</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addTypeModal"><i class="bi bi-plus-lg me-1"></i>Add Type</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Paid</th>
                        <th>Attachment Required</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($types as $type)
                    <tr>
                        <td><code>{{ $type->code }}</code></td>
                        <td>{{ $type->name }}</td>
                        <td>{!! $type->is_paid ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' !!}</td>
                        <td>{!! $type->requires_attachment ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-muted"></i>' !!}</td>
                        <td><span class="badge bg-{{ $type->is_active ? 'success' : 'secondary' }}">{{ $type->is_active ? 'Active' : 'Inactive' }}</span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTypeModal{{ $type->id }}"><i class="bi bi-pencil"></i></button>
                            {{-- Edit Modal --}}
                            <div class="modal fade" id="editTypeModal{{ $type->id }}" tabindex="-1">
                                <div class="modal-dialog">
                                    <form action="{{ route('leave.types.update', $type) }}" method="POST" class="modal-content">
                                        @csrf @method('PUT')
                                        <div class="modal-header"><h5 class="modal-title">Edit Leave Type</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                        <div class="modal-body">
                                            <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" value="{{ $type->name }}" required></div>
                                            <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ $type->description }}</textarea></div>
                                            <div class="form-check mb-2"><input type="checkbox" name="is_paid" value="1" class="form-check-input" {{ $type->is_paid ? 'checked' : '' }}><label class="form-check-label">Paid Leave</label></div>
                                            <div class="form-check mb-2"><input type="checkbox" name="requires_attachment" value="1" class="form-check-input" {{ $type->requires_attachment ? 'checked' : '' }}><label class="form-check-label">Requires Attachment</label></div>
                                            <div class="form-check"><input type="checkbox" name="is_active" value="1" class="form-check-input" {{ $type->is_active ? 'checked' : '' }}><label class="form-check-label">Active</label></div>
                                        </div>
                                        <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No leave types configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Add Type Modal --}}
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('leave.types.store') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header"><h5 class="modal-title">Add Leave Type</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required placeholder="e.g. Annual Leave"></div>
                <div class="mb-3"><label class="form-label">Code <span class="text-danger">*</span></label><input type="text" name="code" class="form-control" required placeholder="e.g. AL" maxlength="20"></div>
                <div class="mb-3"><label class="form-label">Company</label><input type="text" name="company" class="form-control" placeholder="Leave blank for all companies"></div>
                <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
                <div class="form-check mb-2"><input type="checkbox" name="is_paid" value="1" class="form-check-input" checked><label class="form-check-label">Paid Leave</label></div>
                <div class="form-check"><input type="checkbox" name="requires_attachment" value="1" class="form-check-input"><label class="form-check-label">Requires Attachment (e.g. MC)</label></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Create</button></div>
        </form>
    </div>
</div>
@endsection
