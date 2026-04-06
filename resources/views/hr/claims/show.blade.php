@extends('layouts.app')
@section('title', 'Claim Detail — ' . $claim->claim_number)
@section('page-title', 'Claim Detail')

@section('content')
<div class="container-fluid">
    <a href="{{ route('hr.claims.index') }}" class="btn btn-sm btn-outline-secondary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Back to Claims
    </a>

    {{-- Claim Header --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>{{ $claim->claim_number }}</h5>
            <span class="badge bg-{{ $claim->statusBadge()['class'] }}">{{ $claim->statusBadge()['label'] }}</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="text-muted small">Employee</label>
                    <div class="fw-semibold">{{ $claim->employee->full_name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <label class="text-muted small">Department</label>
                    <div>{{ $claim->employee->department ?? '—' }}</div>
                </div>
                <div class="col-md-2">
                    <label class="text-muted small">Period</label>
                    <div>{{ \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y') }}</div>
                </div>
                <div class="col-md-2">
                    <label class="text-muted small">Submitted</label>
                    <div>{{ $claim->submitted_at?->format('d M Y H:i') ?? '—' }}</div>
                </div>
                <div class="col-md-2">
                    <label class="text-muted small">Manager</label>
                    <div>{{ $claim->manager->full_name ?? '—' }}</div>
                </div>
            </div>

            {{-- Approval trail --}}
            @if($claim->manager_approved_at || $claim->hr_approved_at)
            <hr>
            <div class="row g-3">
                @if($claim->manager_approved_at)
                <div class="col-md-4">
                    <label class="text-muted small">Manager Action</label>
                    <div>
                        <span class="badge bg-{{ in_array($claim->status, ['manager_approved','hr_approved','hr_rejected','paid']) ? 'success' : 'danger' }}">
                            {{ in_array($claim->status, ['manager_approved','hr_approved','hr_rejected','paid']) ? 'Approved' : 'Rejected' }}
                        </span>
                        by {{ $claim->managerApprover->name ?? '—' }}
                        on {{ $claim->manager_approved_at->format('d M Y') }}
                    </div>
                    @if($claim->manager_remarks)
                    <small class="text-muted">{{ $claim->manager_remarks }}</small>
                    @endif
                </div>
                @endif
                @if($claim->hr_approved_at)
                <div class="col-md-4">
                    <label class="text-muted small">HR Action</label>
                    <div>
                        <span class="badge bg-{{ in_array($claim->status, ['hr_approved','paid']) ? 'success' : 'danger' }}">
                            {{ in_array($claim->status, ['hr_approved','paid']) ? 'Approved' : 'Rejected' }}
                        </span>
                        by {{ $claim->hrApprover->name ?? '—' }}
                        on {{ $claim->hr_approved_at->format('d M Y') }}
                    </div>
                    @if($claim->hr_remarks)
                    <small class="text-muted">{{ $claim->hr_remarks }}</small>
                    @endif
                </div>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- Items Table --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Claim Items ({{ $claim->item_count }})</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <th>Description</th>
                            <th>Project/Client</th>
                            <th>Category</th>
                            <th class="text-end">RM (w/o GST)</th>
                            <th class="text-end">GST</th>
                            <th class="text-end">Total</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($claim->items as $i => $item)
                        <tr>
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item->expense_date->format('d/m/Y') }}</td>
                            <td>{{ $item->description }}</td>
                            <td>{{ $item->project_client ?? '—' }}</td>
                            <td><span class="badge bg-secondary">{{ $item->category->name ?? '—' }}</span></td>
                            <td class="text-end">{{ number_format($item->amount, 2) }}</td>
                            <td class="text-end">{{ number_format($item->gst_amount, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($item->total_with_gst, 2) }}</td>
                            <td>
                                @if($item->receipt_path)
                                <a href="{{ route('secure.file', $item->receipt_path) }}" target="_blank" class="text-primary"><i class="bi bi-paperclip"></i> View</a>
                                @else — @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="5" class="text-end">GRAND TOTAL</td>
                            <td class="text-end">RM {{ number_format($claim->total_amount, 2) }}</td>
                            <td class="text-end">RM {{ number_format($claim->total_gst, 2) }}</td>
                            <td class="text-end text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Action buttons --}}
    @if($claim->status === 'manager_approved' && Auth::user()->canManageClaims())
    <div class="card shadow-sm border-0">
        <div class="card-body d-flex gap-2">
            <form action="{{ route('hr.claims.approve', $claim) }}" method="POST" onsubmit="return confirm('Approve this claim?')">
                @csrf
                <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>HR Approve</button>
            </form>
            <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#hrRejectForm">
                <i class="bi bi-x-lg me-1"></i>HR Reject
            </button>
        </div>
        <div class="collapse px-3 pb-3" id="hrRejectForm">
            <form action="{{ route('hr.claims.reject', $claim) }}" method="POST">
                @csrf
                <div class="d-flex gap-2">
                    <input type="text" name="remarks" class="form-control" placeholder="Reason for rejection (required)" required maxlength="1000">
                    <button class="btn btn-danger text-nowrap">Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>
    @endif

    @if($claim->notes)
    <div class="card shadow-sm border-0 mt-4">
        <div class="card-body">
            <h6><i class="bi bi-journal-text me-2"></i>Notes</h6>
            <p class="mb-0">{{ $claim->notes }}</p>
        </div>
    </div>
    @endif
</div>
@endsection
