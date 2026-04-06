@extends('layouts.app')
@section('title', 'Public Holidays')
@section('page-title', 'Public Holidays')

@section('content')
@include('hr.leave.partials.nav-tabs')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Public Holidays</h5>
        @if(auth()->user()->canManageLeave())
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addHolidayModal"><i class="bi bi-plus-lg me-1"></i>Add Holiday</button>
        @endif
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Name</th>
                        <th>Company</th>
                        <th>Recurring</th>
                        @if(auth()->user()->canManageLeave())<th class="text-end">Actions</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($holidays as $h)
                    <tr>
                        <td>{{ $h->date->format('d M Y (l)') }}</td>
                        <td>{{ $h->name }}</td>
                        <td>{{ $h->company ?? 'All' }}</td>
                        <td>{!! $h->is_recurring ? '<i class="bi bi-check-circle text-success"></i>' : '' !!}</td>
                        @if(auth()->user()->canManageLeave())
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editHolidayModal{{ $h->id }}" title="Edit"><i class="bi bi-pencil"></i></button>
                            <form action="{{ route('hr.leave.holidays.destroy', $h) }}" method="POST" class="d-inline" onsubmit="return confirm('Remove this holiday?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr><td colspan="{{ auth()->user()->canManageLeave() ? 5 : 4 }}" class="text-center text-muted py-4">No public holidays configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Edit Modals --}}
@if(auth()->user()->canManageLeave())
@foreach($holidays as $h)
<div class="modal fade" id="editHolidayModal{{ $h->id }}" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('hr.leave.holidays.update', $h) }}" method="POST" class="modal-content">
            @csrf @method('PUT')
            <div class="modal-header"><h5 class="modal-title">Edit Public Holiday</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="{{ $h->name }}" required></div>
                <div class="mb-3"><label class="form-label">Date <span class="text-danger">*</span></label><input type="date" name="date" class="form-control" value="{{ $h->date->format('Y-m-d') }}" required></div>
                <div class="mb-3"><label class="form-label">Company</label><input type="text" name="company" class="form-control" value="{{ $h->company }}" placeholder="Leave blank for all companies"></div>
                <div class="form-check"><input type="checkbox" name="is_recurring" value="1" class="form-check-input" {{ $h->is_recurring ? 'checked' : '' }}><label class="form-check-label">Recurring Annually</label></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Update</button></div>
        </form>
    </div>
</div>
@endforeach

{{-- Add Modal --}}
<div class="modal fade" id="addHolidayModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('hr.leave.holidays.store') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header"><h5 class="modal-title">Add Public Holiday</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" required placeholder="e.g. Hari Raya Aidilfitri"></div>
                <div class="mb-3"><label class="form-label">Date <span class="text-danger">*</span></label><input type="date" name="date" class="form-control" required></div>
                <div class="mb-3"><label class="form-label">Company</label><input type="text" name="company" class="form-control" placeholder="Leave blank for all companies"></div>
                <div class="form-check"><input type="checkbox" name="is_recurring" value="1" class="form-check-input"><label class="form-check-label">Recurring Annually</label></div>
            </div>
            <div class="modal-footer"><button class="btn btn-primary">Add</button></div>
        </form>
    </div>
</div>
@endif
@endsection
