@extends('layouts.app')
@section('title', 'Damaged Assets')
@section('page-title', 'Damaged Assets')

@section('content')

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('assets.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Asset Listing
    </a>
    <span class="text-muted small">/ Damaged Assets</span>
</div>

<div class="alert alert-warning py-2 mb-3">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Assets listed here were marked as <strong>Not Good</strong> and removed from the active asset listing.
    This page is <strong>view-only</strong>.
</div>

<div class="card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-bold">
            <i class="bi bi-trash me-2 text-danger"></i>Damaged / Disposed Assets
        </h6>
        <span class="badge bg-danger">{{ $disposed->total() }} record(s)</span>
    </div>
    <div class="card-body p-0">
        @if($disposed->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-check-circle" style="font-size:40px;color:#16a34a;"></i>
                <p class="mt-2">No damaged assets on record.</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Asset Tag</th>
                        <th>Type</th>
                        <th>Brand / Model</th>
                        <th>Serial Number</th>
                        <th>Condition</th>
                        <th>Disposed By</th>
                        <th>Disposed At</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($disposed as $d)
                    <tr>
                        <td class="ps-3"><code>{{ $d->asset_tag }}</code></td>
                        <td>{{ ucfirst(str_replace('_',' ', $d->asset_type)) }}</td>
                        <td>{{ $d->brand }} {{ $d->model }}</td>
                        <td class="text-muted">{{ $d->serial_number ?? '—' }}</td>
                        <td><span class="badge bg-danger">Not Good</span></td>
                        <td>{{ $d->disposed_by ?? '—' }}</td>
                        <td>{{ $d->disposed_at?->format('d M Y, h:i A') ?? '—' }}</td>
                        <td class="text-muted" style="max-width:220px;">
                            @if($d->remarks)
                                <span title="{{ $d->remarks }}" style="display:block;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;max-width:200px;">
                                    {{ $d->remarks }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $disposed->links() }}</div>
        @endif
    </div>
</div>

@endsection
