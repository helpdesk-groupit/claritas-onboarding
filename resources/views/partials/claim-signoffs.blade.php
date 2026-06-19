{{--
    Digital sign-off block for the Expenses Claims Form. Replaces the paper
    "Signature / Date" lines with the recorded system action (who + when) for each
    stage. $claim required; $approver optional (the per-approver printed report).
--}}
@php
    $appr = ($approver ?? null) ?? $claim->managerApprover ?? $claim->manager ?? null;
    $mgrDone = $claim->manager_approved_at && in_array($claim->status, ['manager_approved', 'hr_approved', 'paid']);
    $hrDone = $claim->hr_approved_at && in_array($claim->status, ['hr_approved', 'paid']);
@endphp
<div class="row mt-4">
    <div class="col-6">
        <div>Staff :- {{ $claim->employee->full_name }}</div>
        <div class="mt-1 small">
            @if($claim->submitted_at)
            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Submitted electronically &mdash; {{ $claim->submitted_at->format('d/m/Y, g:ia') }}</span>
            @else
            <span class="text-muted fst-italic">Draft &mdash; not yet submitted</span>
            @endif
        </div>

        <div class="mt-3">Approving Manager :- {{ $appr->full_name ?? '—' }}</div>
        <div class="mt-1 small">
            @if($mgrDone)
            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Approved electronically &mdash; {{ $claim->manager_approved_at->format('d/m/Y, g:ia') }}</span>
            @elseif($claim->status === 'manager_rejected')
            <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Returned to staff &mdash; {{ $claim->manager_approved_at?->format('d/m/Y') }}</span>
            @else
            <span class="text-muted fst-italic">Awaiting manager approval</span>
            @endif
        </div>
    </div>
    <div class="col-6">
        <div>Checked by :- {{ optional($claim->hrApprover)->name ?? '(HR / Finance)' }}</div>
        <div class="mt-1 small">
            @if($hrDone)
            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Approved electronically &mdash; {{ $claim->hr_approved_at->format('d/m/Y') }}</span>
            @elseif($claim->status === 'hr_rejected')
            <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Rejected &mdash; {{ $claim->hr_approved_at?->format('d/m/Y') }}</span>
            @else
            <span class="text-muted fst-italic">Pending HR / Finance</span>
            @endif
        </div>

        <div class="mt-3">Payment processed :-</div>
        <div class="mt-1 small">
            @if($claim->status === 'paid')
            <span class="text-success"><i class="bi bi-cash-coin me-1"></i>Paid</span>
            @else
            <span class="text-muted fst-italic">Pending payment</span>
            @endif
        </div>
    </div>
</div>
<div class="small text-muted mt-3 fst-italic">
    <i class="bi bi-shield-check me-1"></i>Digitally approved &mdash; each sign-off above is the recorded system action (name + timestamp), held in the claim's audit trail. No physical signature is required.
</div>
