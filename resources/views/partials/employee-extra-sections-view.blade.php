{{--
    Reusable VIEW partial for Education, Spouse, Emergency Contacts, Child Registration.
    Required variables: $employee (Employee model with relationships loaded)
    Optional: $showConsent (bool) — show consent timestamp block
--}}
@php
    $edu      = $employee->educationHistories ?? collect();
    $spouses  = $employee->spouseDetails ?? collect();
    $contacts = $employee->emergencyContacts ?? collect();
    $children = $employee->childRegistration;

    // For employees activated before relationship tables were populated, fall back to staging JSON
    if (($spouses->isEmpty() || $contacts->isEmpty() || !$children) && $employee->onboarding_id) {
        $stagingJson = $employee->onboarding?->personalDetail?->invite_staging_json;
        if ($stagingJson) {
            $stagingData = json_decode($stagingJson, true) ?: [];
            if ($spouses->isEmpty() && !empty($stagingData['spouses'])) {
                $spouses = collect($stagingData['spouses'])
                    ->filter(fn($s) => !empty($s['name']))
                    ->map(fn($s) => (object)array_merge([
                        'name' => null, 'nric_no' => null, 'tel_no' => null,
                        'occupation' => null, 'income_tax_no' => null,
                        'is_working' => false, 'is_disabled' => false, 'address' => null,
                    ], $s))
                    ->values();
            }
            if ($contacts->isEmpty() && !empty($stagingData['emergency'])) {
                $contacts = collect($stagingData['emergency'])
                    ->filter(fn($ec) => !empty($ec['name']))
                    ->map(fn($ec, $order) => (object)array_merge(
                        ['contact_order' => $order, 'name' => null, 'tel_no' => null, 'relationship' => null],
                        $ec
                    ))
                    ->values();
            }
            if (!$children && !empty($stagingData['children'])) {
                $children = (object)array_merge([
                    'cat_a_100'=>0,'cat_a_50'=>0,'cat_b_100'=>0,'cat_b_50'=>0,
                    'cat_c_100'=>0,'cat_c_50'=>0,'cat_d_100'=>0,'cat_d_50'=>0,
                    'cat_e_100'=>0,'cat_e_50'=>0,
                ], $stagingData['children']);
            }
        }
    }
    $cats = [
        'a' => 'a) Children under 18 years old',
        'b' => 'b) Children aged 18 years and above (still studying at the certificate and matriculation level)',
        'c' => 'c) Above 18 years (studying Diploma level or higher in Malaysia or elsewhere)',
        'd' => 'd) Disabled Child below 18 years old',
        'e' => 'e) Disabled Child (studying Diploma level or higher in Malaysia or elsewhere)',
    ];
@endphp

{{-- ── Education & Work History ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">F</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Education &amp; Work History</h6>
    </div>
    <div class="card-body py-3">
        @if($edu->isEmpty())
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No education history recorded.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-borderless align-middle mb-0" style="font-size:13.5px;">
                <thead style="background:#f8fafc;font-size:12px;">
                    <tr>
                        <th class="ps-2">Qualification</th>
                        <th>Institution</th>
                        <th>Year</th>
                        <th>Certificate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($edu as $e)
                    <tr>
                        <td class="ps-2 fw-semibold">{{ $e->qualification }}</td>
                        <td class="text-muted">{{ $e->institution ?? '—' }}</td>
                        <td>{{ $e->year_graduated ?? '—' }}</td>
                        <td>
                            @php
                                $certFiles = $e->certificate_paths ?? ($e->certificate_path ? [$e->certificate_path] : []);
                            @endphp
                            @if(!empty($certFiles))
                                @foreach($certFiles as $ci => $certFile)
                                <a href="{{ asset('storage/'.$certFile) }}" target="_blank"
                                   class="btn btn-outline-primary me-1 mb-1" style="padding:2px 8px;font-size:11px;">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $ci + 1 }}
                                </a>
                                @endforeach
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @php $firstEdu = $edu->first(); @endphp
        @if($firstEdu?->years_experience !== null && $firstEdu->years_experience !== '')
        <div class="mt-2 pt-2 border-top" style="font-size:13px;">
            <span class="text-muted">Years of Working Experience:</span>
            <strong class="ms-1">{{ $firstEdu->years_experience }} {{ $firstEdu->years_experience == 1 ? 'year' : 'years' }}</strong>
        </div>
        @endif
        @endif
    </div>
</div>

{{-- ── Spouse Information ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">G</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Spouse Information</h6>
    </div>
    <div class="card-body py-3">
        @if($spouses->isEmpty())
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No spouse information recorded.</p>
        @else
        @foreach($spouses as $spouse)
        <div class="border rounded p-3 mb-2" style="font-size:13.5px;">
            <div class="row g-0">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted py-1" style="width:46%;padding-left:0;">Name</td>
                            <td class="fw-semibold py-1">{{ $spouse->name }}</td></tr>
                        <tr><td class="text-muted py-1">NRIC No.</td>
                            <td class="py-1">{{ $spouse->nric_no ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Tel No.</td>
                            <td class="py-1">{{ $spouse->tel_no ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Occupation</td>
                            <td class="py-1">{{ $spouse->occupation ?? '—' }}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted py-1" style="width:46%;padding-left:0;">Income Tax No.</td>
                            <td class="py-1">{{ $spouse->income_tax_no ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Working</td>
                            <td class="py-1">{{ $spouse->is_working ? 'Yes' : 'No' }}</td></tr>
                        <tr><td class="text-muted py-1">Disabled</td>
                            <td class="py-1">{{ $spouse->is_disabled ? 'Yes' : 'No' }}</td></tr>
                        <tr><td class="text-muted py-1 align-top">Address</td>
                            <td class="py-1" style="white-space:pre-line;">{{ $spouse->address ?? '—' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
        @endforeach
        @endif
    </div>
</div>

{{-- ── Emergency Contacts ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">H</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Emergency Contacts</h6>
    </div>
    <div class="card-body py-3">
        @if($contacts->isEmpty())
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No emergency contacts recorded.</p>
        @else
        <div class="row g-3">
            @foreach($contacts as $ec)
            <div class="col-md-6">
                <div class="border rounded p-3" style="font-size:13.5px;">
                    <div class="fw-semibold mb-1">Contact {{ $ec->contact_order }}</div>
                    <div><span class="text-muted">Name:</span> {{ $ec->name ?? '—' }}</div>
                    <div><span class="text-muted">Tel:</span> {{ $ec->tel_no ?? '—' }}</div>
                    <div><span class="text-muted">Relationship:</span> {{ $ec->relationship ?? '—' }}</div>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>

{{-- ── Child Registration ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">I</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-heart me-2 text-primary"></i>Child Registration (LHDN Tax Relief)</h6>
    </div>
    <div class="card-body py-3">
        @if(!$children)
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No child registration recorded.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th rowspan="2" style="width:60%;vertical-align:middle;">Number of children according to the category below for tax relief purpose</th>
                        <th colspan="2" class="text-center">Number of children</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="width:110px;">100%<br><small class="fw-normal">(tax relief by self)</small></th>
                        <th class="text-center" style="width:110px;">50%<br><small class="fw-normal">(shared with spouse)</small></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($cats as $key => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="text-center">{{ $children->{"cat_{$key}_100"} ?? 0 }}</td>
                        <td class="text-center">{{ $children->{"cat_{$key}_50"} ?? 0 }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ── Declaration & Consent (read-only, shown when $showConsent is true) ── --}}
@if(!empty($showConsent))
@php
    $consentAt = $employee->consent_given_at;
@endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid {{ $consentAt ? '#16a34a' : '#94a3b8' }};">
        <h6 class="mb-0 fw-bold">
            <i class="bi bi-file-earmark-check me-2 {{ $consentAt ? 'text-success' : 'text-muted' }}"></i>Declaration &amp; Consent
        </h6>
        @if($consentAt)
            <span class="ms-auto badge bg-success bg-opacity-10 text-success border border-success" style="font-size:11px;">
                <i class="bi bi-check-circle me-1"></i>Acknowledged
            </span>
        @else
            <span class="ms-auto badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:11px;">
                <i class="bi bi-clock me-1"></i>Pending
            </span>
        @endif
    </div>
    <div class="card-body py-3">
        {{-- PDPA text always visible --}}
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1.1rem;font-size:13px;line-height:1.8;" class="mb-3">
            <p class="fw-semibold mb-2">Personal Data Protection Act (PDPA) 2010 — Consent</p>
            <p class="mb-2">I hereby declare that all information provided above is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
            <p class="mb-2">I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
            <p class="mb-0">I also agree to promptly notify the HRA Department of any changes to the information provided above, including updates to my contact details, banking information, or personal particulars.</p>
        </div>
        @if($consentAt)
        <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;">
            <i class="bi bi-check-circle-fill text-success" style="font-size:22px;"></i>
            <div>
                <div class="fw-semibold text-success small">Consent Acknowledged</div>
                <div class="text-muted small">
                    Submitted on {{ $consentAt->format('d M Y, h:i A') }}
                    @if($employee->consent_ip)
                        — IP: {{ $employee->consent_ip }}
                    @endif
                </div>
            </div>
        </div>
        @else
        <div class="d-flex align-items-center gap-2 text-muted small p-2 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
            <i class="bi bi-clock text-secondary"></i> Awaiting employee acknowledgement.
        </div>
        @endif
    </div>
</div>
@endif