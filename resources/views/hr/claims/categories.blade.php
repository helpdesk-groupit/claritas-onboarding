@extends('layouts.app')
@section('title', 'Expense Categories')
@section('page-title', 'Expense Categories')

@section('content')
<div class="container-fluid">
    <a href="{{ route('hr.claims.index') }}" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to Claims
    </a>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-tags me-2"></i>Expense Categories</h5>
            @if(Auth::user()->canManageClaims())
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                <i class="bi bi-plus-lg me-1"></i>Add Category
            </button>
            @endif
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order</th>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Keywords (auto-detect)</th>
                            <th>Monthly Limit</th>
                            <th>Receipt Req.</th>
                            <th>Status</th>
                            @if(Auth::user()->canManageClaims())
                            <th>Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($categories as $cat)
                        <tr>
                            <td>{{ $cat->sort_order }}</td>
                            <td class="fw-semibold">{{ $cat->name }}</td>
                            <td><code>{{ $cat->code }}</code></td>
                            <td>
                                @foreach(($cat->keywords ?? []) as $kw)
                                <span class="badge bg-light text-dark border me-1">{{ $kw }}</span>
                                @endforeach
                            </td>
                            <td>{{ $cat->monthly_limit ? 'RM '.number_format($cat->monthly_limit, 2) : '—' }}</td>
                            <td>
                                @if($cat->requires_receipt)
                                <span class="badge bg-warning text-dark">Required</span>
                                @else
                                <span class="badge bg-secondary">Optional</span>
                                @endif
                            </td>
                            <td>
                                @if($cat->is_active)
                                <span class="badge bg-success">Active</span>
                                @else
                                <span class="badge bg-danger">Inactive</span>
                                @endif
                            </td>
                            @if(Auth::user()->canManageClaims())
                            <td>
                                <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal" data-bs-target="#editCategoryModal{{ $cat->id }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </td>
                            @endif
                        </tr>

                        {{-- Edit Modal --}}
                        @if(Auth::user()->canManageClaims())
                        <div class="modal fade" id="editCategoryModal{{ $cat->id }}" tabindex="-1">
                            <div class="modal-dialog">
                                <form action="{{ route('hr.claims.categories.update', $cat) }}" method="POST" class="modal-content">
                                    @csrf @method('PUT')
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit Category: {{ $cat->name }}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label">Name <span class="text-danger">*</span></label>
                                            <input type="text" name="name" class="form-control" value="{{ $cat->name }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Code <span class="text-danger">*</span></label>
                                            <input type="text" name="code" class="form-control" value="{{ $cat->code }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Description</label>
                                            <input type="text" name="description" class="form-control" value="{{ $cat->description }}">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Keywords (comma-separated)</label>
                                            <input type="text" name="keywords" class="form-control" value="{{ implode(', ', $cat->keywords ?? []) }}" placeholder="grab, taxi, fuel, petrol">
                                            <small class="text-muted">Used for auto-detecting category from description</small>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Monthly Limit (RM)</label>
                                                <input type="number" name="monthly_limit" class="form-control" step="0.01" value="{{ $cat->monthly_limit }}">
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Sort Order</label>
                                                <input type="number" name="sort_order" class="form-control" value="{{ $cat->sort_order }}">
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" name="requires_receipt" value="1" class="form-check-input" {{ $cat->requires_receipt ? 'checked' : '' }}>
                                                <label class="form-check-label">Requires Receipt</label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" name="is_active" value="1" class="form-check-input" {{ $cat->is_active ? 'checked' : '' }}>
                                                <label class="form-check-label">Active</label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn btn-primary">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        @endif
                        @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">No categories configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Add Category Modal --}}
@if(Auth::user()->canManageClaims())
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('hr.claims.categories.store') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title">Add Expense Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Transportation">
                </div>
                <div class="mb-3">
                    <label class="form-label">Code <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" required placeholder="e.g. TRANSPORT">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Keywords (comma-separated)</label>
                    <input type="text" name="keywords" class="form-control" placeholder="grab, taxi, fuel, petrol">
                    <small class="text-muted">Used for auto-detecting category from description</small>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Monthly Limit (RM)</label>
                        <input type="number" name="monthly_limit" class="form-control" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0">
                    </div>
                </div>
                <div class="mt-3">
                    <div class="form-check form-check-inline">
                        <input type="checkbox" name="requires_receipt" value="1" class="form-check-input" checked>
                        <label class="form-check-label">Requires Receipt</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input" checked>
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary">Add Category</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection
