@extends('layouts.app')

@section('title', 'Onboarding Details')
@section('page-title', 'Onboarding Details')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ $backRoute ?? route('onboarding.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    @if(Auth::user()->canEditOnboarding())
        <a href="{{ route('onboarding.edit', $onboarding) }}" class="btn btn-sm btn-warning">
            <i class="bi bi-pencil me-1"></i>Edit Record
        </a>
    @endif
    @if($onboarding->aarf)
        <a href="{{ route('aarf.view', $onboarding->aarf->acknowledgement_token) }}" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-box-arrow-up-right me-1"></i>View AARF
        </a>
    @endif
</div>

@php
    $authUser       = Auth::user();
    $profileOwnerId = $onboarding->employee?->user_id;
    $canSeePersonal = in_array($authUser->role, ['hr_manager','hr_executive','hr_intern','it_manager','it_executive','it_intern','superadmin','system_admin'])
                      || ($profileOwnerId && $authUser->id === $profileOwnerId);
    $p = $onboarding->personalDetail;
    $w = $onboarding->workDetail;

    $obShowName       = $p?->full_name ?? $onboarding->employee?->full_name ?? 'New Employee';
    $obShowPicUrl     = $onboarding->employee?->user?->profile_picture_url
                      ?? 'https://ui-avatars.com/api/?name=' . urlencode($obShowName) . '&background=2563eb&color=fff&size=200';
    $obStatusColors   = ['active'=>'success','resigned'=>'danger','terminated'=>'danger','contract_ended'=>'secondary'];
    $obStatus         = $onboarding->employee?->employment_status ?? 'pending';
    $obStatusBg       = match($obStatus) {
        'active'         => 'success',
        'resigned','terminated','contract_ended' => 'danger',
        default          => 'warning text-dark',
    };
    $a = $onboarding->assetProvisioning;

    $staging         = ($p && $p->invite_staging_json) ? json_decode($p->invite_staging_json, true) : [];
    $stagingEdu      = $staging['education'] ?? [];
    $stagingSpouses  = $staging['spouses'] ?? [];
    $stagingEc       = $staging['emergency'] ?? [];
    $stagingChildren = $staging['children'] ?? [];

    $lhdnCats = [
        'a' => 'a) Children under 18 years old',
        'b' => 'b) Children aged 18 years and above (still studying at the certificate and matriculation level)',
        'c' => 'c) Above 18 years (studying Diploma level or higher in Malaysia or elsewhere)',
        'd' => 'd) Disabled Child below 18 years old',
        'e' => 'e) Disabled Child (studying Diploma level or higher in Malaysia or elsewhere)',
    ];
@endphp

{{-- ── Profile Header ────────────────────────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-body d-flex align-items-center gap-4 py-3">
        <img src="{{ $obShowPicUrl }}" alt="{{ $obShowName }}"
             class="rounded-circle border shadow-sm flex-shrink-0"
             style="width:80px;height:80px;object-fit:cover;">
        <div class="flex-fill">
            <h5 class="fw-bold mb-1">{{ $obShowName }}</h5>
            @if($p?->preferred_name && $p->preferred_name !== $p->full_name)
                <p class="text-muted mb-1 small">Known as: <em>{{ $p->preferred_name }}</em></p>
            @endif
            <p class="text-muted mb-2 small">{{ $onboarding->employee?->designation ?? $w?->designation ?? '—' }}</p>
            <div class="d-flex flex-wrap gap-1">
                @if($w?->company)
                    <span class="badge bg-primary">{{ $w->company }}</span>
                @endif
                @if($w?->department)
                    <span class="badge bg-secondary">{{ $w->department }}</span>
                @endif
                <span class="badge bg-{{ $obStatusBg }}">
                    {{ ucfirst(str_replace('_', ' ', $obStatus)) }}
                </span>
            </div>
        </div>
        <div class="text-end text-muted small flex-shrink-0 d-none d-md-block">
            @if($p?->personal_email)
                <div><i class="bi bi-envelope me-1"></i>{{ $p->personal_email }}</div>
            @endif
            @if($w?->start_date)
                <div class="mt-1"><i class="bi bi-calendar me-1"></i>Start: {{ \Carbon\Carbon::parse($w->start_date)->format('d M Y') }}</div>
            @endif
        </div>
    </div>
</div>

{{-- ── Section A — Personal Details ── --}}
@if($canSeePersonal && $p)
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Section A — Personal Details</h6>
    </div>
    <div class="card-body">
        <div class="row g-0">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Full Name</td><td class="fw-semibold py-2">{{ $p->full_name }}</td></tr>
                    <tr><td class="text-muted py-2">NRIC / Passport No.</td><td class="py-2">{{ $p->official_document_id }}</td></tr>
                    <tr><td class="text-muted py-2">Date of Birth</td><td class="py-2">{{ $p->date_of_birth?->format('d M Y') }}</td></tr>
                    <tr><td class="text-muted py-2">Age</td><td class="py-2">{{ $p->date_of_birth ? now()->year - $p->date_of_birth->year : '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Sex</td><td class="py-2">{{ ucfirst($p->sex ?? '') }}</td></tr>
                    <tr><td class="text-muted py-2">Marital Status</td><td class="py-2">{{ ucfirst($p->marital_status ?? '') }}</td></tr>
                    <tr><td class="text-muted py-2">Religion</td><td class="py-2">{{ $p->religion }}</td></tr>
                    <tr><td class="text-muted py-2">Race</td><td class="py-2">{{ $p->race }}</td></tr>
                    <tr><td class="text-muted py-2">Disabled Person</td><td class="py-2">{{ $p->is_disabled ? 'Yes' : 'No' }}</td></tr>
                    <tr><td class="text-muted py-2">Tel No. (H/phone)</td><td class="py-2">{{ $p->personal_contact_number }}</td></tr>
                    <tr><td class="text-muted py-2">Tel No. (House)</td><td class="py-2">{{ $p->house_tel_no ?? '—' }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Personal Email</td><td class="py-2">{{ $p->personal_email }}</td></tr>
                    <tr><td class="text-muted py-2">Bank Account</td><td class="py-2">{{ $p->bank_account_number }}</td></tr>
                    <tr><td class="text-muted py-2">Bank Name</td><td class="py-2">{{ $p->bank_name ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">EPF No.</td><td class="py-2">{{ $p->epf_no ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Income Tax No.</td><td class="py-2">{{ $p->income_tax_no ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">SOCSO No.</td><td class="py-2">{{ $p->socso_no ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2 align-top">Address</td><td class="py-2" style="white-space:pre-line;">{{ $p->residential_address }}</td></tr>
                    <tr><td class="text-muted py-2">Access Role</td><td class="py-2">
                        <span class="badge bg-primary">{{ str_replace('_',' ',ucwords($onboarding->workDetail?->role ?? '—')) }}</span>
                    </td></tr>
                    @if($p->consent_given_at)
                    <tr><td class="text-muted py-2">Consent Given</td><td class="py-2">
                        <span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>{{ $p->consent_given_at->format('d M Y, h:i A') }}</span>
                    </td></tr>
                    @endif
                    @php $allNric = $p->nric_file_paths ?? ($p->nric_file_path ? [$p->nric_file_path] : []); @endphp
                    @if(!empty($allNric))
                    <tr><td class="text-muted py-2">NRIC / Passport Copy</td><td class="py-2">
                        @if(in_array($authUser->role, ['hr_manager','it_manager','superadmin','system_admin']) || ($profileOwnerId && $authUser->id === $profileOwnerId))
                        @foreach($allNric as $idx => $path)
                        <a href="{{ secure_file_url($path) }}" target="_blank"
                           class="btn btn-sm btn-outline-primary me-1 mb-1" style="padding:2px 8px;font-size:12px;">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $idx+1 }}
                        </a>
                        @endforeach
                        @else
                        <span class="text-muted small"><i class="bi bi-lock me-1"></i>{{ count($allNric) }} file(s) — view restricted</span>
                        @endif
                    </td></tr>
                    @endif
                </table>
            </div>
        </div>
    </div>
</div>

{{-- ── Section F — Education & Work History ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Section F — Education &amp; Work History</h6>
    </div>
    <div class="card-body">
        @if(empty($stagingEdu))
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No education history recorded.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-borderless align-middle mb-0" style="font-size:13.5px;">
                <thead style="background:#f8fafc;font-size:12px;">
                    <tr><th class="ps-2">Qualification</th><th>Institution</th><th>Year</th><th>Certificate</th></tr>
                </thead>
                <tbody>
                    @foreach($stagingEdu as $edu)
                    <tr>
                        <td class="ps-2 fw-semibold">{{ $edu['qualification'] }}</td>
                        <td class="text-muted">{{ $edu['institution'] ?? '—' }}</td>
                        <td>{{ $edu['year_graduated'] ?? '—' }}</td>
                        <td>
                            @php
                                $certPaths = $edu['certificate_paths'] ?? (isset($edu['certificate_path']) && $edu['certificate_path'] ? [$edu['certificate_path']] : []);
                            @endphp
                            @if(!empty($certPaths))
                                @if(in_array($authUser->role, ['hr_manager','it_manager','superadmin','system_admin']) || ($profileOwnerId && $authUser->id === $profileOwnerId))
                                @foreach($certPaths as $ci => $certPath)
                                <a href="{{ secure_file_url($certPath) }}" target="_blank"
                                   class="btn btn-sm btn-outline-primary me-1 mb-1" style="padding:2px 8px;font-size:11px;">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $ci + 1 }}
                                </a>
                                @endforeach
                                @else
                                <span class="text-muted small"><i class="bi bi-lock me-1"></i>{{ count($certPaths) }} file(s) — view restricted</span>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(!empty($staging['edu_experience_total']))
        <div class="mt-2 small text-muted">
            <i class="bi bi-briefcase me-1"></i>
            Working Experience: <strong>{{ $staging['edu_experience_total'] }}</strong>
            {{ is_numeric($staging['edu_experience_total']) && $staging['edu_experience_total']==1 ? 'year' : 'years' }}
            (not incl. part-time)
        </div>
        @endif
        @endif
    </div>
</div>

{{-- ── Section G — Spouse Information ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Section G — Spouse Information</h6>
    </div>
    <div class="card-body">
        @if(empty($stagingSpouses))
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No spouse information recorded.</p>
        @else
        @foreach($stagingSpouses as $sp)
        <div class="border rounded p-3 mb-2" style="font-size:13.5px;">
            <div class="row g-0">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted py-1" style="width:46%;padding-left:0;">Name</td><td class="fw-semibold py-1">{{ $sp['name'] }}</td></tr>
                        <tr><td class="text-muted py-1">NRIC No.</td><td class="py-1">{{ $sp['nric_no'] ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Tel No.</td><td class="py-1">{{ $sp['tel_no'] ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Occupation</td><td class="py-1">{{ $sp['occupation'] ?? '—' }}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><td class="text-muted py-1" style="width:46%;padding-left:0;">Income Tax No.</td><td class="py-1">{{ $sp['income_tax_no'] ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Working</td><td class="py-1">{{ ($sp['is_working'] ?? false) ? 'Yes' : 'No' }}</td></tr>
                        <tr><td class="text-muted py-1">Disabled</td><td class="py-1">{{ ($sp['is_disabled'] ?? false) ? 'Yes' : 'No' }}</td></tr>
                        @if(!empty($sp['address']))
                        <tr><td class="text-muted py-1 align-top">Address</td><td class="py-1" style="white-space:pre-line;">{{ $sp['address'] }}</td></tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>
        @endforeach
        @endif
    </div>
</div>

{{-- ── Section H — Emergency Contacts ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Section H — Emergency Contacts</h6>
    </div>
    <div class="card-body">
        @php $hasEc = collect($stagingEc)->filter(fn($e) => !empty($e['name']))->isNotEmpty(); @endphp
        @if(!$hasEc)
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No emergency contacts recorded.</p>
        @else
        <div class="row g-3">
            @foreach($stagingEc as $n => $ec)
            @if(!empty($ec['name']))
            <div class="col-md-6">
                <div class="border rounded p-3" style="font-size:13.5px;">
                    <div class="fw-semibold mb-1">Contact {{ $n }}</div>
                    <div><span class="text-muted">Name:</span> {{ $ec['name'] }}</div>
                    <div><span class="text-muted">Tel:</span> {{ $ec['tel_no'] ?? '—' }}</div>
                    <div><span class="text-muted">Relationship:</span> {{ $ec['relationship'] ?? '—' }}</div>
                </div>
            </div>
            @endif
            @endforeach
        </div>
        @endif
    </div>
</div>

{{-- ── Section I — Child Registration (LHDN) ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-heart me-2 text-primary"></i>Section I — Child Registration (LHDN)</h6>
    </div>
    <div class="card-body">
        @php $hasChildren = !empty($stagingChildren) && array_sum($stagingChildren) > 0; @endphp
        @if(!$hasChildren)
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No child registration recorded.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th rowspan="2" style="width:60%;vertical-align:middle;">Category</th>
                        <th colspan="2" class="text-center">Number of children</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="width:110px;">100%<br><small class="fw-normal">(tax relief by self)</small></th>
                        <th class="text-center" style="width:110px;">50%<br><small class="fw-normal">(shared with spouse)</small></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lhdnCats as $key => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="text-center">{{ $stagingChildren["cat_{$key}_100"] ?? 0 }}</td>
                        <td class="text-center">{{ $stagingChildren["cat_{$key}_50"] ?? 0 }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endif {{-- canSeePersonal --}}

{{-- ── Section B — Work Details ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-briefcase me-2 text-primary"></i>Section B — Work Details</h6>
    </div>
    <div class="card-body">
        @if($w)
        <div class="row g-0">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Employee Status</td><td class="py-2">
                        <span class="badge {{ $w->employee_status === 'active' ? 'bg-success' : 'bg-danger' }}">{{ ucfirst($w->employee_status) }}</span>
                    </td></tr>
                    <tr><td class="text-muted py-2">Staff Status</td><td class="py-2">{{ ucfirst($w->staff_status) }}</td></tr>
                    <tr><td class="text-muted py-2">Employment Type</td><td class="py-2">{{ ucfirst($w->employment_type) }}</td></tr>
                    <tr><td class="text-muted py-2">Designation</td><td class="fw-semibold py-2">{{ $w->designation }}</td></tr>
                    <tr><td class="text-muted py-2">Department</td><td class="py-2">{{ $w->department ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Company</td><td class="py-2">{{ $w->company }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Office Location</td><td class="py-2">{{ $w->office_location }}</td></tr>
                    <tr><td class="text-muted py-2">Reporting Manager</td><td class="py-2">{{ $w->reporting_manager }}</td></tr>
                    <tr><td class="text-muted py-2">Start Date</td><td class="py-2">{{ $w->start_date?->format('d M Y') }}</td></tr>
                    <tr><td class="text-muted py-2">Exit Date</td><td class="py-2">{{ $w->exit_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Last Salary Date</td><td class="py-2">{{ $w->last_salary_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Company Email</td><td class="py-2">{{ $w->company_email ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Google ID</td><td class="py-2">{{ $w->google_id ?? '—' }}</td></tr>
                </table>
            </div>
        </div>
        @else
        <p class="text-muted small mb-0">No work details recorded.</p>
        @endif
    </div>
</div>

{{-- ── Section C — Asset Provisioning ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-laptop me-2 text-primary"></i>Section C — Asset Provisioning</h6>
    </div>
    <div class="card-body">
        @if($a)
        <div class="row g-2 mb-3">
            @foreach([
                ['laptop_provision','Laptop','bi-laptop'],
                ['monitor_set','Monitor','bi-display'],
                ['converter','Converter','bi-plug'],
                ['company_phone','Phone','bi-phone'],
                ['sim_card','SIM Card','bi-sim'],
                ['access_card_request','Access Card','bi-credit-card'],
            ] as [$field,$label,$icon])
            <div class="col-md-4">
                <div class="d-flex align-items-center gap-2 p-2 rounded {{ $a->$field ? 'bg-success bg-opacity-10' : 'bg-light' }}">
                    <i class="bi {{ $icon }} {{ $a->$field ? 'text-success' : 'text-muted' }}"></i>
                    <span class="small {{ $a->$field ? 'fw-semibold' : 'text-muted' }}">{{ $label }}</span>
                    <i class="bi {{ $a->$field ? 'bi-check-circle-fill text-success' : 'bi-x-circle text-muted' }} ms-auto small"></i>
                </div>
            </div>
            @endforeach
        </div>
        @if($a->office_keys)<p class="mb-1 small"><strong>Office Keys:</strong> {{ $a->office_keys }}</p>@endif
        @if($a->others)<p class="mb-0 small"><strong>Others:</strong> {{ $a->others }}</p>@endif
        @else
        <p class="text-muted small mb-0">No provisioning data.</p>
        @endif
    </div>
</div>

{{-- ── Assigned Assets ── --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Assigned Assets</h6>
    </div>
    <div class="card-body p-0">
        @if($onboarding->assetAssignments->isEmpty())
            <p class="text-muted small p-3 mb-0">No assets assigned yet.</p>
        @else
        <table class="table table-sm mb-0">
            <thead style="background:#f8fafc;">
                <tr><th class="ps-3">Asset Tag</th><th>Type</th><th>Assigned</th><th>Status</th></tr>
            </thead>
            <tbody>
                @foreach($onboarding->assetAssignments as $assign)
                <tr>
                    <td class="ps-3"><code>{{ $assign->asset?->asset_tag }}</code></td>
                    <td>{{ ucfirst(str_replace('_',' ',$assign->asset?->asset_type ?? '')) }}</td>
                    <td>{{ $assign->assigned_date?->format('d M Y') }}</td>
                    <td><span class="badge {{ $assign->status==='assigned'?'bg-success':'bg-secondary' }}">{{ ucfirst($assign->status) }}</span></td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

{{-- ── Declaration & Consent ── --}}
@if($canSeePersonal && $p)
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #2563eb;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Declaration &amp; Consent</h6>
    </div>
    <div class="card-body">
        <div class="p-3 rounded mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;font-size:13.5px;">
            <p class="fw-semibold mb-2">Personal Data Protection Act (PDPA) 2010 — Consent</p>
            <p class="mb-2">I hereby declare that all information provided above is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
            <p class="mb-2">I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
            <p class="mb-0">I also agree to promptly notify the HRA Department of any changes to the information provided above, including updates to my contact details, banking information, or personal particulars.</p>
        </div>
        @if($p->consent_given_at)
        <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;">
            <i class="bi bi-check-circle-fill text-success" style="font-size:22px;"></i>
            <div>
                <div class="fw-semibold text-success">Consent Given</div>
                <div class="text-muted small">
                    Acknowledged by <strong>{{ $p->full_name }}</strong>
                    on {{ $p->consent_given_at->format('d M Y, h:i A') }}
                </div>
            </div>
        </div>
        @else
        <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:#fff7ed;border:1px solid #fed7aa;">
            <i class="bi bi-clock-history text-warning" style="font-size:22px;"></i>
            <div>
                <div class="fw-semibold text-warning">Pending Consent</div>
                <div class="text-muted small">The new hire has not yet acknowledged the Declaration &amp; Consent. A request email has been sent to their work email.</div>
            </div>
        </div>
        @endif
    </div>
</div>
@endif

{{-- ── Edit & Consent Acknowledgement Log ── --}}
@php $editLogs = $onboarding->editLogs; @endphp
@if($editLogs->isNotEmpty())
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #6366f1;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2 text-indigo-600" style="color:#6366f1;"></i>Edit &amp; Consent Acknowledgement Log</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th class="ps-3" style="width:140px;">Date &amp; Time</th>
                    <th>Edited By</th>
                    <th>Sections Changed</th>
                    <th>Acknowledged At</th>
                </tr>
            </thead>
            <tbody>
                @foreach($editLogs as $log)
                <tr>
                    <td class="ps-3 text-muted">{{ $log->created_at->format('d M Y') }}<br><small>{{ $log->created_at->format('h:i A') }}</small></td>
                    <td>
                        <span class="fw-semibold">{{ $log->edited_by_name }}</span><br>
                        <small class="text-muted">{{ str_replace('_',' ',ucwords($log->edited_by_role ?? '')) }}</small>
                    </td>
                    <td>
                        @if(!empty($log->sections_changed))
                            @foreach($log->sections_changed as $section)
                            <span class="badge bg-primary bg-opacity-10 text-primary me-1 mb-1">{{ $section }}</span>
                            @endforeach
                        @else
                            <span class="text-muted">—</span>
                        @endif
                        @if($log->change_notes)
                        <div class="text-muted small mt-1">{{ $log->change_notes }}</div>
                        @endif
                    </td>
                    <td>
                        @if($log->acknowledged_at)
                        {{ $log->acknowledged_at->format('d M Y') }}<br>
                        <small class="text-muted">{{ $log->acknowledged_at->format('h:i A') }}</small>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                        @if($log->acknowledgement_notes)
                        <div class="text-muted small mt-1">{{ $log->acknowledgement_notes }}</div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@endsection