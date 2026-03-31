<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Complete Your Onboarding — Claritas Asia</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
body { background:#f1f5f9; min-height:100vh; }
.brand-header { background:linear-gradient(135deg,#1A6FE8,#4B9EFF); }
.section-card { border-left:4px solid #1A6FE8; }
.consent-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1.25rem; max-height:340px; overflow-y:auto; font-size:13.5px; line-height:1.8; }
.list-entry { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:.85rem 1rem; margin-bottom:.6rem; position:relative; padding-right:3rem; }
.list-entry .remove-btn { position:absolute; top:50%; right:.75rem; transform:translateY(-50%); }
.input-panel { background:#f8fafc; border:1px solid #e9ecef; border-radius:8px; padding:1rem; margin-top:.75rem; }
.file-item { display:flex; align-items:center; justify-content:space-between; background:#fff; border:1px solid #dee2e6; border-radius:6px; padding:.4rem .75rem; margin-bottom:.4rem; font-size:13px; }
</style>
</head>
<body>

<div class="brand-header py-3 px-4 mb-4">
    <div class="container">
        <h5 class="text-white mb-0 fw-bold"><i class="bi bi-person-plus me-2"></i>Employee Portal — Onboarding</h5>
        <p class="text-white-50 mb-0 small">Claritas Asia Sdn. Bhd.</p>
    </div>
</div>

<div class="container" style="max-width:800px;">

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── STEP 1: Email Verification ── --}}
@if(!$verified)
<div class="card shadow-sm">
    <div class="card-header bg-white py-3 section-card">
        <h6 class="mb-0 fw-bold"><i class="bi bi-shield-check me-2 text-primary"></i>Verify Your Email</h6>
    </div>
    <div class="card-body p-4">
        <p class="text-muted mb-3">Please enter the email address this invitation was sent to.</p>
        <form method="POST" action="{{ route('onboarding.invite.verify', $token) }}">
            @csrf
            <div class="mb-3">
                <label class="form-label fw-semibold">Your Email Address <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                       placeholder="Enter your email address" required autofocus>
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-check-circle me-2"></i>Verify &amp; Continue
            </button>
        </form>
    </div>
</div>

{{-- ── STEP 2: Full Form (consent at end) ── --}}
@else
<form method="POST" action="{{ route('onboarding.invite.submit', $token) }}"
      enctype="multipart/form-data" id="inviteForm">
@csrf

{{-- ─── Section A — Personal Details ─────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 section-card">
        <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Section A — Personal Details</h6>
    </div>
    <div class="card-body p-4">
        <div class="row g-3">

            <div class="col-md-6">
                <label class="form-label fw-semibold">Full Name (as per IC) <span class="text-danger">*</span></label>
                <input type="text" name="full_name" class="form-control @error('full_name') is-invalid @enderror"
                       value="{{ old('full_name') }}" required>
                @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Preferred Name</label>
                <input type="text" name="preferred_name" class="form-control" value="{{ old('preferred_name') }}">
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">NRIC / Passport Number <span class="text-danger">*</span></label>
                <input type="text" name="official_document_id"
                       class="form-control @error('official_document_id') is-invalid @enderror"
                       value="{{ old('official_document_id') }}" required>
                @error('official_document_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- NRIC Upload — one file at a time ─────────── --}}
            <div class="col-md-6">
                <label class="form-label fw-semibold">NRIC / Passport Copy Upload
                    <span class="text-muted fw-normal small">(photo or PDF)</span>
                </label>
                <div class="d-flex gap-2 mb-2">
                    <input type="file" id="nricFileInput" class="form-control"
                           accept=".jpg,.jpeg,.png,.pdf">
                    <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0"
                            onclick="addNricFile()">
                        <i class="bi bi-upload me-1"></i>Upload
                    </button>
                </div>
                <div id="nricFileList"></div>
                <div id="nricHidden"></div>
                <div class="form-text">Max 5 files, 5 MB each. Visible to HR and yourself only.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                <input type="date" name="date_of_birth"
                       class="form-control @error('date_of_birth') is-invalid @enderror"
                       value="{{ old('date_of_birth') }}" required>
                @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Sex <span class="text-danger">*</span></label>
                <select name="sex" class="form-select @error('sex') is-invalid @enderror" required>
                    <option value="">Select...</option>
                    <option value="male"   {{ old('sex')=='male'  ?'selected':'' }}>Male</option>
                    <option value="female" {{ old('sex')=='female'?'selected':'' }}>Female</option>
                </select>
                @error('sex')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Disabled Person</label>
                <select name="is_disabled" class="form-select">
                    <option value="0" {{ old('is_disabled','0')=='0'?'selected':'' }}>No</option>
                    <option value="1" {{ old('is_disabled')=='1'?'selected':'' }}>Yes</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Marital Status <span class="text-danger">*</span></label>
                <select name="marital_status" id="maritalStatus" class="form-select @error('marital_status') is-invalid @enderror" required onchange="toggleSpouseSection(this.value)">
                    <option value="">Select...</option>
                    @foreach(['single'=>'Single','married'=>'Married','divorced'=>'Divorced','widowed'=>'Widowed'] as $v=>$l)
                    <option value="{{ $v }}" {{ old('marital_status')==$v?'selected':'' }}>{{ $l }}</option>
                    @endforeach
                </select>
                @error('marital_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Race <span class="text-danger">*</span></label>
                <input type="text" name="race" class="form-control @error('race') is-invalid @enderror"
                       value="{{ old('race') }}" required>
                @error('race')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Religion <span class="text-danger">*</span></label>
                <input type="text" name="religion" class="form-control @error('religion') is-invalid @enderror"
                       value="{{ old('religion') }}" required>
                @error('religion')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Tel No. (H/phone) <span class="text-danger">*</span></label>
                <input type="text" name="personal_contact_number"
                       class="form-control @error('personal_contact_number') is-invalid @enderror"
                       value="{{ old('personal_contact_number') }}" required>
                @error('personal_contact_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tel No. (House)</label>
                <input type="text" name="house_tel_no" class="form-control" value="{{ old('house_tel_no') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Personal Email <span class="text-danger">*</span></label>
                <input type="email" name="personal_email"
                       class="form-control @error('personal_email') is-invalid @enderror"
                       value="{{ old('personal_email') }}" required>
                @error('personal_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Residential Address <span class="text-danger">*</span></label>
                <textarea name="residential_address"
                          class="form-control @error('residential_address') is-invalid @enderror"
                          rows="2" required>{{ old('residential_address') }}</textarea>
                @error('residential_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-6">
                <label class="form-label fw-semibold">Bank Account Number <span class="text-danger">*</span></label>
                <input type="text" name="bank_account_number"
                       class="form-control @error('bank_account_number') is-invalid @enderror"
                       value="{{ old('bank_account_number') }}" required>
                @error('bank_account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Bank Name</label>
                <select name="bank_name" id="invBankName" class="form-select"
                        onchange="toggleOtherBank(this,'invBankNameOther')">
                    <option value="">— Select Bank —</option>
                    @php $banks=['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank','Other']; @endphp
                    @foreach($banks as $b)
                    <option value="{{ $b }}" {{ old('bank_name')==$b?'selected':'' }}>{{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6 d-none" id="invBankNameOther">
                <label class="form-label fw-semibold">Other Bank Name</label>
                <input type="text" name="bank_name_other" class="form-control"
                       value="{{ old('bank_name_other') }}" placeholder="Enter bank name">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">EPF No.</label>
                <input type="text" name="epf_no" class="form-control" value="{{ old('epf_no') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Income Tax No.</label>
                <input type="text" name="income_tax_no" class="form-control" value="{{ old('income_tax_no') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">SOCSO No.</label>
                <input type="text" name="socso_no" class="form-control" value="{{ old('socso_no') }}">
            </div>

        </div>
    </div>
</div>

{{-- ─── Education & Work History ────────────────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 section-card">
        <h6 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Education &amp; Work History</h6>
    </div>
    <div class="card-body p-4">

        {{-- Input panel shown first --}}
        <div class="input-panel" id="eduPanel">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Qualification (Full Name)</label>
                    <input type="text" id="eduQual" class="form-control"
                           placeholder="e.g. Bachelor of Business Administration">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Institution</label>
                    <input type="text" id="eduInst" class="form-control"
                           placeholder="e.g. Universiti Malaya">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Year Graduated</label>
                    <input type="number" id="eduYear" class="form-control"
                           placeholder="{{ date('Y') }}" min="1950" max="{{ date('Y')+5 }}">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold">
                        Certificate <span class="text-muted fw-normal small">(PDF/image)</span>
                    </label>
                    <div class="d-flex gap-2">
                        <input type="file" id="eduCertInput" class="form-control"
                               accept=".jpg,.jpeg,.png,.pdf">
                        <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0"
                                onclick="addEduCertFile()">
                            <i class="bi bi-upload me-1"></i>Upload
                        </button>
                    </div>
                    <div id="eduCertFileList" class="mt-1"></div>
                </div>
            </div>
            <div class="mt-3 text-end">
                <button type="button" class="btn btn-primary btn-sm px-4" onclick="addEduEntry()">
                    <i class="bi bi-plus-circle me-1"></i>Add to List
                </button>
            </div>
        </div>

        {{-- List appears below the panel --}}
        <div id="eduList" class="mt-3"></div>
        <div id="eduHidden"></div>

        {{-- Years of Experience — separate field ── --}}
        <hr class="my-3">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">
                    No. of Years of Working Experience
                    <span class="text-muted fw-normal small">(not incl. part-time)</span>
                </label>
                <select name="edu_experience_total" class="form-select">
                    <option value="">— Select —</option>
                    @for($y = 0; $y <= 40; $y++)
                    <option value="{{ $y }}" {{ old('edu_experience_total')==$y?'selected':'' }}>
                        {{ $y }} {{ $y==1?'year':'years' }}
                    </option>
                    @endfor
                    <option value="40+" {{ old('edu_experience_total')=='40+'?'selected':'' }}>40+ years</option>
                </select>
            </div>
        </div>

    </div>
</div>

{{-- ─── Spouse Information ──────────────────────────────────────────── --}}
<div class="card shadow-sm mb-4" id="spouseSection">
    <div class="card-header bg-white py-3 section-card">
        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Spouse Information</h6>
        <p class="mb-0 text-muted small mt-1">Fill in details and click "Add". You may add more than one. Leave blank if not applicable.</p>
    </div>
    <div class="card-body p-4">

        <div class="input-panel" id="spousePanel">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Name</label>
                    <input type="text" id="spName" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">NRIC No.</label>
                    <input type="text" id="spNric" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tel No.</label>
                    <input type="text" id="spTel" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Occupation</label>
                    <input type="text" id="spOccupation" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Income Tax No.</label>
                    <input type="text" id="spIncomeTax" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Address</label>
                    <textarea id="spAddress" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Working?</label>
                    <select id="spWorking" class="form-select">
                        <option value="0">No</option><option value="1">Yes</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Disabled?</label>
                    <select id="spDisabled" class="form-select">
                        <option value="0">No</option><option value="1">Yes</option>
                    </select>
                </div>
            </div>
            <div class="mt-3 text-end">
                <button type="button" class="btn btn-primary btn-sm px-4" onclick="addSpouseEntry()">
                    <i class="bi bi-plus-circle me-1"></i>Add to List
                </button>
            </div>
        </div>

        <div id="spouseList" class="mt-3"></div>
        <div id="spouseHidden"></div>

    </div>
</div>

{{-- ─── Emergency Contacts ─────────────────────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 section-card">
        <h6 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Person to Notify in Case of Emergency</h6>
        <p class="mb-0 text-muted small mt-1">Two contacts are required. Fill in and click "Add" for each.</p>
    </div>
    <div class="card-body p-4">

        <div class="input-panel" id="emergencyPanel">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="ecName" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tel No. <span class="text-danger">*</span></label>
                    <input type="text" id="ecTel" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Relationship <span class="text-danger">*</span></label>
                    <select id="ecRel" class="form-select">
                        <option value="">— Select —</option>
                        @foreach(['Spouse','Parent','Sibling','Child','Friend','Colleague','Other'] as $rel)
                        <option value="{{ $rel }}">{{ $rel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="mt-3 text-end">
                <button type="button" class="btn btn-primary btn-sm px-4" onclick="addEmergencyEntry()">
                    <i class="bi bi-plus-circle me-1"></i>Add to List
                </button>
            </div>
        </div>

        <div id="emergencyList" class="mt-3"></div>
        <div id="emergencyHidden"></div>

        <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            <span id="emergencyCountText">0 of 2 required contacts added.</span>
        </p>
    </div>
</div>

{{-- ─── Child Registration (LHDN) ──────────────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 section-card">
        <h6 class="mb-0 fw-bold"><i class="bi bi-heart me-2 text-primary"></i>Child Registration — For LHDN Purpose</h6>
        <p class="mb-0 text-muted small mt-1"><em>Put N/A if not applicable.</em> Number of children for tax relief purpose.</p>
    </div>
    <div class="card-body p-4">
        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th rowspan="2" style="width:55%;vertical-align:middle;">Number of children according to the category below for tax relief purpose</th>
                        <th colspan="2" class="text-center">Number of children</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="width:130px;">100%<br><small class="fw-normal">(tax relief by self)</small></th>
                        <th class="text-center" style="width:130px;">50%<br><small class="fw-normal">(tax relief shared with spouse)</small></th>
                    </tr>
                </thead>
                <tbody>
                    @php $lhdnCats = [
                        'a'=>'a) Children under 18 years old',
                        'b'=>'b) Children aged 18 years and above (still studying at the certificate and matriculation level)',
                        'c'=>'c) Above 18 years (studying Diploma level or higher in Malaysia or elsewhere)',
                        'd'=>'d) Disabled Child below 18 years old',
                        'e'=>'e) Disabled Child (studying Diploma level or higher in Malaysia or elsewhere)',
                    ]; @endphp
                    @foreach($lhdnCats as $key => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="text-center">
                            <input type="number" name="cat_{{ $key }}_100" class="form-control form-control-sm text-center"
                                   value="{{ old("cat_{$key}_100",0) }}" min="0" max="99" style="width:70px;margin:auto;">
                        </td>
                        <td class="text-center">
                            <input type="number" name="cat_{{ $key }}_50" class="form-control form-control-sm text-center"
                                   value="{{ old("cat_{$key}_50",0) }}" min="0" max="99" style="width:70px;margin:auto;">
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ─── Declaration & Consent (end of form) ───────────────────────── --}}
<div class="card shadow-sm mb-4" id="consentCard">
    <div class="card-header bg-white py-3 section-card">
        <h6 class="mb-0 fw-bold"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Declaration &amp; Consent</h6>
    </div>
    <div class="card-body p-4">
        <div class="consent-box mb-4">
            <p class="fw-semibold mb-2">Personal Data Protection Act (PDPA) 2010 — Consent</p>
            <p>I hereby declare that all information provided above is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
            <p>I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
            <p class="mb-0">I also agree to promptly notify the HRA Department of any changes to the information provided above, including updates to my contact details, banking information, or personal particulars.</p>
        </div>

        {{-- Step 1: I Acknowledge button (AJAX) --}}
        <div id="consentStep1">
            <button type="button" class="btn btn-outline-primary px-4" onclick="acknowledgeConsent()">
                <i class="bi bi-pen me-2"></i>I Acknowledge
            </button>
            <p class="text-muted small mt-2 mb-0">
                Click "I Acknowledge" to record your consent, then Submit My Details below.
            </p>
        </div>

        {{-- Step 2: Confirmed state (shown after acknowledge) --}}
        <div id="consentStep2" class="d-none">
            <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                <i class="bi bi-check-circle-fill text-success" style="font-size:22px;"></i>
                <div>
                    <div class="fw-semibold text-success">Acknowledged</div>
                    <div class="text-muted small" id="consentTimestamp"></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Hidden field carries consent flag for form submission --}}
<input type="hidden" name="consent_acknowledged" id="consentAcknowledged" value="0">

<div class="d-flex gap-2 justify-content-end mb-5">
    <button type="submit" class="btn btn-primary btn-lg px-5" id="submitBtn"
            onclick="return validateAndSubmit()">
        <i class="bi bi-check-circle me-2"></i>Submit My Details
    </button>
</div>

</form>
@endif

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CONSENT_URL = "{{ isset($token) ? route('onboarding.invite.consent', $token ?? '') : '' }}";
const CSRF_TOKEN  = "{{ csrf_token() }}";

// ── Bank Name ─────────────────────────────────────────────────────────────
function toggleOtherBank(sel, otherId) {
    document.getElementById(otherId)?.classList.toggle('d-none', sel.value !== 'Other');
}
document.addEventListener('DOMContentLoaded', () => {
    const b = document.getElementById('invBankName');
    if (b) toggleOtherBank(b, 'invBankNameOther');
});

// ═══════════════════════════════════════════════════
// NRIC FILE UPLOAD — one at a time
// ═══════════════════════════════════════════════════
let nricFiles = [];

function addNricFile() {
    const inp = document.getElementById('nricFileInput');
    if (!inp.files.length) { alert('Please select a file first.'); return; }
    if (nricFiles.length >= 5) { alert('Maximum 5 files allowed.'); return; }
    const file = inp.files[0];
    nricFiles.push(file);
    renderNricList();
    inp.value = '';
}

function removeNricFile(i) {
    nricFiles.splice(i, 1);
    renderNricList();
}

function renderNricList() {
    const list = document.getElementById('nricFileList');
    const hidden = document.getElementById('nricHidden');
    list.innerHTML = '';
    nricFiles.forEach((f, i) => {
        list.innerHTML += `
        <div class="file-item">
            <span><i class="bi bi-file-earmark me-1"></i>${escH(f.name)}</span>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                    onclick="removeNricFile(${i})"><i class="bi bi-x"></i></button>
        </div>`;
    });
    // Rebuild hidden file input
    const old = hidden.querySelector('input[data-nric]');
    if (old) old.remove();
    if (nricFiles.length) {
        const dt = new DataTransfer();
        nricFiles.forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = 'nric_files[]'; inp.multiple = true;
        inp.setAttribute('data-nric','1'); inp.style.display='none';
        inp.files = dt.files;
        hidden.appendChild(inp);
    }
}

// ═══════════════════════════════════════════════════
// EDUCATION — list below panel
// ═══════════════════════════════════════════════════
let eduEntries = [];
let eduCertFiles = [];

function addEduCertFile() {
    const inp = document.getElementById('eduCertInput');
    if (!inp.files.length) { alert('Please select a file first.'); return; }
    eduCertFiles.push(inp.files[0]);
    renderEduCertList();
    inp.value = '';
}

function removeEduCertFile(i) {
    eduCertFiles.splice(i, 1);
    renderEduCertList();
}

function renderEduCertList() {
    const list = document.getElementById('eduCertFileList');
    list.innerHTML = '';
    eduCertFiles.forEach((f, i) => {
        list.innerHTML += `
        <div class="file-item mt-1">
            <span><i class="bi bi-file-earmark me-1"></i>${escH(f.name)}</span>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                    onclick="removeEduCertFile(${i})"><i class="bi bi-x"></i></button>
        </div>`;
    });
}

function addEduEntry() {
    const qual = document.getElementById('eduQual').value.trim();
    if (!qual) { alert('Please enter a qualification name.'); return; }
    const entry = {
        qualification: qual,
        institution:   document.getElementById('eduInst').value.trim(),
        year:          document.getElementById('eduYear').value.trim(),
        certFiles:     [...eduCertFiles],
    };
    eduEntries.push(entry);
    eduCertFiles = [];
    renderEduCertList();
    renderEduList();
    ['eduQual','eduInst','eduYear'].forEach(id => document.getElementById(id).value = '');
}

function removeEduEntry(i) {
    eduEntries.splice(i, 1);
    renderEduList();
}

function renderEduList() {
    const list = document.getElementById('eduList');
    list.innerHTML = '';
    eduEntries.forEach((e, i) => {
        list.innerHTML += `
        <div class="list-entry">
            <button type="button" class="btn btn-sm btn-outline-danger remove-btn"
                    onclick="removeEduEntry(${i})"><i class="bi bi-trash"></i></button>
            <div class="fw-semibold">${escH(e.qualification)}</div>
            <div class="text-muted small mt-1">
                ${e.institution?`<span class="me-3"><i class="bi bi-building me-1"></i>${escH(e.institution)}</span>`:''}
                ${e.year?`<span class="me-3"><i class="bi bi-calendar me-1"></i>${escH(e.year)}</span>`:''}
                ${e.certFiles.length?`<span><i class="bi bi-paperclip me-1"></i>${e.certFiles.map(f=>escH(f.name)).join(', ')}</span>`:''}
            </div>
        </div>`;
    });
    syncEduHidden();
}

function syncEduHidden() {
    const h = document.getElementById('eduHidden');
    h.innerHTML = '';
    // Remove any previously generated cert inputs
    h.querySelectorAll('input[data-edu-cert-group]').forEach(el => el.remove());

    eduEntries.forEach((e, i) => {
        h.innerHTML += `
            <input type="hidden" name="edu_qualification[]" value="${escH(e.qualification)}">
            <input type="hidden" name="edu_institution[]"   value="${escH(e.institution)}">
            <input type="hidden" name="edu_year[]"          value="${escH(e.year)}">`;

        // Create a grouped file input per qualification: edu_certificate[i][]
        if (e.certFiles && e.certFiles.length) {
            const dt = new DataTransfer();
            e.certFiles.forEach(f => dt.items.add(f));
            const inp = document.createElement('input');
            inp.type = 'file';
            inp.name = `edu_certificate[${i}][]`;
            inp.multiple = true;
            inp.setAttribute('data-edu-cert-group', i);
            inp.style.display = 'none';
            inp.files = dt.files;
            h.appendChild(inp);
        }
    });
}

// ═══════════════════════════════════════════════════
// SPOUSE — multiple entries, list below panel
// ═══════════════════════════════════════════════════
let spouseEntries = [];

function addSpouseEntry() {
    const name = document.getElementById('spName').value.trim();
    if (!name) { alert('Please enter the spouse name.'); return; }
    spouseEntries.push({
        name,
        nric:       document.getElementById('spNric').value.trim(),
        tel:        document.getElementById('spTel').value.trim(),
        occupation: document.getElementById('spOccupation').value.trim(),
        incomeTax:  document.getElementById('spIncomeTax').value.trim(),
        address:    document.getElementById('spAddress').value.trim(),
        working:    document.getElementById('spWorking').value,
        disabled:   document.getElementById('spDisabled').value,
    });
    renderSpouseList();
    ['spName','spNric','spTel','spOccupation','spIncomeTax','spAddress'].forEach(id =>
        document.getElementById(id).value = '');
    document.getElementById('spWorking').value = '0';
    document.getElementById('spDisabled').value = '0';
}

function removeSpouseEntry(i) {
    spouseEntries.splice(i, 1);
    renderSpouseList();
}

function renderSpouseList() {
    const list = document.getElementById('spouseList');
    const h    = document.getElementById('spouseHidden');
    list.innerHTML = '';
    h.innerHTML    = '';
    spouseEntries.forEach((e, i) => {
        list.innerHTML += `
        <div class="list-entry">
            <button type="button" class="btn btn-sm btn-outline-danger remove-btn"
                    onclick="removeSpouseEntry(${i})"><i class="bi bi-trash"></i></button>
            <div class="fw-semibold">${escH(e.name)}</div>
            <div class="text-muted small mt-1">
                ${e.nric?`<span class="me-3">NRIC: ${escH(e.nric)}</span>`:''}
                ${e.tel?`<span class="me-3">Tel: ${escH(e.tel)}</span>`:''}
                ${e.occupation?`<span class="me-3">${escH(e.occupation)}</span>`:''}
                <span class="me-3">Working: ${e.working==='1'?'Yes':'No'}</span>
                <span>Disabled: ${e.disabled==='1'?'Yes':'No'}</span>
            </div>
        </div>`;
        // Build hidden inputs grouped per spouse index
        const idx = i;
        h.innerHTML += `
            <input type="hidden" name="spouses[${idx}][name]"          value="${escH(e.name)}">
            <input type="hidden" name="spouses[${idx}][nric_no]"       value="${escH(e.nric)}">
            <input type="hidden" name="spouses[${idx}][tel_no]"        value="${escH(e.tel)}">
            <input type="hidden" name="spouses[${idx}][occupation]"    value="${escH(e.occupation)}">
            <input type="hidden" name="spouses[${idx}][income_tax_no]" value="${escH(e.incomeTax)}">
            <input type="hidden" name="spouses[${idx}][address]"       value="${escH(e.address)}">
            <input type="hidden" name="spouses[${idx}][is_working]"    value="${e.working}">
            <input type="hidden" name="spouses[${idx}][is_disabled]"   value="${e.disabled}">`;
    });
}

// ═══════════════════════════════════════════════════
// EMERGENCY CONTACTS — list below panel
// ═══════════════════════════════════════════════════
let emergencyEntries = [];

function addEmergencyEntry() {
    const name = document.getElementById('ecName').value.trim();
    const tel  = document.getElementById('ecTel').value.trim();
    const rel  = document.getElementById('ecRel').value;
    if (!name || !tel || !rel) { alert('Please fill in Name, Tel No., and Relationship.'); return; }
    if (emergencyEntries.length >= 2) { alert('Maximum 2 emergency contacts allowed.'); return; }
    emergencyEntries.push({ name, tel, relationship: rel });
    renderEmergencyList();
    document.getElementById('ecName').value = '';
    document.getElementById('ecTel').value  = '';
    document.getElementById('ecRel').value  = '';
}

function removeEmergencyEntry(i) {
    emergencyEntries.splice(i, 1);
    renderEmergencyList();
}

function renderEmergencyList() {
    const list = document.getElementById('emergencyList');
    const h    = document.getElementById('emergencyHidden');
    list.innerHTML = '';
    h.innerHTML    = '';
    emergencyEntries.forEach((e, i) => {
        const order = i + 1;
        list.innerHTML += `
        <div class="list-entry">
            <button type="button" class="btn btn-sm btn-outline-danger remove-btn"
                    onclick="removeEmergencyEntry(${i})"><i class="bi bi-trash"></i></button>
            <div class="fw-semibold">Contact ${order}: ${escH(e.name)}</div>
            <div class="text-muted small mt-1">
                <span class="me-3"><i class="bi bi-telephone me-1"></i>${escH(e.tel)}</span>
                <span><i class="bi bi-person-lines-fill me-1"></i>${escH(e.relationship)}</span>
            </div>
        </div>`;
        h.innerHTML += `
            <input type="hidden" name="emergency[${order}][name]"         value="${escH(e.name)}">
            <input type="hidden" name="emergency[${order}][tel_no]"       value="${escH(e.tel)}">
            <input type="hidden" name="emergency[${order}][relationship]" value="${escH(e.relationship)}">`;
    });
    const t = document.getElementById('emergencyCountText');
    if (t) t.textContent = `${emergencyEntries.length} of 2 required contacts added.`;
}

// ═══════════════════════════════════════════════════
// CONSENT — I Acknowledge (AJAX, then show Submit)
// ═══════════════════════════════════════════════════
function acknowledgeConsent() {
    fetch(CONSENT_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
        body: JSON.stringify({}),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('consentStep1').classList.add('d-none');
            document.getElementById('consentStep2').classList.remove('d-none');
            document.getElementById('consentTimestamp').textContent =
                'Acknowledged on ' + data.timestamp;
            document.getElementById('consentAcknowledged').value = '1';
        } else {
            alert(data.message || 'Could not record consent. Please try again.');
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

// ═══════════════════════════════════════════════════
// FORM VALIDATION
// ═══════════════════════════════════════════════════
function validateAndSubmit() {
    // Auto-capture NRIC file if selected but "Upload" was not clicked
    const nricInputEl = document.getElementById('nricFileInput');
    if (nricInputEl && nricInputEl.files.length && nricFiles.length < 5) {
        const file = nricInputEl.files[0];
        nricFiles.push(file);
        renderNricList();
        nricInputEl.value = '';
    }

    // Auto-capture spouse if name is filled but "Add to List" was not clicked
    const spNameVal = (document.getElementById('spName')?.value || '').trim();
    if (spNameVal && !spouseEntries.some(e => e.name === spNameVal)) {
        addSpouseEntry();
    }
    // Validate: married status requires at least one spouse entry
    const maritalVal = (document.getElementById('maritalStatus')?.value || '').toLowerCase();
    if (maritalVal === 'married' && spouseEntries.length === 0) {
        alert('Marital status is "Married" — please add at least one spouse entry in Section G before submitting.');
        document.getElementById('spouseSection')?.scrollIntoView({ behavior: 'smooth' });
        return false;
    }

    // Auto-capture emergency contact if fields are filled but not added
    const ecNameVal = (document.getElementById('ecName')?.value || '').trim();
    const ecTelVal  = (document.getElementById('ecTel')?.value  || '').trim();
    const ecRelVal  =  document.getElementById('ecRel')?.value  || '';
    if (ecNameVal && ecTelVal && ecRelVal && emergencyEntries.length < 2) {
        addEmergencyEntry();
    }

    if (emergencyEntries.length < 2) {
        alert('Please add 2 emergency contacts before submitting.');
        document.getElementById('emergencyList').scrollIntoView({ behavior:'smooth' });
        return false;
    }
    if (document.getElementById('consentAcknowledged').value !== '1') {
        alert('Please click "I Acknowledge" in the Declaration & Consent section before submitting.');
        document.getElementById('consentCard').scrollIntoView({ behavior:'smooth' });
        return false;
    }
    return true;
}

function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Marital Status → Spouse Section toggle ──────────────────────────────
function toggleSpouseSection(val) {
    const section = document.getElementById('spouseSection');
    const panel   = document.getElementById('spousePanel');
    const addBtn  = panel ? panel.querySelector('button[onclick="addSpouseEntry()"]') : null;
    if (!section) return;
    if (val === 'married') {
        section.style.opacity = '1';
        section.style.pointerEvents = 'auto';
        if (panel) panel.style.opacity = '1';
        // Update header to show required
        const hdr = section.querySelector('.card-header h6');
        if (hdr && !hdr.querySelector('.spouse-required')) {
            hdr.insertAdjacentHTML('beforeend', ' <span class="text-danger spouse-required" style="font-size:13px;">*</span>');
        }
        const note = section.querySelector('.card-header p');
        if (note) note.textContent = 'Required — please add at least one spouse entry.';
    } else {
        section.style.opacity = '0.4';
        section.style.pointerEvents = 'none';
        const hdr = section.querySelector('.card-header h6');
        if (hdr) { const star = hdr.querySelector('.spouse-required'); if (star) star.remove(); }
        const note = section.querySelector('.card-header p');
        if (note) note.textContent = 'Not applicable — spouse information is only required when marital status is Married.';
    }
}
// Run on page load to reflect old() value
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('maritalStatus');
    if (sel) toggleSpouseSection(sel.value);
});

</script>
</body>
</html>