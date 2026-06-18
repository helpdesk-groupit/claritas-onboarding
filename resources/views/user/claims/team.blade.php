@extends('layouts.app')
@section('title', 'Team Claims')

@section('content')
@include('partials.dashboard-widgets-style')
<div class="container-fluid py-4">

    {{-- ── Page Header ── --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-people-fill me-2"></i>Team Expense Claims</h3>
            <p class="text-muted mb-0">Review and approve your team members' claims</p>
        </div>
        <a href="{{ route('user.claims.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-receipt-cutoff me-1"></i>My Claims
        </a>
    </div>

    {{-- success/error flash is rendered globally by layouts/app.blade.php --}}

    {{-- ── Summary Stat Cards ── --}}
    @php
        $pendingCount  = $pendingClaims->count();
        $totalPending  = $pendingClaims->sum('total_with_gst');
        $staffPending  = $pendingClaims->pluck('employee_id')->unique()->count();
        $reviewedCount = $historyClaims->count();
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number">{{ $pendingCount }}</div>
                            <div class="widget-label">Pending Approval</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-cash-stack"></i></div>
                        <div>
                            <div class="widget-number" style="font-size:22px;">RM {{ number_format($totalPending, 2) }}</div>
                            <div class="widget-label">Total Pending</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-person-badge"></i></div>
                        <div>
                            <div class="widget-number">{{ $staffPending }}</div>
                            <div class="widget-label">Staff Awaiting</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <div class="widget-number">{{ $reviewedCount }}</div>
                            <div class="widget-label">Reviewed</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Pending Claims ── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white border-0 d-flex align-items-center">
            <h5 class="mb-0"><i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Approval</h5>
            @if($pendingCount > 0)
            <span class="badge rounded-pill bg-warning text-dark ms-2">{{ $pendingCount }}</span>
            @endif
        </div>
        <div class="card-body @if($pendingCount > 0) pt-0 @endif">
            @forelse($pendingClaims as $claim)
            @php
                $empName = $claim->employee->full_name ?? '—';
                $initials = collect(explode(' ', trim($empName)))->filter()->take(2)->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
            @endphp
            <div class="border rounded-3 p-3 mb-3 bg-light bg-opacity-50">
                {{-- Claim header --}}
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                             style="width:42px;height:42px;font-size:.95rem;">{{ $initials ?: '?' }}</div>
                        <div>
                            <div class="fw-semibold">{{ $empName }}
                                <span class="text-muted fw-normal small ms-1">{{ $claim->employee->department ?? '' }}</span>
                            </div>
                            <small class="text-muted">
                                <span class="fw-semibold">{{ $claim->claim_number }}</span> &middot;
                                {{ \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y') }} &middot;
                                <i class="bi bi-send-check me-1"></i>Submitted {{ $claim->submitted_at?->format('d M Y') }}
                            </small>
                            @if($claim->managerPendingCount() > 0)
                            <div class="mt-1"><span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-people me-1"></i>Manager review: {{ $claim->managerProgress() }} items decided (multiple approvers)</span></div>
                            @endif
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fs-5 fw-bold text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</div>
                        <small class="text-muted">{{ $claim->item_count }} item{{ $claim->item_count == 1 ? '' : 's' }}</small>
                    </div>
                </div>

                @include('partials.claim-review-summary', ['claim' => $claim])

                {{-- Items detail (inside the approve form so per-item reject decisions submit together) --}}
                <form action="{{ route('user.claims.team.approve', $claim) }}" method="POST" class="js-confirm item-review-form"
                      data-confirm="Approve your items on this claim? Any item you ticked “Reject” is excluded. The claim moves to HR once every manager has reviewed their items."
                      data-confirm-title="Approve your items" data-confirm-ok="Approve" data-confirm-variant="success">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle bg-white rounded overflow-hidden mb-3" style="font-size:.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Category</th>
                                    <th class="text-end">RM (w/o GST)</th>
                                    <th class="text-end">GST</th>
                                    <th class="text-end">Total</th>
                                    <th class="text-center">Receipt</th>
                                    <th>Approver</th>
                                    <th class="text-center text-danger">Reject?</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($claim->items as $item)
                                @php $mine = $item->approver_id === $employee->id; @endphp
                                <tr class="review-row {{ $mine ? '' : 'opacity-75' }}">
                                    <td class="text-nowrap">{{ $item->expense_date->format('d/m/Y') }}</td>
                                    <td>
                                        {{ $item->description }}
                                        @include('partials.claim-item-checks', ['item' => $item])
                                    </td>
                                    <td><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $item->category->name ?? '—' }}</span></td>
                                    <td class="text-end">{{ number_format($item->amount, 2) }}</td>
                                    <td class="text-end">{{ number_format($item->gst_amount, 2) }}</td>
                                    <td class="text-end fw-semibold item-total" data-total="{{ $mine ? $item->total_with_gst : 0 }}">{{ number_format($item->total_with_gst, 2) }}</td>
                                    <td class="text-center">
                                        @if($item->receipt_path)
                                        <a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-1" title="View receipt"><i class="bi bi-paperclip"></i></a>
                                        @else
                                        <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small text-nowrap">
                                        {{ $item->approver->full_name ?? '—' }}
                                        @if($item->manager_status === 'approved')<span class="badge bg-success ms-1">Approved</span>
                                        @elseif($item->manager_status === 'rejected')<span class="badge bg-danger ms-1">Rejected</span>
                                        @else<span class="badge bg-secondary ms-1">Pending</span>@endif
                                    </td>
                                    <td class="text-center" style="min-width:150px;">
                                        @if($mine && $item->isManagerPending())
                                        <input type="checkbox" class="form-check-input reject-toggle border-danger" name="rejected_items[]" value="{{ $item->id }}" style="width:1.7em;height:1.7em;cursor:pointer;accent-color:#dc3545;" title="Reject this item">
                                        <input type="text" name="item_remarks[{{ $item->id }}]" class="form-control form-control-sm mt-1 reject-reason d-none" placeholder="Reason (shown to employee)" maxlength="500">
                                        @else
                                        <span class="text-muted small">{{ $mine ? 'done' : 'not yours' }}</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAL</td>
                                    <td class="text-end">{{ number_format($claim->total_amount, 2) }}</td>
                                    <td class="text-end">{{ number_format($claim->total_gst, 2) }}</td>
                                    <td class="text-end text-primary">{{ number_format($claim->total_with_gst, 2) }}</td>
                                    <td colspan="3"></td>
                                </tr>
                                <tr class="payable-row d-none">
                                    <td colspan="5" class="text-end text-success">YOUR ITEMS' PAYABLE (after your rejections)</td>
                                    <td class="text-end text-success fw-bold payable-amount" data-grand="{{ $claim->items->where('approver_id', $employee->id)->sum('total_with_gst') }}"></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reject-{{ $claim->id }}">
                            <i class="bi bi-x-octagon me-1"></i>Reject All My Items
                        </button>
                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Approve My Items</button>
                    </div>
                </form>

                {{-- Reject all of MY items on this claim (separate form) --}}
                <div class="collapse mt-2" id="reject-{{ $claim->id }}">
                    <form action="{{ route('user.claims.team.reject', $claim) }}" method="POST">
                        @csrf
                        <label class="form-label small text-danger mb-1"><i class="bi bi-exclamation-circle me-1"></i>Reject <strong>all your items</strong> on this claim — reason (the employee will see this)</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="remarks" class="form-control" placeholder="e.g., These aren't valid business expenses." required maxlength="1000">
                            <button class="btn btn-danger text-nowrap"><i class="bi bi-x-circle me-1"></i>Confirm Reject</button>
                        </div>
                    </form>
                </div>
            </div>
            @empty
            <div class="text-center text-muted py-5">
                <i class="bi bi-check2-circle text-success" style="font-size:2.5rem;"></i>
                <p class="mt-2 mb-0">All caught up — no pending claims to review.</p>
            </div>
            @endforelse
        </div>
    </div>

    {{-- ── Approval History — categorized by status ── --}}
    @if($reviewedCount > 0)
    @php
        $history    = $historyClaims->sortByDesc('updated_at')->values();
        $histApprov = $history->whereIn('status', ['manager_approved', 'hr_approved', 'paid']);
        $histReject = $history->whereIn('status', ['manager_rejected', 'hr_rejected']);
    @endphp
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0 d-flex align-items-center">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-muted"></i>Approval History</h5>
        </div>
        <div class="card-body">
            <ul class="nav nav-pills flex-wrap gap-1 mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#th-all" type="button" role="tab">All <span class="badge bg-secondary ms-1">{{ $history->count() }}</span></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#th-approved" type="button" role="tab">Approved <span class="badge bg-success ms-1">{{ $histApprov->count() }}</span></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#th-rejected" type="button" role="tab">Rejected <span class="badge bg-danger ms-1">{{ $histReject->count() }}</span></button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="th-all" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $history, 'showEmployee' => true, 'emptyText' => 'No reviewed claims yet.'])
                </div>
                <div class="tab-pane fade" id="th-approved" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $histApprov, 'showEmployee' => true, 'emptyText' => 'No approved claims yet.'])
                </div>
                <div class="tab-pane fade" id="th-rejected" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $histReject, 'showEmployee' => true, 'emptyText' => 'No rejected claims.'])
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

@include('partials.confirm-modal')
@include('partials.item-review-js')
@include('partials.item-verify-js')
@endsection
