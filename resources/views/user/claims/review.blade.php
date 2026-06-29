@extends('layouts.app')
@section('title', 'Review Claim — ' . $claim->claim_number)
@section('page-title', 'Review Claim')

@section('content')
<div class="container-fluid py-3" style="max-width:980px;">

    {{-- Toolbar --}}
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <a href="{{ $backUrl }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to claims</a>
        <a href="{{ route('user.claims.pdf', $claim) }}" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>Download PDF</a>
    </div>

    {{-- Title --}}
    <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
        <h4 class="mb-0"><i class="bi bi-receipt me-2"></i>{{ $claim->event ?: 'Claim' }} <span class="text-muted">— {{ $claim->claim_number }}</span></h4>
        <span class="d-inline-flex flex-wrap gap-1">
            @if($claim->correction_of_id)
            <span class="badge bg-info text-dark" title="Resubmission of a rejected claim ({{ optional($claim->correctionOf)->claim_number ?? 'previously rejected' }})"><i class="bi bi-arrow-repeat me-1"></i>Resubmitted</span>
            @endif
            @foreach($claim->stageBadges() as $sb)
            <span class="badge bg-{{ $sb['class'] }} {{ $sb['class'] === 'warning' ? 'text-dark' : '' }}">{{ $sb['label'] }}</span>
            @endforeach
        </span>
    </div>

    {{-- The claim AS a report (letterhead + itemised table + sign-offs + attachments) --}}
    <div class="claim-form-wrap mb-4">
        @include('partials.claim-report-form', [
            'claim' => $claim,
            'company' => $company,
            'items' => $items,
            'approver' => $approver,
            'padRows' => false,
            'showAttachments' => true,
            'reviewReject' => ($stage === 'manager' || $stage === 'hr'),
        ])
    </div>

    @if($stage === 'manager' || $stage === 'hr')
    {{-- ── Decision panel ── --}}
    <div class="card shadow-sm border-0 mb-4" id="decisionPanel">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Your decision
                <span class="text-muted small fw-normal">— {{ $stage === 'hr' ? 'HR approval' : 'Manager / PIC approval' }}</span>
            </h5>
        </div>
        <div class="card-body">
            <div class="d-flex gap-2 flex-wrap">
                <form action="{{ $approveUrl }}" method="POST" class="js-confirm"
                      data-confirm="Approve this claim ({{ $items->count() }} item(s), RM {{ number_format($claim->total_with_gst, 2) }})?{{ $stage === 'hr' ? ' It will be marked HR-approved for payout.' : ' It will go to HR for final approval.' }}"
                      data-confirm-title="Approve claim" data-confirm-ok="Approve" data-confirm-variant="success">
                    @csrf
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Approve Claim</button>
                </form>
                <button type="button" class="btn btn-outline-danger" id="toggleReject"><i class="bi bi-x-octagon me-1"></i>Reject Claim</button>
            </div>

            {{-- Reject form — the overall reason here; per-item comments are written directly on
                 the report above (each flagged item's comment is gathered on submit). --}}
            <div class="collapse mt-3" id="rejectBox">
                <form action="{{ $rejectUrl }}" method="POST" id="rejectForm">
                    @csrf
                    <div class="alert alert-danger py-2 small mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>Rejecting returns the <strong>whole claim</strong> to {{ $claim->employee->full_name ?? 'the employee' }} to fix and resubmit.
                        <div class="mt-1"><i class="bi bi-flag me-1"></i>Scroll up to the report and add a comment under any item that needs fixing — the employee will see those flags.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Overall reason <span class="text-muted small fw-normal">(optional — the employee sees this)</span></label>
                        <input type="text" name="remarks" class="form-control" maxlength="1000"
                               placeholder="e.g., The KLCC mileage isn't a business trip — please remove it and resubmit.">
                    </div>
                    {{-- Per-item comments collected from the report are injected here on submit. --}}
                    <div id="rejectComments"></div>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Confirm Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

</div>

@include('partials.confirm-modal')

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .claim-form-wrap .claim-form { max-width:100%; margin:0; background:#fff; padding:26px 30px; box-shadow:0 1px 4px rgba(0,0,0,.08); border-radius:10px; font-size:.85rem; }
    .claim-form-wrap .attachments { max-width:100%; margin:16px 0 0; }
    .claim-form-wrap .attachment-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:14px; }
    .claim-form-wrap .attachment-card img { max-width:100%; max-height:640px; display:block; margin:8px auto 0; border:1px solid #e2e8f0; }
    /* Per-item comment rows on the report — only shown once "Reject" is engaged. */
    .review-flag-row { display: none; }
    .claim-form-wrap.reject-mode .review-flag-row { display: table-row; }
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    const toggle = document.getElementById('toggleReject');
    const box = document.getElementById('rejectBox');
    const wrap = document.querySelector('.claim-form-wrap');
    const form = document.getElementById('rejectForm');

    // "Reject Claim" → reveal the reject panel AND the per-item comment fields on the report.
    if (toggle && box && window.bootstrap) {
        toggle.addEventListener('click', function () {
            const inst = bootstrap.Collapse.getOrCreateInstance(box);
            inst.toggle();
            if (wrap) wrap.classList.add('reject-mode');
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });
    }
    // Highlight an item row on the report once it has a comment.
    document.querySelectorAll('.report-item-comment').forEach(function (inp) {
        inp.addEventListener('input', function () {
            const row = inp.closest('.review-flag-row');
            if (row) row.style.background = inp.value.trim() ? '#fff7ed' : '';
        });
    });
    // On submit, gather the report's per-item comments into the reject form.
    if (form) {
        form.addEventListener('submit', function () {
            const bin = document.getElementById('rejectComments');
            if (!bin) return;
            bin.innerHTML = '';
            document.querySelectorAll('.report-item-comment').forEach(function (inp) {
                const v = (inp.value || '').trim();
                if (!v) return;
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'item_comments[' + inp.dataset.itemId + ']';
                h.value = v;
                bin.appendChild(h);
            });
        });
    }
})();
</script>
@endpush
@endsection
