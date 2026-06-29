@extends('layouts.app')
@section('title', 'Claim Detail — ' . $claim->claim_number)
@section('page-title', 'Claim Detail')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <a href="{{ route('hr.claims.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back to Claims
        </a>
        <a href="{{ route('user.claims.pdf', $claim) }}" class="btn btn-sm btn-outline-danger">
            <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
        </a>
    </div>

    @include('partials.claim-review-summary', ['claim' => $claim])

    {{-- The claim AS a report (#11): letterhead form + itemised table + digital sign-offs,
         exactly as it prints — not a bare item list. --}}
    @php
        $canReview = $claim->status === 'manager_approved' && Auth::user()->canManageClaims();
    @endphp
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Claim Report</h6>
            <a href="{{ route('user.claims.report-print', $claim) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer me-1"></i>Print / full report
            </a>
        </div>

        <div class="card-body">
            @include('partials.claim-report-form', [
                'claim' => $claim,
                'company' => $company ?? null,
                'items' => $claim->items,
                'approver' => $claim->manager ?? $claim->managerApprover,
                'padRows' => false,
            ])
        </div>

        {{-- Reviewer checks & verification — collapsed so the report stays the focus,
             but HR can still run per-item OCR/mileage verification before approving. --}}
        <div class="card-body border-top">
            <button class="btn btn-sm btn-outline-secondary mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#hrVerifyPanel">
                <i class="bi bi-shield-check me-1"></i>Reviewer checks & verify items
            </button>
            <div class="collapse" id="hrVerifyPanel">
                <div class="list-group list-group-flush">
                    @foreach($claim->items as $i => $item)
                    <div class="list-group-item px-0">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <div>
                                <span class="fw-semibold">{{ $i + 1 }}. {{ $item->expense_date->format('d/m/Y') }} — {{ $item->description }}</span>
                                <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis ms-1">{{ $item->category->name ?? '—' }}</span>
                                <span class="ms-1 text-muted">RM {{ number_format($item->total_with_gst, 2) }}</span>
                                @if($item->approver)
                                <span class="small ms-1"><i class="bi bi-person-check me-1 text-muted"></i>{{ $item->approver->full_name }}:
                                    @if($item->manager_status === 'approved')<span class="text-success fw-semibold">approved</span>@else<span class="text-secondary">pending</span>@endif
                                </span>
                                @endif
                                @include('partials.claim-item-checks', ['item' => $item])
                            </div>
                            <div>
                                @if($item->receipt_path)
                                <a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-paperclip me-1"></i>Receipt</a>
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if($canReview)
        <div class="card-footer bg-white d-flex gap-2 justify-content-end flex-wrap">
            <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#hrRejectForm">
                <i class="bi bi-x-octagon me-1"></i>Reject Claim
            </button>
            <form action="{{ route('hr.claims.approve', $claim) }}" method="POST" class="js-confirm d-inline"
                  data-confirm="HR approve this entire claim for payout?" data-confirm-title="HR approve" data-confirm-ok="HR Approve" data-confirm-variant="success">
                @csrf
                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>HR Approve</button>
            </form>
        </div>
        <div class="collapse px-3 pb-3" id="hrRejectForm">
            <form action="{{ route('hr.claims.reject', $claim) }}" method="POST">
                @csrf
                <label class="form-label small text-danger mb-1">Rejecting returns the <strong>entire</strong> claim to the employee to fix and resubmit — reason (optional; the employee will see this)</label>
                <div class="input-group">
                    <input type="text" name="remarks" class="form-control" placeholder="e.g., Office Equipment receipt is missing — please attach and resubmit." maxlength="1000">
                    <button class="btn btn-danger text-nowrap">Confirm Reject (whole claim)</button>
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
@include('partials.item-verify-js')
@endsection
