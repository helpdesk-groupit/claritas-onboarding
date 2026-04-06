@extends('layouts.app')
@section('title', 'Team Claims')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-people me-2"></i>Team Expense Claims</h3>
            <p class="text-muted mb-0">Review and approve your team members' claims</p>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- ── Pending Claims ── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-warning bg-opacity-10 border-0">
            <h5 class="mb-0">
                <i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Approval
                @if($pendingClaims->count() > 0)
                <span class="badge bg-warning text-dark ms-2">{{ $pendingClaims->count() }}</span>
                @endif
            </h5>
        </div>
        <div class="card-body p-0">
            @if($pendingClaims->count() > 0)
            @foreach($pendingClaims as $claim)
            <div class="border-bottom p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <strong class="fs-6">{{ $claim->employee->full_name ?? '-' }}</strong>
                        <span class="text-muted ms-2">{{ $claim->employee->department ?? '' }}</span>
                        <br>
                        <small class="text-muted">
                            {{ $claim->claim_number }} &mdash;
                            {{ \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y') }}
                            &mdash; Submitted {{ $claim->submitted_at?->format('d M Y') }}
                        </small>
                    </div>
                    <div class="text-end">
                        <div class="fs-5 fw-bold text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</div>
                        <small class="text-muted">{{ $claim->item_count }} item(s)</small>
                    </div>
                </div>

                {{-- Items detail --}}
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.85rem;">
                        <thead class="table-light">
                            <tr>
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
                            @foreach($claim->items as $item)
                            <tr>
                                <td>{{ $item->expense_date->format('d/m/Y') }}</td>
                                <td>{{ $item->description }}</td>
                                <td>{{ $item->project_client ?? '-' }}</td>
                                <td><span class="badge bg-secondary">{{ $item->category->name ?? '-' }}</span></td>
                                <td class="text-end">{{ number_format($item->amount, 2) }}</td>
                                <td class="text-end">{{ number_format($item->gst_amount, 2) }}</td>
                                <td class="text-end fw-bold">{{ number_format($item->total_with_gst, 2) }}</td>
                                <td>
                                    @if($item->receipt_path)
                                    <a href="{{ route('secure.file', $item->receipt_path) }}" target="_blank" class="text-primary"><i class="bi bi-paperclip"></i></a>
                                    @else—@endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">TOTAL</td>
                                <td class="text-end">{{ number_format($claim->total_amount, 2) }}</td>
                                <td class="text-end">{{ number_format($claim->total_gst, 2) }}</td>
                                <td class="text-end text-primary">{{ number_format($claim->total_with_gst, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Action buttons --}}
                <div class="d-flex gap-2 justify-content-end">
                    <form action="{{ route('user.claims.team.approve', $claim) }}" method="POST" onsubmit="return confirm('Approve this claim?')">
                        @csrf
                        <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Approve</button>
                    </form>
                    <button class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#reject-{{ $claim->id }}">
                        <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                </div>
                <div class="collapse mt-2" id="reject-{{ $claim->id }}">
                    <form action="{{ route('user.claims.team.reject', $claim) }}" method="POST">
                        @csrf
                        <div class="d-flex gap-2">
                            <input type="text" name="remarks" class="form-control form-control-sm" placeholder="Reason for rejection (required)" required maxlength="1000">
                            <button class="btn btn-danger btn-sm text-nowrap">Confirm Reject</button>
                        </div>
                    </form>
                </div>
            </div>
            @endforeach
            @else
            <div class="text-center text-muted py-4">
                <i class="bi bi-check-circle" style="font-size:2rem;"></i>
                <p class="mt-2">No pending claims to review.</p>
            </div>
            @endif
        </div>
    </div>

    {{-- ── Approval History ── --}}
    @if($historyClaims->count() > 0)
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Approval History</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Claim No.</th>
                            <th>Employee</th>
                            <th>Period</th>
                            <th class="text-end">Total (w/ GST)</th>
                            <th>Status</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($historyClaims as $hc)
                        <tr>
                            <td class="fw-semibold">{{ $hc->claim_number }}</td>
                            <td>{{ $hc->employee->full_name ?? '-' }}</td>
                            <td>{{ \Carbon\Carbon::create($hc->year, $hc->month)->format('M Y') }}</td>
                            <td class="text-end">RM {{ number_format($hc->total_with_gst, 2) }}</td>
                            <td><span class="badge bg-{{ $hc->statusBadge()['class'] }}">{{ $hc->statusBadge()['label'] }}</span></td>
                            <td>{{ $hc->updated_at->format('d M Y') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
