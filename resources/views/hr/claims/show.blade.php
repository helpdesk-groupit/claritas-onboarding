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
                    <div>{{ $claim->submitted_at?->format('d/m/Y H:i') ?? '—' }}</div>
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
                        by {{ $claim->managerApprover->full_name ?? '—' }}
                        on {{ $claim->manager_approved_at->format('d/m/Y H:i') }}
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
                        on {{ $claim->hr_approved_at->format('d/m/Y') }}
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

    {{-- Employee spend context (#7) --}}
    @isset($spendStats)
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-2">
            <h6 class="mb-0"><i class="bi bi-person-lines-fill me-2"></i>{{ $claim->employee->full_name ?? 'Employee' }} — {{ $spendStats['year'] }} claim history</h6>
        </div>
        <div class="card-body py-3">
            <div class="row text-center g-2">
                <div class="col-6 col-md-3"><div class="fw-bold text-success fs-5">RM {{ number_format($spendStats['approved_total'], 2) }}</div><div class="small text-muted">Approved this year</div></div>
                <div class="col-6 col-md-3"><div class="fw-bold text-warning fs-5">RM {{ number_format($spendStats['pending_total'], 2) }}</div><div class="small text-muted">Pending</div></div>
                <div class="col-6 col-md-3"><div class="fw-bold fs-5">{{ $spendStats['claim_count'] }}</div><div class="small text-muted">Claims submitted</div></div>
                <div class="col-6 col-md-3"><div class="fw-bold fs-5">RM {{ number_format($spendStats['avg_claim'], 2) }}</div><div class="small text-muted">Avg approved claim</div></div>
            </div>
        </div>
    </div>
    @endisset

    @include('partials.claim-review-summary', ['claim' => $claim])

    {{-- Items Table (with per-item review when HR can act) --}}
    @php
        $canReview = $claim->status === 'manager_approved' && Auth::user()->canManageClaims();
        $showReviewCol = $canReview || $claim->hasRejectedItems();
    @endphp
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-list-ul me-2"></i>Claim Items ({{ $claim->item_count }})</h6>
            @if($canReview)<small class="text-muted">Tick <span class="text-danger">Reject?</span> to exclude an item from the payout</small>@endif
        </div>

        @if($canReview)
        <form action="{{ route('hr.claims.approve', $claim) }}" method="POST" class="js-confirm item-review-form"
              data-confirm="HR approve this claim? Any item marked “Reject” is excluded from the payout; the rest are approved."
              data-confirm-title="HR approve" data-confirm-ok="HR Approve" data-confirm-variant="success">
            @csrf
        @endif

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
                            @if($showReviewCol)<th class="text-center {{ $canReview ? 'text-danger' : '' }}">{{ $canReview ? 'Reject?' : 'Review' }}</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($claim->items as $i => $item)
                        @php $rejected = $item->isRejected(); @endphp
                        <tr class="review-row {{ $rejected && ! $canReview ? 'table-danger' : '' }}">
                            <td>{{ $i + 1 }}</td>
                            <td>{{ $item->expense_date->format('d/m/Y') }}</td>
                            <td>
                                {{ $item->description }}
                                @if($item->approver)
                                <div class="small mt-1">
                                    <i class="bi bi-person-check me-1 text-muted"></i><span class="text-muted">{{ $item->approver->full_name }}:</span>
                                    @if($item->manager_status === 'approved')<span class="text-success fw-semibold">approved</span>
                                    @elseif($item->manager_status === 'rejected')<span class="text-danger fw-semibold">rejected</span>@if($item->manager_remarks)<span class="text-muted"> — {{ $item->manager_remarks }}</span>@endif
                                    @else<span class="text-secondary">pending</span>@endif
                                </div>
                                @endif
                                @include('partials.claim-item-checks', ['item' => $item])
                            </td>
                            <td>{{ $item->project_client ?? '—' }}</td>
                            <td><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">{{ $item->category->name ?? '—' }}</span></td>
                            <td class="text-end">{{ number_format($item->amount, 2) }}</td>
                            <td class="text-end">{{ number_format($item->gst_amount, 2) }}</td>
                            <td class="text-end fw-bold item-total {{ $rejected && ! $canReview ? 'text-decoration-line-through text-muted' : '' }}" data-total="{{ $item->total_with_gst }}">{{ number_format($item->total_with_gst, 2) }}</td>
                            <td>
                                @if($item->receipt_path)
                                <a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank" class="text-primary"><i class="bi bi-paperclip"></i> View</a>
                                @else <span class="text-muted">—</span> @endif
                            </td>
                            @if($canReview)
                            <td class="text-center" style="min-width:170px;">
                                <input type="checkbox" class="form-check-input reject-toggle border-danger" name="rejected_items[]" value="{{ $item->id }}" {{ $rejected ? 'checked' : '' }} style="width:1.7em;height:1.7em;cursor:pointer;accent-color:#dc3545;" title="Reject this item">
                                <input type="text" name="item_remarks[{{ $item->id }}]" class="form-control form-control-sm mt-1 reject-reason {{ $rejected ? '' : 'd-none' }}" value="{{ $rejected ? $item->remarks : '' }}" placeholder="Reason (shown to employee)" maxlength="500">
                            </td>
                            @elseif($showReviewCol)
                            <td>
                                @if($rejected)
                                <span class="badge bg-danger">Rejected</span>
                                @if($item->remarks)<div class="small text-muted">{{ $item->remarks }}</div>@endif
                                @else
                                <span class="badge bg-success-subtle text-success-emphasis">Approved</span>
                                @endif
                            </td>
                            @endif
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-bold">
                            <td colspan="5" class="text-end">GRAND TOTAL</td>
                            <td class="text-end">RM {{ number_format($claim->total_amount, 2) }}</td>
                            <td class="text-end">RM {{ number_format($claim->total_gst, 2) }}</td>
                            <td class="text-end text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</td>
                            <td @if($showReviewCol) colspan="2" @endif></td>
                        </tr>
                        @if($showReviewCol)
                        <tr class="payable-row {{ $canReview ? 'd-none' : '' }}">
                            <td colspan="7" class="text-end text-success">PAYABLE (after rejections)</td>
                            <td class="text-end text-success fw-bold payable-amount" data-grand="{{ $claim->total_with_gst }}">@if(! $canReview)RM {{ number_format($claim->approvedTotal(), 2) }}@endif</td>
                            <td colspan="2"></td>
                        </tr>
                        @endif
                    </tfoot>
                </table>
            </div>
        </div>

        @if($canReview)
            <div class="card-footer bg-white d-flex gap-2 justify-content-end">
                <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#hrRejectForm">
                    <i class="bi bi-x-octagon me-1"></i>Reject Whole Claim
                </button>
                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>HR Approve</button>
            </div>
        </form>
        <div class="collapse px-3 pb-3" id="hrRejectForm">
            <form action="{{ route('hr.claims.reject', $claim) }}" method="POST">
                @csrf
                <label class="form-label small text-danger mb-1">Reject the <strong>entire</strong> claim — reason (the employee will see this)</label>
                <div class="input-group">
                    <input type="text" name="remarks" class="form-control" placeholder="Reason for rejecting the whole claim" required maxlength="1000">
                    <button class="btn btn-danger text-nowrap">Confirm Reject</button>
                </div>
            </form>
        </div>
        @endif
    </div>

    {{-- Payroll Linkage --}}
    @if($claim->status === 'paid' && ($claim->payslip_id || $claim->pay_run_id))
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="bi bi-link-45deg me-2"></i>Payroll Linkage</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @if($claim->pay_run_id && $claim->payRun)
                <div class="col-md-4">
                    <label class="text-muted small">Pay Run</label>
                    <div class="fw-semibold">
                        {{ $claim->payRun->reference ?? 'Pay Run #' . $claim->pay_run_id }}
                        <span class="badge bg-success ms-1">Processed</span>
                    </div>
                    <small class="text-muted">{{ $claim->payRun->period_label ?? \Carbon\Carbon::create($claim->year, $claim->month)->format('F Y') }}</small>
                </div>
                @endif
                @if($claim->payslip_id && $claim->payslip)
                <div class="col-md-4">
                    <label class="text-muted small">Payslip</label>
                    <div class="fw-semibold">
                        <a href="{{ route('hr.payroll.payslip', $claim->payslip_id) }}" class="text-decoration-none">
                            {{ $claim->payslip->payslip_number ?? 'Payslip #' . $claim->payslip_id }}
                        </a>
                    </div>
                </div>
                @endif
                <div class="col-md-4">
                    <label class="text-muted small">Reimbursement Amount</label>
                    <div class="fw-semibold text-success">RM {{ number_format($claim->total_with_gst, 2) }}</div>
                    <small class="text-muted">Non-taxable — excluded from statutory calculation</small>
                </div>
            </div>
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

@include('partials.confirm-modal')
@include('partials.item-review-js')
@include('partials.item-verify-js')
@endsection
