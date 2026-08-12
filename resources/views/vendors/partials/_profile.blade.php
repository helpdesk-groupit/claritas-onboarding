{{-- Vendor profile details. Read-only; editing goes through vendors.edit. --}}
<div class="row g-4">
    <div class="col-lg-6">
        <div class="vnd-label">Company</div>
        <div class="vnd-value mb-3">{{ $vendor->name }}</div>

        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <div class="vnd-label">Company Registration No.</div>
                <div class="vnd-value {{ $vendor->company_registration_no ? '' : 'vnd-value-muted' }}">{{ $vendor->company_registration_no ?: 'Not recorded' }}</div>
            </div>
            <div class="col-sm-6">
                <div class="vnd-label">Industry</div>
                <div class="vnd-value {{ $vendor->industry ? '' : 'vnd-value-muted' }}">{{ $vendor->industry ? $vendor->industryLabel() : 'Not recorded' }}</div>
            </div>
        </div>

        <div class="vnd-label">Address</div>
        <div class="vnd-value mb-3 {{ $vendor->address ? '' : 'vnd-value-muted' }}" style="white-space:pre-line;">{{ $vendor->address ?: 'Not recorded' }}</div>

        <div class="row g-3">
            <div class="col-sm-4">
                <div class="vnd-label">Contact Number</div>
                <div class="vnd-value {{ $vendor->contact_number ? '' : 'vnd-value-muted' }}">{{ $vendor->contact_number ?: '—' }}</div>
            </div>
            <div class="col-sm-4">
                <div class="vnd-label">Company Email</div>
                <div class="vnd-value {{ $vendor->email ? '' : 'vnd-value-muted' }}">
                    @if($vendor->email)
                        <a href="mailto:{{ $vendor->email }}" class="text-decoration-none">{{ $vendor->email }}</a>
                    @else
                        —
                    @endif
                </div>
            </div>
            <div class="col-sm-4">
                <div class="vnd-label">Website</div>
                <div class="vnd-value {{ $vendor->website ? '' : 'vnd-value-muted' }}">{{ $vendor->website ?: '—' }}</div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="row g-3 mb-3">
            <div class="col-sm-6">
                <div class="vnd-label">SST Number</div>
                <div class="vnd-value {{ $vendor->sst_number ? '' : 'vnd-value-muted' }}">{{ $vendor->sst_number ?: 'Not recorded' }}</div>
            </div>
            <div class="col-sm-6">
                <div class="vnd-label">TIN</div>
                <div class="vnd-value {{ $vendor->tin_number ? '' : 'vnd-value-muted' }}">{{ $vendor->tin_number ?: 'Not recorded' }}</div>
            </div>
            <div class="col-12">
                <div class="vnd-label">SST Category</div>
                <div class="vnd-value {{ $vendor->sst_category ? '' : 'vnd-value-muted' }}">{{ $vendor->sst_category ? $vendor->sstCategoryLabel() : 'Not recorded' }}</div>
            </div>
        </div>

        <div class="vnd-label">Person in Charge</div>
        <div class="vnd-value mb-1 {{ $vendor->pic_name ? '' : 'vnd-value-muted' }}">{{ $vendor->pic_name ?: 'Not recorded' }}</div>
        <div class="vnd-pic-meta mb-3">
            @if($vendor->pic_email)<a href="mailto:{{ $vendor->pic_email }}"><i class="bi bi-envelope me-1"></i>{{ $vendor->pic_email }}</a>@endif
            @if($vendor->pic_phone)<span class="ms-2"><i class="bi bi-telephone me-1"></i>{{ $vendor->pic_phone }}</span>@endif
        </div>

        <div class="vnd-label">Technical Person</div>
        <div class="vnd-value mb-1 {{ $vendor->technical_person_name ? '' : 'vnd-value-muted' }}">{{ $vendor->technical_person_name ?: 'Not recorded' }}</div>
        <div class="vnd-pic-meta mb-3">
            @if($vendor->technical_person_email)<a href="mailto:{{ $vendor->technical_person_email }}"><i class="bi bi-envelope me-1"></i>{{ $vendor->technical_person_email }}</a>@endif
            @if($vendor->technical_person_phone)<span class="ms-2"><i class="bi bi-telephone me-1"></i>{{ $vendor->technical_person_phone }}</span>@endif
        </div>

        @if($vendor->notes)
        <div class="vnd-label">Notes</div>
        <div class="vnd-value" style="white-space:pre-line;">{{ $vendor->notes }}</div>
        @endif
    </div>
</div>

{{-- ── Bank details ─────────────────────────────────────────────────────────
     Full width rather than a column, because this is the payment instruction and
     whoever pays the invoice has to be able to find it without reading the profile
     top to bottom. --}}
@php
    $bankFields = [
        $vendor->bank_name, $vendor->bank_account_name, $vendor->bank_account_number,
        $vendor->bank_branch, $vendor->bank_swift,
    ];
    $anyBank = collect($bankFields)->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp
<hr class="my-4">
<div class="fw-semibold mb-1"><i class="bi bi-bank me-1 text-primary"></i>Bank Details</div>
<div class="text-muted small mb-3">How we pay this vendor. Kept here so a payment instruction isn&rsquo;t read off whichever invoice PDF is to hand.</div>

@if(! $anyBank)
    <div class="ewx-empty"><i class="bi bi-bank"></i>No bank details recorded for this vendor.</div>
@else
    <div class="row g-3">
        <div class="col-sm-6 col-lg-4">
            <div class="vnd-label">Bank</div>
            <div class="vnd-value {{ $vendor->bank_name ? '' : 'vnd-value-muted' }}">{{ $vendor->bank_name ?: 'Not recorded' }}</div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="vnd-label">Account Holder</div>
            <div class="vnd-value {{ $vendor->bank_account_name ? '' : 'vnd-value-muted' }}">{{ $vendor->bank_account_name ?: 'Not recorded' }}</div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="vnd-label">Account Number</div>
            <div class="vnd-value {{ $vendor->bank_account_number ? '' : 'vnd-value-muted' }}">{{ $vendor->bank_account_number ?: 'Not recorded' }}</div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="vnd-label">Branch</div>
            <div class="vnd-value {{ $vendor->bank_branch ? '' : 'vnd-value-muted' }}">{{ $vendor->bank_branch ?: '—' }}</div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="vnd-label">SWIFT / BIC</div>
            <div class="vnd-value {{ $vendor->bank_swift ? '' : 'vnd-value-muted' }}">{{ $vendor->bank_swift ?: '—' }}</div>
        </div>
    </div>

    {{-- A half-entered account looks recorded at a glance and cannot be paid from. Say so
         rather than letting the grid above imply the instruction is complete. --}}
    @if(! $vendor->hasBankDetails())
        <div class="alert alert-warning py-2 mt-3 mb-0" style="font-size:12.5px;">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Incomplete &mdash; a payment needs both the <strong>bank name</strong> and the <strong>account number</strong>.
        </div>
    @endif
@endif
