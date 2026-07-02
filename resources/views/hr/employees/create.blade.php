@extends('layouts.app')
@section('title', 'Add Employee')
@section('page-title', 'Add New Employee')

@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('employees.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Listing
    </a>
    <span class="text-muted small">/ Add Employee</span>
</div>

@if($errors->any())
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle me-1"></i>Please fix the errors below.
    <ul class="mb-0 mt-1 small">
        @foreach($errors->all() as $err)<li>{{ $err }}</li>@endforeach
    </ul>
</div>
@endif

<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Assets (Section C) and Documents (Section E — contract, handbook, orientation) can be added from the
    employee's record after you create it here.
</div>

<form action="{{ route('employees.store') }}" method="POST" enctype="multipart/form-data" id="createEmpForm">
    @csrf

    {{-- ── SECTION A — Personal Details ──────────────────────────────────── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">A</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Personal Details</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                {{-- Row 1: Names --}}
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" value="{{ old('full_name') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Preferred Name</label>
                    <input type="text" name="preferred_name" class="form-control" value="{{ old('preferred_name') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">NRIC / Passport No.</label>
                    <input type="text" name="official_document_id" class="form-control" value="{{ old('official_document_id') }}">
                </div>

                {{-- Row 2: DOB, Sex, Marital, Religion --}}
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="{{ old('date_of_birth') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Sex</label>
                    <select name="sex" class="form-select">
                        <option value="">— Select —</option>
                        <option value="male"   {{ old('sex') == 'male'   ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('sex') == 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Marital Status</label>
                    <select name="marital_status" id="empMaritalStatus" class="form-select">
                        @foreach(['single','married','divorced','widowed'] as $ms)
                            <option value="{{ $ms }}" {{ old('marital_status') == $ms ? 'selected' : '' }}>{{ ucfirst($ms) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Religion</label>
                    <input type="text" name="religion" class="form-control" value="{{ old('religion') }}">
                </div>

                {{-- Row 3: Race, Disabled, Tel --}}
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Race</label>
                    <input type="text" name="race" class="form-control" value="{{ old('race') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Disabled Person</label>
                    <select name="is_disabled" class="form-select">
                        <option value="0" {{ !old('is_disabled') ? 'selected' : '' }}>No</option>
                        <option value="1" {{ old('is_disabled') ? 'selected' : '' }}>Yes</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tel No. (H/phone)</label>
                    <input type="text" name="personal_contact_number" class="form-control" value="{{ old('personal_contact_number') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tel No. (House)</label>
                    <input type="text" name="house_tel_no" class="form-control" value="{{ old('house_tel_no') }}">
                </div>

                {{-- Row 4: Email & Bank --}}
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Personal Email</label>
                    <input type="email" name="personal_email" class="form-control" value="{{ old('personal_email') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Bank Account Number</label>
                    <input type="text" name="bank_account_number" class="form-control" value="{{ old('bank_account_number') }}">
                </div>

                {{-- Row 5: Bank Name --}}
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Bank Name</label>
                    <select name="bank_name" id="empBankName" class="form-select">
                        <option value="">— Select Bank —</option>
                        @foreach(['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank','Other'] as $bank)
                        <option value="{{ $bank }}" {{ old('bank_name') == $bank ? 'selected' : '' }}>{{ $bank }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 {{ in_array(old('bank_name'), ['Other','other']) ? '' : 'd-none' }}" id="empBankNameOther">
                    <label class="form-label fw-semibold">Other Bank Name</label>
                    <input type="text" name="bank_name_other" class="form-control" value="{{ old('bank_name_other') }}">
                </div>

                {{-- Row 6: Statutory Numbers --}}
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

                {{-- Row 7: NRIC Upload --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">NRIC / Passport Copy Upload
                        <span class="text-muted fw-normal small">(PDF/image, max 5 files)</span>
                    </label>
                    <input type="file" name="nric_files[]" class="form-control" accept=".jpg,.jpeg,.png,.pdf" multiple>
                    <div class="form-text">Select up to 5 files.</div>
                </div>

                {{-- Row 8: Address --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Residential Address</label>
                    <textarea name="residential_address" id="empResAddress" class="form-control" rows="2">{{ old('residential_address') }}</textarea>
                </div>
            </div>
        </div>
    </div>

    {{-- ── SECTION B — Work Details ──────────────────────────────────────── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">B</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-briefcase me-2 text-primary"></i>Work Details</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employee Number</label>
                    <input type="text" name="employee_number" class="form-control" value="{{ old('employee_number') }}" placeholder="e.g. EMP-001">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employment Type</label>
                    <select name="employment_type" class="form-select">
                        <option value="">— Select —</option>
                        @foreach(['permanent','intern','contract'] as $et)
                            <option value="{{ $et }}" {{ old('employment_type') == $et ? 'selected' : '' }}>{{ ucfirst($et) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employment Status</label>
                    <select name="employment_status" class="form-select">
                        @foreach(['active'=>'Active','resigned'=>'Resigned','terminated'=>'Terminated','contract_ended'=>'Contract Ended'] as $val=>$label)
                            <option value="{{ $val }}" {{ old('employment_status','active') == $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Designation</label>
                    <input type="text" name="designation" class="form-control" value="{{ old('designation') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Department</label>
                    <input type="text" name="department" class="form-control" value="{{ old('department') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                    <select name="company" id="empCompanySelect" class="form-control" required>
                        <option value="">Select company...</option>
                        @foreach($companies as $c)
                            <option value="{{ $c->name }}" data-address="{{ $c->address }}" {{ old('company') == $c->name ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Office Location</label>
                    <input type="text" name="office_location" id="empOfficeLocation" class="form-control" value="{{ old('office_location') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Reporting Manager</label>
                    <select name="reporting_manager" id="emp_reporting_manager" class="form-select">
                        <option value="">— Select manager —</option>
                        @foreach($managers as $mgr)
                            @php
                                $mgrDisplay = trim($mgr->preferred_name) !== ''
                                    ? $mgr->preferred_name.' ('.$mgr->full_name.')'
                                    : $mgr->full_name;
                                if ($mgr->department) { $mgrDisplay .= ' — '.$mgr->department; }
                            @endphp
                            <option value="{{ $mgr->full_name }}"
                                data-company="{{ $mgr->company }}"
                                data-employee-id="{{ $mgr->id }}"
                                {{ old('reporting_manager') === $mgr->full_name ? 'selected' : '' }}>
                                {{ $mgrDisplay }}
                            </option>
                        @endforeach
                    </select>
                    <input type="hidden" name="manager_id" id="emp_manager_id" value="{{ old('manager_id') }}">
                    <div class="form-text">Pick the employee this person reports to.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="{{ old('start_date') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Exit Date</label>
                    <input type="date" name="exit_date" class="form-control" value="{{ old('exit_date') }}">
                    <div class="form-text">Leave blank unless already known.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Confirmation Date</label>
                    <input type="date" name="confirmation_date" class="form-control" value="{{ old('confirmation_date') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Company Email</label>
                    <input type="email" name="company_email" id="emp_company_email" class="form-control" value="{{ old('company_email') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Google ID</label>
                    <input type="text" name="google_id" id="emp_google_id" class="form-control bg-light" value="{{ old('google_id') }}" readonly>
                    <div class="form-text">Auto-mirrors company email.</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── SECTION D — Access Role (Superadmin only) ─────────────────────── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">D</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Access Role</h6>
            @if(!Auth::user()->isSuperadmin())
            <span class="ms-auto badge bg-light text-secondary border" style="font-size:11px;"><i class="bi bi-lock me-1"></i>Managed by Superadmin</span>
            @endif
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">System Role</label>
                    @if(Auth::user()->isSuperadmin())
                    <select name="work_role" class="form-select">
                        <option value="">Select role...</option>
                        @foreach([
                            'manager'=>'Manager','senior_executive'=>'Senior Executive','executive_associate'=>'Executive / Associate',
                            'director_hod'=>'Director / Head of Department','hr_manager'=>'HR Manager','hr_executive'=>'HR Executive',
                            'hr_intern'=>'HR Intern','it_manager'=>'IT Manager','it_executive'=>'IT Executive','it_intern'=>'IT Intern',
                            'superadmin'=>'Superadmin','system_admin'=>'System Admin','others'=>'Others',
                        ] as $val => $label)
                            <option value="{{ $val }}" {{ old('work_role') == $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @else
                    <input type="text" class="form-control" style="background:#f8fafc;" value="Others" readonly>
                    <div class="form-text text-muted"><i class="bi bi-lock me-1"></i>Only Superadmin can set roles.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- ── SECTION F — Education & Work History ──────────────────────────── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">F</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Education & Work History</h6>
        </div>
        <div class="card-body">
            <div id="eduRows"></div>
            <button type="button" id="addEduBtn" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Qualification
            </button>

            <div class="row g-3 mt-2">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">No. of Years of Working Experience
                        <span class="text-muted fw-normal small">(not incl. part-time)</span>
                    </label>
                    <select name="edu_experience_total" class="form-select">
                        <option value="">— Select —</option>
                        @for($y = 0; $y <= 40; $y++)
                        <option value="{{ $y }}" {{ old('edu_experience_total') == (string)$y ? 'selected' : '' }}>{{ $y }} {{ $y == 1 ? 'year' : 'years' }}</option>
                        @endfor
                        <option value="40+" {{ old('edu_experience_total') === '40+' ? 'selected' : '' }}>40+ years</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- ── SECTION G — Spouse Information ─────────────────────────────────── --}}
    <div class="card mb-3" id="spouseCard">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">G</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Spouse Information</h6>
            <span class="text-muted small ms-1" id="spouseHint">(for married employees)</span>
        </div>
        <div class="card-body">
            <div id="spouseRows"></div>
            <button type="button" id="addSpouseBtn" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Spouse
            </button>
        </div>
    </div>

    {{-- ── SECTION H — Emergency Contacts ────────────────────────────────── --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">H</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Emergency Contacts</h6>
        </div>
        <div class="card-body">
            @foreach([1,2] as $n)
            <div class="{{ $n==2 ? 'mt-3 pt-3 border-top' : '' }}">
                <p class="fw-semibold small text-muted mb-2">Contact {{ $n }}</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Name</label>
                        <input type="text" name="emergency[{{ $n }}][name]" class="form-control" value="{{ old("emergency.{$n}.name") }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Tel No.</label>
                        <input type="text" name="emergency[{{ $n }}][tel_no]" class="form-control" value="{{ old("emergency.{$n}.tel_no") }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Relationship</label>
                        <select name="emergency[{{ $n }}][relationship]" class="form-select">
                            <option value="">— Select —</option>
                            @foreach(['Spouse','Parent','Sibling','Child','Friend','Colleague','Other'] as $rel)
                            <option value="{{ $rel }}" {{ old("emergency.{$n}.relationship") === $rel ? 'selected' : '' }}>{{ $rel }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- ── SECTION I — Child Registration ────────────────────────────────── --}}
    @php
        $catLabels = [
            'a' => 'a) Children under 18 years old',
            'b' => 'b) Children aged 18 years and above (still studying at the certificate and matriculation level)',
            'c' => 'c) Above 18 years (studying Diploma level or higher in Malaysia or elsewhere)',
            'd' => 'd) Disabled Child below 18 years old',
            'e' => 'e) Disabled Child (studying Diploma level or higher in Malaysia or elsewhere)',
        ];
    @endphp
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">I</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-heart me-2 text-primary"></i>Child Registration (LHDN Tax Relief)</h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle" style="font-size:13px;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th rowspan="2" style="width:55%;vertical-align:middle;">Number of children according to the category below for tax relief purpose</th>
                            <th colspan="2" class="text-center">Number of children</th>
                        </tr>
                        <tr>
                            <th class="text-center">100%<br><small class="fw-normal">(tax relief by self)</small></th>
                            <th class="text-center">50%<br><small class="fw-normal">(tax relief shared with spouse)</small></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($catLabels as $key => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="text-center" style="width:120px;">
                                <input type="number" name="cat_{{ $key }}_100" class="form-control form-control-sm text-center"
                                       value="{{ old("cat_{$key}_100", 0) }}" min="0" max="99" style="width:70px;margin:auto;">
                            </td>
                            <td class="text-center" style="width:120px;">
                                <input type="number" name="cat_{{ $key }}_50" class="form-control form-control-sm text-center"
                                       value="{{ old("cat_{$key}_50", 0) }}" min="0" max="99" style="width:70px;margin:auto;">
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Remarks --}}
    <div class="card mb-3">
        <div class="card-body">
            <label class="form-label fw-semibold">Remarks</label>
            <textarea name="remarks" class="form-control" rows="2" placeholder="Any notes about this new record...">{{ old('remarks') }}</textarea>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="{{ route('employees.index') }}" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i>Create Employee</button>
    </div>
</form>

{{-- ── Row templates (cloned by JS; no inline handlers, CSP-safe) ────────── --}}
<template id="eduRowTpl">
    <div class="edu-row border rounded p-2 mb-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Qualification</label>
                <input type="text" data-name="edu_qualification" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Institution</label>
                <input type="text" data-name="edu_institution" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Year</label>
                <input type="number" data-name="edu_year" class="form-control form-control-sm" min="1950" max="2099">
            </div>
            <div class="col-md-2 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger edu-remove"><i class="bi bi-trash"></i></button>
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Certificate(s) <span class="text-muted fw-normal">(PDF/image, max 5)</span></label>
                <input type="file" data-name="edu_certificate" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" multiple>
            </div>
        </div>
    </div>
</template>

<template id="spouseRowTpl">
    <div class="spouse-row border rounded p-2 mb-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Name</label>
                <input type="text" data-name="name" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">NRIC No.</label>
                <input type="text" data-name="nric_no" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Tel No.</label>
                <input type="text" data-name="tel_no" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Occupation</label>
                <input type="text" data-name="occupation" class="form-control form-control-sm">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Income Tax No.</label>
                <input type="text" data-name="income_tax_no" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Working?</label>
                <select data-name="is_working" class="form-select form-select-sm">
                    <option value="0">No</option><option value="1">Yes</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Disabled?</label>
                <select data-name="is_disabled" class="form-select form-select-sm">
                    <option value="0">No</option><option value="1">Yes</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label small fw-semibold mb-1">Address</label>
                <textarea data-name="address" class="form-control form-control-sm" rows="1"></textarea>
            </div>
            <div class="col-12 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger spouse-remove"><i class="bi bi-trash me-1"></i>Remove</button>
            </div>
        </div>
    </div>
</template>

@php
    // Re-render dynamic rows on validation error
    $oldEduQual = old('edu_qualification', []);
    $oldEduInst = old('edu_institution', []);
    $oldEduYear = old('edu_year', []);
    $oldSpouses = old('spouses', []);
@endphp
<script nonce="{{ $cspNonce ?? '' }}">
(function(){
    'use strict';

    // ── Bank "Other" toggle ──────────────────────────────────────────────
    var bank = document.getElementById('empBankName');
    var bankOther = document.getElementById('empBankNameOther');
    if (bank && bankOther) {
        bank.addEventListener('change', function(){
            if (this.value === 'Other') { bankOther.classList.remove('d-none'); }
            else { bankOther.classList.add('d-none'); }
        });
    }

    // ── Company → office location autofill + manager filter ──────────────
    var companySel = document.getElementById('empCompanySelect');
    var officeInput = document.getElementById('empOfficeLocation');
    var mgrSel = document.getElementById('emp_reporting_manager');
    var mgrIdInput = document.getElementById('emp_manager_id');

    // Normalized company name -> group label; companies in the same group share
    // their reporting-manager pool (e.g. the Cozzi branches). grpNorm() mirrors
    // Company::normName() in PHP.
    var EMP_COMPANY_GROUPS = @json($companyGroups ?? []);
    function grpNorm(s){ return (s||'').toString().toLowerCase().replace(/[^a-z0-9]+/g,' ').trim(); }
    function companyGroupOf(name){ return EMP_COMPANY_GROUPS[grpNorm(name)] || ''; }

    function filterManagers(){
        if (!mgrSel) return;
        var comp = companySel ? grpNorm(companySel.value) : '';
        var compGroup = comp ? companyGroupOf(companySel.value) : '';
        Array.prototype.forEach.call(mgrSel.options, function(opt){
            if (!opt.value) return; // keep placeholder
            var oc = grpNorm(opt.getAttribute('data-company'));
            var og = companyGroupOf(opt.getAttribute('data-company'));
            var show = !comp || !oc || oc === comp || (compGroup && og === compGroup);
            opt.hidden = !show;
            opt.disabled = !show;
        });
        // If the currently selected manager is now hidden, reset
        var cur = mgrSel.selectedOptions[0];
        if (cur && cur.value && cur.hidden) { mgrSel.value = ''; if (mgrIdInput) mgrIdInput.value = ''; }
    }

    if (companySel) {
        companySel.addEventListener('change', function(){
            if (officeInput && !officeInput.value.trim()) {
                var opt = this.selectedOptions[0];
                var addr = opt ? opt.getAttribute('data-address') : '';
                if (addr) officeInput.value = addr;
            }
            filterManagers();
        });
    }
    if (mgrSel && mgrIdInput) {
        mgrSel.addEventListener('change', function(){
            var opt = this.selectedOptions[0];
            mgrIdInput.value = opt ? (opt.getAttribute('data-employee-id') || '') : '';
        });
    }
    filterManagers();

    // ── Company email → Google ID mirror ─────────────────────────────────
    var email = document.getElementById('emp_company_email');
    var gid = document.getElementById('emp_google_id');
    if (email && gid) {
        email.addEventListener('input', function(){ gid.value = this.value; });
    }

    // ── Education dynamic rows ───────────────────────────────────────────
    var eduRows = document.getElementById('eduRows');
    var eduTpl = document.getElementById('eduRowTpl');
    var eduIdx = 0;

    function addEduRow(data){
        if (!eduRows || !eduTpl) return;
        var node = eduTpl.content.firstElementChild.cloneNode(true);
        var i = eduIdx++;
        node.querySelectorAll('[data-name]').forEach(function(inp){
            var base = inp.getAttribute('data-name');
            if (base === 'edu_certificate') { inp.name = 'edu_certificate[' + i + '][]'; }
            else { inp.name = base + '[' + i + ']'; }
        });
        if (data) {
            var q = node.querySelector('[data-name="edu_qualification"]'); if (q) q.value = data.qual || '';
            var ins = node.querySelector('[data-name="edu_institution"]'); if (ins) ins.value = data.inst || '';
            var yr = node.querySelector('[data-name="edu_year"]'); if (yr) yr.value = data.year || '';
        }
        node.querySelector('.edu-remove').addEventListener('click', function(){ node.remove(); });
        eduRows.appendChild(node);
    }
    document.getElementById('addEduBtn').addEventListener('click', function(){ addEduRow(); });

    // ── Spouse dynamic rows ──────────────────────────────────────────────
    var spouseRows = document.getElementById('spouseRows');
    var spouseTpl = document.getElementById('spouseRowTpl');
    var spouseCard = document.getElementById('spouseCard');
    var maritalSel = document.getElementById('empMaritalStatus');
    var spouseIdx = 0;

    function addSpouseRow(data){
        if (!spouseRows || !spouseTpl) return;
        var node = spouseTpl.content.firstElementChild.cloneNode(true);
        var i = spouseIdx++;
        node.querySelectorAll('[data-name]').forEach(function(inp){
            inp.name = 'spouses[' + i + '][' + inp.getAttribute('data-name') + ']';
        });
        // Prefill address from residential address if empty
        var addrEl = node.querySelector('[data-name="address"]');
        var res = document.getElementById('empResAddress');
        if (data) {
            node.querySelectorAll('[data-name]').forEach(function(inp){
                var k = inp.getAttribute('data-name');
                if (data[k] !== undefined && data[k] !== null) inp.value = data[k];
            });
        } else if (addrEl && res && res.value.trim()) {
            addrEl.value = res.value.trim();
        }
        node.querySelector('.spouse-remove').addEventListener('click', function(){ node.remove(); });
        spouseRows.appendChild(node);
    }
    document.getElementById('addSpouseBtn').addEventListener('click', function(){ addSpouseRow(); });

    function toggleSpouseCard(){
        if (!spouseCard || !maritalSel) return;
        var married = maritalSel.value === 'married';
        spouseCard.style.opacity = married ? '1' : '0.6';
    }
    if (maritalSel) { maritalSel.addEventListener('change', toggleSpouseCard); }
    toggleSpouseCard();

    // ── Re-render dynamic rows from old() input after a validation error ──
    var oldEdu = @json(array_values($oldEduQual));
    var oldEduInst = @json(array_values($oldEduInst));
    var oldEduYear = @json(array_values($oldEduYear));
    if (oldEdu && oldEdu.length) {
        oldEdu.forEach(function(q, k){
            addEduRow({ qual: q, inst: oldEduInst[k] || '', year: oldEduYear[k] || '' });
        });
    }
    var oldSpouses = @json(array_values($oldSpouses));
    if (oldSpouses && oldSpouses.length) {
        oldSpouses.forEach(function(sp){ addSpouseRow(sp); });
    }
})();
</script>
@endsection
