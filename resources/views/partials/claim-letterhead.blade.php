{{--
    Company letterhead for the Expenses Claims Form — used on the My Claims header
    and the printable claim report. Vars: $company (App\Models\Company|null),
    $employee, and optionals: $event, $showRules (bool), $claimDate.
--}}
<div class="d-flex justify-content-between align-items-start mb-2">
    <div class="small">
        <div class="fw-bold">{{ $company->name ?? ($employee->company ?? 'Company') }}</div>
        @if($company && $company->address)
        <div class="text-muted" style="white-space:pre-line;line-height:1.3;">{{ $company->address }}</div>
        @endif
    </div>
    @if($company && $company->logoUrl())
    <img src="{{ $company->logoUrl() }}" alt="{{ $company->name }}" style="max-height:48px;max-width:200px;object-fit:contain;">
    @endif
</div>

<h5 class="text-center fw-bold text-uppercase my-3" style="letter-spacing:1px;">Expenses Claims Form</h5>

@if($showRules ?? false)
<ul class="small text-muted fst-italic mb-3" style="line-height:1.5;list-style:none;padding-left:0;">
    <li>- All supporting documents should be submitted with form for approval.</li>
    <li>- Your supporting documents should be arrange and attached accordingly on an A4 paper.</li>
    <li>- All claims submission should be signed (incl. yourself) and approved by your Reporting Manager prior to submitting to HR.</li>
    <li>- Submission of claims to be submitted to HR/Finance by {{ \App\Models\ExpenseClaimPolicy::forCompany($employee->company)->submission_deadline_day ?? 22 }}nd of the month for processing. Any late submission will be process next month.</li>
</ul>
@endif

<div class="row small mb-3">
    <div class="col-md-8">
        <div class="d-flex"><div class="fw-semibold" style="width:110px;">Name :</div><div class="border-bottom flex-grow-1">{{ $employee->full_name }}</div></div>
        <div class="d-flex mt-2"><div class="fw-semibold" style="width:110px;">Department :</div><div class="border-bottom flex-grow-1">{{ $employee->department ?? '—' }}</div></div>
        <div class="d-flex mt-2"><div class="fw-semibold" style="width:110px;">Event :</div><div class="border-bottom flex-grow-1">{{ $event ?? '—' }}</div></div>
    </div>
    <div class="col-md-4">
        <div class="d-flex"><div class="fw-semibold" style="width:60px;">Date :</div><div class="border-bottom flex-grow-1">{{ ($claimDate ?? now())->format('jS F Y') }}</div></div>
    </div>
</div>
