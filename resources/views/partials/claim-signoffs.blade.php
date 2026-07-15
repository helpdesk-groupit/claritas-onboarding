{{--
    Digital sign-off block for the Expenses Claims Form. Replaces the paper
    "Signature / Date" lines with the recorded system action (who + when) for each
    stage. $claim required; $approver optional (the per-approver printed report).
--}}
@php
    $appr = ($approver ?? null) ?? $claim->managerApprover ?? $claim->manager ?? null;
    $apprDetails = collect([$appr?->designation, $appr?->department, $appr?->company])->filter()->implode(' · ');

    // Manager stage outcome. A manager-approved claim that HR later rejects/reverses still shows
    // the manager's approval (their sign-off stands); only a manager_rejected claim shows rejected.
    $mgrRejected = $claim->status === 'manager_rejected';
    $mgrApproved = $claim->manager_approved_at && in_array($claim->status, ['manager_approved', 'hr_approved', 'hr_rejected', 'reversed', 'paid']);

    // HR approver/rejecter: a User; pull dept/designation/company from their linked employee record.
    $hrAppr = $claim->hrApprover ?? null;
    $hrEmp = $hrAppr?->employee ?? null;
    $hrName = $hrEmp?->full_name ?? $hrAppr?->name;
    $hrDetails = collect([$hrEmp?->designation, $hrEmp?->department, $hrEmp?->company])->filter()->implode(' · ');

    // HR stage outcome — show the HR person's name + details whether they approved OR rejected.
    // A REVERSED claim keeps its HR approval on show (it WAS fully approved) and adds a reversal
    // line beneath it — reversing does not erase the approval sign-off.
    $hrReversed = $claim->status === 'reversed';
    $hrApproved = $claim->hr_approved_at && in_array($claim->status, ['hr_approved', 'paid', 'reversed']);
    $hrRejected = $claim->status === 'hr_rejected';
    $hrActed = $hrApproved || $hrRejected;
@endphp
<div class="row mt-4">
    <div class="col-6">
        <div>Approving Manager :- {{ $appr->full_name ?? '—' }}</div>
        @if($apprDetails)<div class="small text-muted">{{ $apprDetails }}</div>@endif
        <div class="mt-1 small">
            @if($mgrApproved)
            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Approved digitally &mdash; {{ $claim->manager_approved_at->format('d/m/Y, g:ia') }}</span>
            @elseif($mgrRejected)
            <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Rejected digitally &mdash; {{ $claim->manager_approved_at?->format('d/m/Y, g:ia') }}</span>
            @else
            <span class="text-muted fst-italic">Awaiting manager approval</span>
            @endif
        </div>
    </div>
    <div class="col-6">
        {{-- HR-checked: the actual HR approver/rejecter (Manager or Executive), shown once they act. --}}
        <div>Checked by :- {{ $hrActed ? ($hrName ?? '(HR)') : '(HR)' }}</div>
        @if($hrActed && $hrDetails)<div class="small text-muted">{{ $hrDetails }}</div>@endif
        <div class="mt-1 small">
            @if($hrApproved)
            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>Approved digitally &mdash; {{ $claim->hr_approved_at->format('d/m/Y, g:ia') }}</span>
            @elseif($hrRejected)
            <span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Rejected digitally &mdash; {{ $claim->hr_approved_at?->format('d/m/Y, g:ia') }}</span>
            @else
            <span class="text-muted fst-italic">Pending HR</span>
            @endif
            {{-- Reversal stands ALONGSIDE the approval — the approval is not erased. --}}
            @if($hrReversed)
            <span class="d-block" style="color:#b45309;"><i class="bi bi-arrow-counterclockwise me-1"></i>Reversed &mdash; {{ $claim->reversed_at?->format('d/m/Y, g:ia') }}@if($claim->reverse_remarks) ({{ $claim->reverse_remarks }})@endif</span>
            @endif
        </div>
    </div>
</div>
<div class="small text-muted mt-3 fst-italic">
    <i class="bi bi-shield-check me-1"></i>Digital sign-off &mdash; each action above is the recorded system action (name + timestamp), held in the claim's audit trail. No physical signature is required.
</div>
