@extends('layouts.app')
@section('title', 'Edit Offboarding')
@section('page-title', 'Edit Offboarding Record')
@section('content')

@php
    $managers = \App\Models\User::whereIn('role',['hr_manager','it_manager','superadmin'])
        ->where('is_active', true)->orderBy('name')->get();
@endphp

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('hr.offboarding.show', $offboarding) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Record
    </a>
    <span class="text-muted small">/ Edit Offboarding</span>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form action="{{ route('hr.offboarding.update', $offboarding) }}" method="POST" enctype="multipart/form-data">
    @csrf @method('PUT')

    {{-- SECTION A — Personal Details --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">A</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Personal Details</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control @error('full_name') is-invalid @enderror"
                           value="{{ old('full_name', $employee?->full_name ?? $offboarding->full_name) }}" required>
                    @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Preferred Name</label>
                    <input type="text" name="preferred_name" class="form-control"
                           value="{{ old('preferred_name', $employee?->preferred_name) }}" placeholder="Nickname / preferred name">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Document ID (IC / Passport) <span class="text-danger">*</span></label>
                    <input type="text" name="official_document_id" class="form-control @error('official_document_id') is-invalid @enderror"
                           value="{{ old('official_document_id', $employee?->official_document_id) }}" required>
                    @error('official_document_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                    @php $off_dob = old('date_of_birth', $employee?->date_of_birth?->format('Y-m-d')); @endphp
                    <input type="hidden" name="date_of_birth" id="off_dob_combined" value="{{ $off_dob }}">
                    @error('date_of_birth')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                    <div class="d-flex gap-1">
                        <select id="off_dob_day" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                            <option value="">Day</option>
                            @for($d = 1; $d <= 31; $d++)
                                <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                    {{ $off_dob && (int)explode('-',$off_dob)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                            @endfor
                        </select>
                        <select id="off_dob_month" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                            <option value="">Month</option>
                            @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                    {{ $off_dob && (int)explode('-',$off_dob)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                            @endforeach
                        </select>
                        <select id="off_dob_year" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                            <option value="">Year</option>
                            @for($y = date('Y'); $y >= 1940; $y--)
                                <option value="{{ $y }}"
                                    {{ $off_dob && (int)explode('-',$off_dob)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <script>
                    (function(){
                        function calcOffAge(dob){
                            var el=document.getElementById('off_age'); if(!el) return;
                            if(!dob){el.value='';return;}
                            var p=dob.split('-'),t=new Date();
                            var a=t.getFullYear()-+p[0];
                            el.value=(a>=0&&a<150)?a:'';
                        }
                        function sync(){
                            var d=document.getElementById('off_dob_day').value,
                                m=document.getElementById('off_dob_month').value,
                                y=document.getElementById('off_dob_year').value;
                            var dob=(y&&m&&d)?y+'-'+m+'-'+d:'';
                            document.getElementById('off_dob_combined').value=dob;
                            calcOffAge(dob);
                        }
                        ['off_dob_day','off_dob_month','off_dob_year'].forEach(function(id){
                            document.getElementById(id).addEventListener('change',sync);
                        });
                        document.addEventListener('DOMContentLoaded',function(){
                            calcOffAge(document.getElementById('off_dob_combined').value);
                        });
                    })();
                    </script>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Age</label>
                    <input type="text" id="off_age" class="form-control bg-light" readonly placeholder="—">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Sex <span class="text-danger">*</span></label>
                    <select name="sex" class="form-select" required>
                        <option value="male"   {{ old('sex', $employee?->sex) == 'male'   ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('sex', $employee?->sex) == 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Marital Status <span class="text-danger">*</span></label>
                    <select name="marital_status" id="offMaritalStatus" class="form-select" required
                            onchange="offToggleSpouseSection(this.value)">
                        @foreach(['single','married','divorced','widowed'] as $ms)
                            <option value="{{ $ms }}" {{ old('marital_status', $employee?->marital_status) == $ms ? 'selected' : '' }}>{{ ucfirst($ms) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Religion <span class="text-danger">*</span></label>
                    <input type="text" name="religion" class="form-control"
                           value="{{ old('religion', $employee?->religion) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Race <span class="text-danger">*</span></label>
                    <input type="text" name="race" class="form-control"
                           value="{{ old('race', $employee?->race) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tel No. (H/phone) <span class="text-danger">*</span></label>
                    <input type="text" name="personal_contact_number" class="form-control"
                           value="{{ old('personal_contact_number', $employee?->personal_contact_number) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tel No. (House)</label>
                    <input type="text" name="house_tel_no" class="form-control"
                           value="{{ old('house_tel_no', $employee?->house_tel_no) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Personal Email <span class="text-danger">*</span></label>
                    <input type="email" name="personal_email" class="form-control"
                           value="{{ old('personal_email', $employee?->personal_email ?? $offboarding->personal_email) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Bank Account Number <span class="text-danger">*</span></label>
                    <input type="text" name="bank_account_number" class="form-control"
                           value="{{ old('bank_account_number', $employee?->bank_account_number) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Bank Name</label>
                    @php $knownBanks = ['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank','Other']; @endphp
                    <select name="bank_name" id="offBankName" class="form-select"
                            onchange="toggleOtherBank(this,'offBankNameOther')">
                        <option value="">— Select Bank —</option>
                        @foreach($knownBanks as $bank)
                        <option value="{{ $bank }}" {{ old('bank_name', $employee?->bank_name) == $bank ? 'selected' : '' }}>{{ $bank }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 {{ in_array(old('bank_name', $employee?->bank_name ?? ''), ['Other','other']) ? '' : 'd-none' }}" id="offBankNameOther">
                    <label class="form-label fw-semibold">Other Bank Name</label>
                    <input type="text" name="bank_name_other" class="form-control"
                           value="{{ old('bank_name_other', in_array($employee?->bank_name, ['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank']) ? '' : $employee?->bank_name) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">EPF No.</label>
                    <input type="text" name="epf_no" class="form-control"
                           value="{{ old('epf_no', $employee?->epf_no) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Income Tax No.</label>
                    <input type="text" name="income_tax_no" class="form-control"
                           value="{{ old('income_tax_no', $employee?->income_tax_no) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">SOCSO No.</label>
                    <input type="text" name="socso_no" class="form-control"
                           value="{{ old('socso_no', $employee?->socso_no) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Disabled Person</label>
                    <select name="is_disabled" class="form-select">
                        <option value="0" {{ !old('is_disabled', $employee?->is_disabled ?? false) ? 'selected' : '' }}>No</option>
                        <option value="1" {{ old('is_disabled', $employee?->is_disabled ?? false) ? 'selected' : '' }}>Yes</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">NRIC / Passport Copy Upload
                        <span class="text-muted fw-normal small">(PDF/image, max 5 files)</span>
                    </label>
                    @php $existingNric = $employee?->nric_file_paths ?? ($employee?->nric_file_path ? [$employee->nric_file_path] : []); @endphp
                    <div id="nricExistingList" class="mb-2">
                        @foreach($existingNric as $idx => $path)
                        <div class="d-inline-flex align-items-center gap-1 me-1 mb-1">
                            <a href="{{ secure_file_url($path) }}" target="_blank"
                               class="btn btn-sm btn-outline-primary" style="font-size:12px;">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $idx+1 }}
                            </a>
                            <input type="hidden" name="nric_keep_paths[]" value="{{ $path }}" class="nric-keep-input">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                    style="font-size:12px;" onclick="removeNricExisting(this)" title="Remove this file">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                        @endforeach
                    </div>
                    <input type="hidden" name="nric_keep_submitted" value="1">
                    <div class="d-flex gap-2 mb-1">
                        <input type="file" id="nricNewFileInput" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="max-width:340px;">
                        <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" onclick="addNricFile()">
                            <i class="bi bi-upload me-1"></i>Add
                        </button>
                    </div>
                    <div id="nricNewList"></div>
                    <div id="nricNewHidden"></div>
                    <div class="form-text">Max 5 files total. Click <i class="bi bi-x"></i> to remove a file.</div>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Residential Address <span class="text-danger">*</span></label>
                    <textarea name="residential_address" id="offResAddress" class="form-control" rows="2" required>{{ old('residential_address', $employee?->residential_address) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    {{-- SECTION B — Work Details --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">B</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-briefcase me-2 text-primary"></i>Work Details</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employment Type <span class="text-danger">*</span></label>
                    <select name="employment_type" class="form-select" required>
                        @foreach(['permanent','intern','contract'] as $et)
                            <option value="{{ $et }}" {{ old('employment_type', $employee?->employment_type) == $et ? 'selected' : '' }}>{{ ucfirst($et) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employment Status</label>
                    <select name="employment_status" class="form-select">
                        @foreach(['active'=>'Active','resigned'=>'Resigned','terminated'=>'Terminated','contract_ended'=>'Contract Ended'] as $val=>$label)
                            <option value="{{ $val }}" {{ old('employment_status', $employee?->employment_status ?? 'resigned') == $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Designation <span class="text-danger">*</span></label>
                    <input type="text" name="designation" class="form-control @error('designation') is-invalid @enderror"
                           value="{{ old('designation', $offboarding->designation ?? $employee?->designation) }}" required>
                    @error('designation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Department</label>
                    <input type="text" name="department" class="form-control"
                           value="{{ old('department', $offboarding->department ?? $employee?->department) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                    @php $offCurrentCompany = old('company', $offboarding->company ?? $employee?->company); @endphp
                    <select name="company" id="offCompanySelect"
                            class="form-select @error('company') is-invalid @enderror"
                            onchange="autofillOfficeLocation(this, 'offOfficeLocation'); filterManagersByCompany(this.value, 'offMgrSelect')" required>
                        <option value="">Select company...</option>
                        @foreach($companies as $c)
                            <option value="{{ $c->name }}"
                                    data-address="{{ $c->address }}"
                                    {{ $offCurrentCompany == $c->name ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                        @if($offCurrentCompany && !$companies->pluck('name')->contains($offCurrentCompany))
                            <option value="{{ $offCurrentCompany }}" selected>{{ $offCurrentCompany }}</option>
                        @endif
                    </select>
                    @error('company')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Office Location</label>
                    <input type="text" name="office_location" id="offOfficeLocation" class="form-control"
                           value="{{ old('office_location', $employee?->office_location) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Reporting Manager</label>
                    <select name="reporting_manager" id="offMgrSelect" class="form-select">
                        <option value="">— Select manager —</option>
                        @php $currentMgr = old('reporting_manager', $employee?->reporting_manager); @endphp
                        @if($currentMgr && !$managers->pluck('name')->contains($currentMgr))
                            <option value="{{ $currentMgr }}" selected>{{ $currentMgr }} (current)</option>
                        @endif
                        @foreach($managers as $mgr)
                            @php
                                $roleLabelsOff = [
                                    'hr_manager'          => 'HR Manager',
                                    'hr_executive'        => 'HR Executive',
                                    'hr_intern'           => 'HR Intern',
                                    'it_manager'          => 'IT Manager',
                                    'it_executive'        => 'IT Executive',
                                    'it_intern'           => 'IT Intern',
                                    'superadmin'          => 'SuperAdmin',
                                    'system_admin'        => 'System Admin',
                                    'manager'             => 'Manager',
                                    'senior_executive'    => 'Senior Executive',
                                    'executive_associate' => 'Executive / Associate',
                                    'director_hod'        => 'Director / HOD',
                                    'others'              => 'Others',
                                ];
                            @endphp
                            <option value="{{ $mgr->name }}"
                                data-company="{{ $mgr->employee?->company }}"
                                {{ $currentMgr == $mgr->name ? 'selected' : '' }}>
                                {{ $mgr->name }} ({{ $roleLabelsOff[$mgr->role ?? ''] ?? ucfirst(str_replace('_',' ',$mgr->role ?? '')) }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Start Date</label>
                    @php $off_sd = old('start_date', $employee?->start_date?->format('Y-m-d')); @endphp
                    <input type="hidden" name="start_date" id="off_sd_combined" value="{{ $off_sd }}">
                    <div class="d-flex gap-1">
                        <select id="off_sd_day" class="form-select" style="min-width:0">
                            <option value="">Day</option>
                            @for($d = 1; $d <= 31; $d++)
                                <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                    {{ $off_sd && (int)explode('-',$off_sd)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                            @endfor
                        </select>
                        <select id="off_sd_month" class="form-select" style="min-width:0">
                            <option value="">Month</option>
                            @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                    {{ $off_sd && (int)explode('-',$off_sd)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                            @endforeach
                        </select>
                        <select id="off_sd_year" class="form-select" style="min-width:0">
                            <option value="">Year</option>
                            @for($y = date('Y') + 2; $y >= 1990; $y--)
                                <option value="{{ $y }}"
                                    {{ $off_sd && (int)explode('-',$off_sd)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <script>
                    (function(){
                        function sync(){
                            var d=document.getElementById('off_sd_day').value,
                                m=document.getElementById('off_sd_month').value,
                                y=document.getElementById('off_sd_year').value;
                            document.getElementById('off_sd_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                        }
                        ['off_sd_day','off_sd_month','off_sd_year'].forEach(function(id){
                            document.getElementById(id).addEventListener('change',sync);
                        });
                    })();
                    </script>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Exit Date</label>
                    @php $off_ed = old('exit_date', $offboarding->exit_date?->format('Y-m-d')); @endphp
                    <input type="hidden" name="exit_date" id="off_ed_combined" value="{{ $off_ed }}">
                    <div class="d-flex gap-1">
                        <select id="off_ed_day" class="form-select" style="min-width:0">
                            <option value="">Day</option>
                            @for($d = 1; $d <= 31; $d++)
                                <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                    {{ $off_ed && (int)explode('-',$off_ed)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                            @endfor
                        </select>
                        <select id="off_ed_month" class="form-select" style="min-width:0">
                            <option value="">Month</option>
                            @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                    {{ $off_ed && (int)explode('-',$off_ed)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                            @endforeach
                        </select>
                        <select id="off_ed_year" class="form-select" style="min-width:0">
                            <option value="">Year</option>
                            @for($y = date('Y') + 2; $y >= 1990; $y--)
                                <option value="{{ $y }}"
                                    {{ $off_ed && (int)explode('-',$off_ed)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <div class="form-text">Changing this resets pending notification emails.</div>
                    <script>
                    (function(){
                        function sync(){
                            var d=document.getElementById('off_ed_day').value,
                                m=document.getElementById('off_ed_month').value,
                                y=document.getElementById('off_ed_year').value;
                            document.getElementById('off_ed_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                        }
                        ['off_ed_day','off_ed_month','off_ed_year'].forEach(function(id){
                            document.getElementById(id).addEventListener('change',sync);
                        });
                    })();
                    </script>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Last Salary Date</label>
                    @if(Auth::user()->isHrManager())
                        @php $off_lsd = old('last_salary_date', $employee?->last_salary_date?->format('Y-m-d')); @endphp
                        <input type="hidden" name="last_salary_date" id="off_lsd_combined" value="{{ $off_lsd }}">
                        <div class="d-flex gap-1">
                            <select id="off_lsd_day" class="form-select" style="min-width:0">
                                <option value="">Day</option>
                                @for($d = 1; $d <= 31; $d++)
                                    <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                        {{ $off_lsd && (int)explode('-',$off_lsd)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                                @endfor
                            </select>
                            <select id="off_lsd_month" class="form-select" style="min-width:0">
                                <option value="">Month</option>
                                @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                    <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                        {{ $off_lsd && (int)explode('-',$off_lsd)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                                @endforeach
                            </select>
                            <select id="off_lsd_year" class="form-select" style="min-width:0">
                                <option value="">Year</option>
                                @for($y = date('Y') + 2; $y >= 1990; $y--)
                                    <option value="{{ $y }}"
                                        {{ $off_lsd && (int)explode('-',$off_lsd)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <script>
                        (function(){
                            function sync(){
                                var d=document.getElementById('off_lsd_day').value,
                                    m=document.getElementById('off_lsd_month').value,
                                    y=document.getElementById('off_lsd_year').value;
                                document.getElementById('off_lsd_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                            }
                            ['off_lsd_day','off_lsd_month','off_lsd_year'].forEach(function(id){
                                document.getElementById(id).addEventListener('change',sync);
                            });
                        })();
                        </script>
                    @else
                        @php $lsdDisplay = $employee?->last_salary_date?->format('d M Y'); @endphp
                        <input type="text" class="form-control bg-light" readonly value="{{ $lsdDisplay ?: '—' }}">
                    @endif
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Company Email</label>
                    <input type="email" name="company_email" id="edit_company_email" class="form-control"
                           value="{{ old('company_email', $offboarding->company_email ?? $employee?->company_email) }}"
                           oninput="syncGoogleId(this.value)">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Google ID</label>
                    <input type="text" name="google_id" id="edit_google_id" class="form-control"
                           value="{{ old('google_id', $employee?->google_id) }}" readonly style="background:#f8fafc;">
                    <div class="form-text">Auto-mirrors Company Email.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Reason for Leaving</label>
                    <input type="text" name="reason" class="form-control"
                           value="{{ old('reason', $offboarding->reason) }}" placeholder="e.g. Resigned, Contract ended">
                </div>
            </div>
        </div>
    </div>

    {{-- SECTION C — Asset Assignment (view only) --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #94a3b8;">
            <span class="badge bg-secondary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">C</span>
            <h6 class="mb-0 fw-bold text-muted"><i class="bi bi-box-seam me-2"></i>Asset Assignment</h6>
            <span class="ms-auto badge bg-light text-secondary border" style="font-size:11px;"><i class="bi bi-lock me-1"></i>Managed by IT — view only</span>
        </div>
        <div class="card-body p-0">
            @if($directAssets->isEmpty())
                <p class="text-muted small p-3 mb-0"><i class="bi bi-info-circle me-1"></i>No assets currently assigned.</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th class="ps-3">Asset Tag</th><th>Type</th><th>Brand / Model</th><th>Serial No.</th><th>Assigned Date</th><th>Condition</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($directAssets as $ea)
                        <tr style="opacity:.75;">
                            <td class="ps-3"><code>{{ $ea->asset_tag }}</code></td>
                            <td>{{ ucfirst(str_replace('_',' ',$ea->asset_type)) }}</td>
                            <td class="text-muted small">{{ trim(($ea->brand ?? '').' '.($ea->model ?? '')) ?: '—' }}</td>
                            <td class="text-muted small">{{ $ea->serial_number ?? '—' }}</td>
                            <td>{{ $ea->asset_assigned_date?->format('d M Y') ?? '—' }}</td>
                            <td>
                                @php $cc = ['new'=>'success','good'=>'primary','fair'=>'warning','damaged'=>'danger','not_good'=>'danger','under_maintenance'=>'warning'][$ea->asset_condition ?? ''] ?? 'secondary'; @endphp
                                <span class="badge bg-{{ $cc }}">{{ ucfirst(str_replace('_',' ',$ea->asset_condition ?? '—')) }}</span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    {{-- SECTION D — Access Role --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">D</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Access Role</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">System Role <span class="text-danger">*</span></label>
                    <select name="work_role" class="form-select @error('work_role') is-invalid @enderror" required>
                        <option value="">Select role...</option>
                        @foreach([
                            'manager'=>'Manager','senior_executive'=>'Senior Executive',
                            'executive_associate'=>'Executive / Associate','director_hod'=>'Director / Head of Department',
                            'hr_manager'=>'HR Manager','hr_executive'=>'HR Executive','hr_intern'=>'HR Intern',
                            'it_manager'=>'IT Manager','it_executive'=>'IT Executive','it_intern'=>'IT Intern',
                            'superadmin'=>'Superadmin','system_admin'=>'System Admin','others'=>'Others',
                        ] as $val => $label)
                            <option value="{{ $val }}" {{ old('work_role', $employee?->work_role) == $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('work_role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </div>
    </div>

    {{-- Remarks --}}
    <div class="card mb-3">
        <div class="card-body">
            <label class="form-label fw-semibold">Remarks <span class="text-muted fw-normal">(optional — appended to record)</span></label>
            <textarea name="remarks" class="form-control" rows="2" placeholder="Reason for update or any notes...">{{ old('remarks', $offboarding->remarks) }}</textarea>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end mb-4">
        <a href="{{ route('hr.offboarding.show', $offboarding) }}" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check-circle me-2"></i>Save Changes
        </button>
    </div>
</form>

{{-- SECTION E — Documents (separate multipart forms, outside PUT form) --}}
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">E</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-folder2-open me-2 text-primary"></i>Documents</h6>
    </div>
    <div class="card-body">
        @if(!$employee)
            <p class="text-muted small mb-0">No employee record linked to manage documents.</p>
        @else
        <div class="row g-4">
            {{-- Contract --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:40px;height:40px;background:#dbeafe;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-file-earmark-text" style="font-size:19px;color:#2563eb;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small">Employment Contract</div>
                            <div class="text-muted" style="font-size:11px;">PDF, DOC, DOCX &middot; max 10 MB</div>
                        </div>
                    </div>
                    @if($employee->contracts->isNotEmpty())
                        <div>
                            <p class="text-muted mb-2" style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">Uploaded ({{ $employee->contracts->count() }})</p>
                            @foreach($employee->contracts as $contract)
                            <div class="d-flex align-items-start justify-content-between gap-2 py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                                <div class="text-truncate" style="font-size:12px;">
                                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
                                    <span title="{{ $contract->original_filename }}">{{ $contract->original_filename }}</span>
                                    <div class="text-muted" style="font-size:11px;">{{ $contract->file_size_label }} &middot; {{ $contract->created_at->format('d M Y') }}@if($contract->notes)<br>{{ $contract->notes }}@endif</div>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <a href="{{ route('employees.contracts.download', [$employee, $contract]) }}" class="btn btn-outline-primary btn-sm" style="padding:2px 7px;" title="Download">
                                        <i class="bi bi-download" style="font-size:12px;"></i>
                                    </a>
                                    <form action="{{ route('employees.contracts.delete', [$employee, $contract]) }}" method="POST" onsubmit="return confirm('Delete this contract?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-outline-danger btn-sm" style="padding:2px 7px;" title="Delete">
                                            <i class="bi bi-trash" style="font-size:12px;"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-muted small mb-0">No contract uploaded yet.</p>
                    @endif
                    <form action="{{ route('employees.contracts.upload', $employee) }}" method="POST" enctype="multipart/form-data" class="mt-auto pt-2 border-top">
                        @csrf
                        <p class="fw-semibold small mb-2">Upload New Contract</p>
                        <input type="file" name="contract_file" accept=".pdf,.doc,.docx" class="form-control form-control-sm mb-2 @error('contract_file') is-invalid @enderror" required>
                        @error('contract_file')<div class="invalid-feedback" style="font-size:11px;">{{ $message }}</div>@enderror
                        <input type="text" name="notes" class="form-control form-control-sm mb-2" placeholder="Notes (optional)" maxlength="500">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-upload me-1"></i>Upload Contract</button>
                    </form>
                </div>
            </div>
            {{-- Handbook --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:40px;height:40px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-book" style="font-size:19px;color:#16a34a;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small">Employee Handbook</div>
                            <div class="text-muted" style="font-size:11px;">PDF only &middot; max 20 MB</div>
                        </div>
                    </div>
                    @if($employee->handbook_path)
                        <div class="d-flex align-items-center justify-content-between gap-2 p-2 rounded-2" style="background:#dcfce7;font-size:12px;">
                            <span><i class="bi bi-file-earmark-check-fill text-success me-1"></i>Personalised handbook uploaded</span>
                            <div class="d-flex gap-1">
                                <a href="{{ secure_file_url($employee->handbook_path) }}" target="_blank" class="btn btn-outline-success btn-sm" style="padding:2px 7px;"><i class="bi bi-eye" style="font-size:12px;"></i></a>
                                <form action="{{ route('employees.handbook.delete', $employee) }}" method="POST" onsubmit="return confirm('Remove this handbook?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" style="padding:2px 7px;"><i class="bi bi-trash" style="font-size:12px;"></i></button>
                                </form>
                            </div>
                        </div>
                    @else
                        <p class="text-muted small mb-0">No personalised handbook. Default will be shown.</p>
                    @endif
                    <form action="{{ route('employees.handbook.upload', $employee) }}" method="POST" enctype="multipart/form-data" class="mt-auto pt-2 border-top">
                        @csrf
                        <p class="fw-semibold small mb-2">{{ $employee->handbook_path ? 'Replace Handbook' : 'Upload Handbook' }}</p>
                        <input type="file" name="handbook_file" accept=".pdf" class="form-control form-control-sm mb-2 @error('handbook_file') is-invalid @enderror" required>
                        @error('handbook_file')<div class="invalid-feedback" style="font-size:11px;">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-upload me-1"></i>{{ $employee->handbook_path ? 'Replace' : 'Upload' }} Handbook</button>
                    </form>
                </div>
            </div>
            {{-- Orientation --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <div style="width:40px;height:40px;background:#fef3c7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-easel" style="font-size:19px;color:#d97706;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small">Orientation Slide</div>
                            <div class="text-muted" style="font-size:11px;">PDF only &middot; max 20 MB</div>
                        </div>
                    </div>
                    @if($employee->orientation_path)
                        <div class="d-flex align-items-center justify-content-between gap-2 p-2 rounded-2" style="background:#fef3c7;font-size:12px;">
                            <span><i class="bi bi-file-earmark-check-fill text-warning me-1"></i>Personalised slide uploaded</span>
                            <div class="d-flex gap-1">
                                <a href="{{ secure_file_url($employee->orientation_path) }}" target="_blank" class="btn btn-outline-warning btn-sm" style="padding:2px 7px;"><i class="bi bi-eye" style="font-size:12px;"></i></a>
                                <form action="{{ route('employees.orientation.delete', $employee) }}" method="POST" onsubmit="return confirm('Remove this orientation slide?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger btn-sm" style="padding:2px 7px;"><i class="bi bi-trash" style="font-size:12px;"></i></button>
                                </form>
                            </div>
                        </div>
                    @else
                        <p class="text-muted small mb-0">No personalised slide. Default will be shown.</p>
                    @endif
                    <form action="{{ route('employees.orientation.upload', $employee) }}" method="POST" enctype="multipart/form-data" class="mt-auto pt-2 border-top">
                        @csrf
                        <p class="fw-semibold small mb-2">{{ $employee->orientation_path ? 'Replace Slide' : 'Upload Slide' }}</p>
                        <input type="file" name="orientation_file" accept=".pdf" class="form-control form-control-sm mb-2 @error('orientation_file') is-invalid @enderror" required>
                        @error('orientation_file')<div class="invalid-feedback" style="font-size:11px;">{{ $message }}</div>@enderror
                        <button type="submit" class="btn btn-warning btn-sm w-100"><i class="bi bi-upload me-1"></i>{{ $employee->orientation_path ? 'Replace' : 'Upload' }} Slide</button>
                    </form>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

@if($employee)

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION F — Education & Work History                                  --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@php $eduList = $employee->educationHistories ?? collect(); @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">F</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Education &amp; Work History</h6>
    </div>
    <div class="card-body">
        <form action="{{ route('hr.offboarding.update', $offboarding) }}" method="POST" enctype="multipart/form-data">
        @csrf @method('PUT')
        <input type="hidden" name="edu_delete_ids" id="offEduDeleteIds" value="">
        <div id="offEduContainer">
            @forelse($eduList as $edu)
            <div class="border rounded p-3 mb-3 off-edu-row" data-id="{{ $edu->id }}">
                <input type="hidden" name="edu_id[]" value="{{ $edu->id }}">
                <div class="d-flex align-items-start justify-content-between">
                    <div class="edu-summary">
                        <div class="fw-semibold">{{ $edu->qualification }}</div>
                        <div class="text-muted small">
                            {{ $edu->institution ?? '' }}{{ $edu->year_graduated ? ' · '.$edu->year_graduated : '' }}
                        </div>
                        @php $editCertFiles = $edu->certificate_paths ?? ($edu->certificate_path ? [$edu->certificate_path] : []); @endphp
                        @if(!empty($editCertFiles))
                        <div class="mt-1">
                            @foreach($editCertFiles as $ci => $cf)
                            <a href="{{ secure_file_url($cf) }}" target="_blank"
                               class="btn btn-sm btn-outline-primary me-1 mb-1" style="padding:2px 8px;font-size:11px;">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $ci + 1 }}
                            </a>
                            @endforeach
                        </div>
                        @endif
                    </div>
                    <div class="d-flex gap-1 ms-2 flex-shrink-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                onclick="offToggleEduEdit(this)">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                onclick="offMarkEduDelete(this, {{ $edu->id }})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                @php $inlineCerts = $edu->certificate_paths ?? ($edu->certificate_path ? [$edu->certificate_path] : []); @endphp
                <div class="off-edu-fields mt-3 d-none" data-edu-idx="{{ $loop->index }}">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Qualification</label>
                            <input type="text" name="edu_qualification[]" class="form-control" value="{{ $edu->qualification }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Institution</label>
                            <input type="text" name="edu_institution[]" class="form-control" value="{{ $edu->institution }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Year Graduated</label>
                            <input type="number" name="edu_year[]" class="form-control" value="{{ $edu->year_graduated }}" min="1950" max="{{ date('Y')+5 }}">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label fw-semibold">Certificate(s) <span class="text-muted fw-normal small">(max 5 files)</span></label>
                            <div class="edu-cert-existing mb-2">
                                @foreach($inlineCerts as $ci => $cf)
                                <div class="d-inline-flex align-items-center gap-1 me-1 mb-1">
                                    <a href="{{ secure_file_url($cf) }}" target="_blank"
                                       class="btn btn-sm btn-outline-primary" style="font-size:11px;">
                                        <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $ci+1 }}
                                    </a>
                                    <input type="hidden" name="edu_cert_keep[{{ $loop->parent->index }}][]"
                                           value="{{ $cf }}" class="edu-cert-keep-input">
                                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                            style="font-size:11px;" onclick="offRemoveEduCert(this)" title="Remove this file">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                                @endforeach
                                {{-- FIX: sentinel — always submitted so controller knows cert section was opened --}}
                                <input type="hidden" name="edu_cert_keep_submitted[{{ $loop->index }}]" value="1">
                            </div>
                            <div class="d-flex gap-2 mb-1">
                                <input type="file" class="off-edu-cert-file-input form-control form-control-sm"
                                       accept=".jpg,.jpeg,.png,.pdf" style="max-width:280px;"
                                       data-idx="{{ $loop->index }}">
                                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0"
                                        onclick="offAddEduCertFile(this, {{ $loop->index }})">
                                    <i class="bi bi-upload me-1"></i>Add
                                </button>
                            </div>
                            <div class="off-edu-cert-new-list" data-idx="{{ $loop->index }}"></div>
                            <div class="off-edu-cert-new-hidden" data-idx="{{ $loop->index }}"></div>
                            <div class="form-text">Click <i class="bi bi-x"></i> to remove. Existing files kept unless removed.</div>
                        </div>
                    </div>
                </div>
            </div>
            @empty
            <p class="text-muted small" id="offNoEduMsg">No education history yet.</p>
            @endforelse
        </div>
        <div class="d-flex gap-2 mt-2 mb-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="offAddEduRow()">
                <i class="bi bi-plus-circle me-1"></i>Add Qualification
            </button>
        </div>
        @php $expTotal = $employee->educationHistories->first()?->years_experience ?? null; @endphp
        <div class="row g-3 mb-3">
            <div class="col-md-5">
                <label class="form-label fw-semibold">No. of Years of Working Experience <span class="text-muted fw-normal small">(not incl. part-time)</span></label>
                <select name="edu_experience_total" class="form-select">
                    <option value="">— Select —</option>
                    @for($y = 0; $y <= 40; $y++)
                    <option value="{{ $y }}" {{ old('edu_experience_total', $expTotal) == $y ? 'selected' : '' }}>
                        {{ $y }} {{ $y == 1 ? 'year' : 'years' }}
                    </option>
                    @endfor
                    <option value="40+" {{ old('edu_experience_total', $expTotal) === '40+' ? 'selected' : '' }}>40+ years</option>
                </select>
            </div>
        </div>
        <div class="text-end mt-2">
            <button type="submit" class="btn btn-primary btn-sm px-4">
                <i class="bi bi-check-circle me-1"></i>Save Education
            </button>
        </div>
        </form>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION G — Spouse Information                                        --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@php $spouses = $employee->spouseDetails ?? collect(); @endphp
<div class="card mb-3" id="offSectionG">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">G</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Spouse Information</h6>
    </div>
    <div class="card-body">
        @foreach($spouses as $sp)
        <div class="border rounded p-3 mb-3 off-spouse-card" style="background:#f8fafc;">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="fw-semibold">{{ $sp->name }}</div>
                    <div class="text-muted small">
                        {{ $sp->nric_no ? 'NRIC: '.$sp->nric_no.' · ' : '' }}
                        {{ $sp->tel_no ? 'Tel: '.$sp->tel_no.' · ' : '' }}
                        {{ $sp->occupation ?? '' }}
                        {{ $sp->is_working ? ' · Working' : '' }}
                    </div>
                </div>
                <div class="d-flex gap-1 ms-2 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="offToggleSpouseEdit(this)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <form action="{{ route('employees.spouse.delete', [$employee, $sp->id]) }}" method="POST"
                          class="d-inline" onsubmit="return confirm('Remove this spouse record?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
            <div class="off-spouse-edit-fields mt-3 d-none">
                <form action="{{ route('employees.spouse.edit', [$employee, $sp->id]) }}" method="POST" onsubmit="return offValidateSpouseTel(this)">
                @csrf @method('PUT')
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                        <input type="text" name="spouse_name" class="form-control form-control-sm" value="{{ $sp->name }}" required></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">NRIC No.</label>
                        <input type="text" name="spouse_nric_no" class="form-control form-control-sm" value="{{ $sp->nric_no }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Tel No. <span class="text-danger">*</span></label>
                        <input type="text" name="spouse_tel_no" class="form-control form-control-sm" value="{{ $sp->tel_no }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Occupation</label>
                        <input type="text" name="spouse_occupation" class="form-control form-control-sm" value="{{ $sp->occupation }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Income Tax No.</label>
                        <input type="text" name="spouse_income_tax_no" class="form-control form-control-sm" value="{{ $sp->income_tax_no }}"></div>
                    <div class="col-12"><label class="form-label fw-semibold small">Address</label>
                        <textarea name="spouse_address" class="form-control form-control-sm" rows="2">{{ $sp->address }}</textarea></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Working?</label>
                        <select name="spouse_is_working" class="form-select form-select-sm">
                            <option value="0" {{ !$sp->is_working ? 'selected' : '' }}>No</option>
                            <option value="1" {{ $sp->is_working ? 'selected' : '' }}>Yes</option>
                        </select></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Disabled?</label>
                        <select name="spouse_is_disabled" class="form-select form-select-sm">
                            <option value="0" {{ !$sp->is_disabled ? 'selected' : '' }}>No</option>
                            <option value="1" {{ $sp->is_disabled ? 'selected' : '' }}>Yes</option>
                        </select></div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary btn-sm px-4">
                            <i class="bi bi-check-circle me-1"></i>Save Changes
                        </button>
                    </div>
                </div>
                </form>
            </div>
        </div>
        @endforeach
        <p class="fw-semibold small text-muted mb-2">Add {{ $spouses->isEmpty() ? 'Spouse' : 'Another Spouse' }}</p>
        <form id="offAddSpouseForm" action="{{ route('employees.spouse.update', $employee) }}" method="POST" onsubmit="return offValidateSpouseTel(this)">
        @csrf
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" name="spouse_name" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold">NRIC No.</label>
                <input type="text" name="spouse_nric_no" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Tel No. <span class="text-danger">*</span></label>
                <input type="text" name="spouse_tel_no" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Occupation</label>
                <input type="text" name="spouse_occupation" class="form-control"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Income Tax No.</label>
                <input type="text" name="spouse_income_tax_no" class="form-control"></div>
            <div class="col-12"><label class="form-label fw-semibold">Address</label>
                <textarea name="spouse_address" class="form-control" rows="2"></textarea></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Working?</label>
                <select name="spouse_is_working" class="form-select">
                    <option value="0">No</option><option value="1">Yes</option>
                </select></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Disabled?</label>
                <select name="spouse_is_disabled" class="form-select">
                    <option value="0">No</option><option value="1">Yes</option>
                </select></div>
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-plus-circle me-1"></i>Add Spouse
                </button>
            </div>
        </div>
        </form>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION H — Emergency Contacts                                        --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@php $ec = $employee->emergencyContacts->keyBy('contact_order'); @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">H</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Emergency Contacts</h6>
        <span class="text-muted small ms-1">(2 required)</span>
    </div>
    <div class="card-body">
        <form action="{{ route('employees.emergency.update', $employee) }}" method="POST">
        @csrf
        @foreach([1,2] as $n)
        @php $contact = $ec[$n] ?? null; @endphp
        <div class="{{ $n==2 ? 'mt-3 pt-3 border-top' : '' }}">
            <p class="fw-semibold small text-muted mb-2">Contact {{ $n }}</p>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" name="emergency[{{ $n }}][name]" class="form-control"
                           value="{{ old("emergency.{$n}.name", $contact?->name) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tel No. <span class="text-danger">*</span></label>
                    <input type="text" name="emergency[{{ $n }}][tel_no]" class="form-control"
                           value="{{ old("emergency.{$n}.tel_no", $contact?->tel_no) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Relationship <span class="text-danger">*</span></label>
                    <select name="emergency[{{ $n }}][relationship]" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach(['Spouse','Parent','Sibling','Child','Friend','Colleague','Other'] as $rel)
                        <option value="{{ $rel }}"
                            {{ old("emergency.{$n}.relationship", $contact?->relationship) === $rel ? 'selected' : '' }}>
                            {{ $rel }}
                        </option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        @endforeach
        <div class="text-end mt-3">
            <button type="submit" class="btn btn-primary btn-sm px-4">
                <i class="bi bi-check-circle me-1"></i>Save Emergency Contacts
            </button>
        </div>
        </form>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION I — Child Registration                                        --}}
{{-- ══════════════════════════════════════════════════════════════════════ --}}
@php
    $ch = $employee->childRegistration;
    $catLabels = [
        'a' => 'a) Children under 18 years old',
        'b' => 'b) Children aged 18 years and above (still studying at the certificate and matriculation level)',
        'c' => 'c) Above 18 years (studying Diploma level or higher in Malaysia or elsewhere)',
        'd' => 'd) Disabled Child below 18 years old',
        'e' => 'e) Disabled Child (studying Diploma level or higher in Malaysia or elsewhere)',
    ];
@endphp
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">I</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-heart me-2 text-primary"></i>Child Registration (LHDN Tax Relief)</h6>
    </div>
    <div class="card-body">
        <form action="{{ route('employees.children.update', $employee) }}" method="POST">
        @csrf
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
                                   value="{{ old("cat_{$key}_100", $ch?->{"cat_{$key}_100"} ?? 0) }}" min="0" max="99" style="width:70px;margin:auto;">
                        </td>
                        <td class="text-center" style="width:120px;">
                            <input type="number" name="cat_{{ $key }}_50" class="form-control form-control-sm text-center"
                                   value="{{ old("cat_{$key}_50", $ch?->{"cat_{$key}_50"} ?? 0) }}" min="0" max="99" style="width:70px;margin:auto;">
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="text-end mt-2">
            <button type="submit" class="btn btn-primary btn-sm px-4">
                <i class="bi bi-check-circle me-1"></i>Save Child Registration
            </button>
        </div>
        </form>
    </div>
</div>

{{-- Declaration & Consent --}}
@php $consentAt = $employee->consent_given_at; @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2"
         style="border-left:4px solid {{ $consentAt ? '#16a34a' : '#94a3b8' }};">
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
                    @if($employee->consent_ip) — IP: {{ $employee->consent_ip }} @endif
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

{{-- Edit & Consent Acknowledgement Log --}}
@if($employee->editLogs->isNotEmpty())
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #94a3b8;">
        <h6 class="mb-0 fw-bold text-muted"><i class="bi bi-clock-history me-2"></i>Edit &amp; Consent Acknowledgement Log</h6>
    </div>
    <div style="overflow:hidden;">
        <div style="overflow-x:auto;">
            <table class="table table-sm align-middle mb-0" style="font-size:12.5px;min-width:900px;">
                <thead style="background:#f8fafc;position:sticky;top:0;z-index:1;">
                    <tr>
                        <th class="ps-3">Date &amp; Time</th>
                        <th>Edited By</th>
                        <th>Sections Changed</th>
                        <th>Sent To</th>
                        <th>Consent Status</th>
                        <th>Acknowledged By</th>
                        <th class="pe-3">Acknowledged At</th>
                    </tr>
                </thead>
            </table>
        </div>
        <div style="max-height:280px;overflow-y:auto;overflow-x:auto;">
            <table class="table table-sm align-middle mb-0" style="font-size:12.5px;min-width:900px;">
                <tbody>
                    @foreach($employee->editLogs as $log)
                    <tr>
                        <td class="ps-3 text-muted" style="width:160px;">{{ $log->created_at->format('d M Y, h:i A') }}</td>
                        <td style="width:160px;">
                            <div class="fw-semibold">{{ $log->edited_by_name ?? '—' }}</div>
                            <div class="text-muted" style="font-size:11px;">{{ ucfirst(str_replace('_',' ',$log->edited_by_role ?? '')) }}</div>
                        </td>
                        <td>
                            @if(!empty($log->sections_changed))
                                @foreach($log->sections_changed as $sec)
                                <span class="badge bg-light text-dark border me-1 mb-1" style="font-size:11px;">{{ $sec }}</span>
                                @endforeach
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            @if($log->change_notes)
                            <div class="text-muted mt-1" style="font-size:11px;"><i class="bi bi-chat-left-text me-1"></i>{{ $log->change_notes }}</div>
                            @endif
                        </td>
                        <td class="text-muted" style="font-size:11px;">{{ $log->consent_sent_to_email ?? '—' }}</td>
                        <td>
                            @if(!$log->consent_required)
                                <span class="badge bg-secondary" style="font-size:11px;">Not required</span>
                            @elseif($log->isAcknowledged())
                                <span class="badge bg-success" style="font-size:11px;"><i class="bi bi-check-circle me-1"></i>Acknowledged</span>
                            @elseif($log->isTokenExpired())
                                <span class="badge bg-warning text-dark" style="font-size:11px;"><i class="bi bi-exclamation-triangle me-1"></i>Expired</span>
                            @else
                                <span class="badge bg-danger" style="font-size:11px;"><i class="bi bi-clock me-1"></i>Pending</span>
                            @endif
                        </td>
                        <td>{{ $log->acknowledged_by_name ?? '—' }}</td>
                        <td class="pe-3 text-muted">
                            {{ $log->acknowledged_at?->format('d M Y, h:i A') ?? '—' }}
                            @if($log->acknowledgement_notes)
                            <div style="font-size:11px;color:#64748b;"><i class="bi bi-chat-left-text me-1"></i>{{ $log->acknowledgement_notes }}</div>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

@endif {{-- end @if($employee) --}}

@endsection

@push('scripts')
<script>
function autofillOfficeLocation(selectEl, targetId) {
    const selected = selectEl.options[selectEl.selectedIndex];
    const target   = document.getElementById(targetId);
    if (!target || !selected || !selected.value) return;
    target.value = selected.dataset.address || '-';
}

function filterManagersByCompany(companyName, mgrSelectId) {
    const sel = document.getElementById(mgrSelectId);
    if (!sel) return;
    Array.from(sel.options).forEach(function(opt) {
        if (!opt.value || !opt.dataset.company) return;
        var match = !companyName || opt.dataset.company === companyName;
        opt.hidden   = !match;
        opt.disabled = !match;
    });
    var chosen = sel.options[sel.selectedIndex];
    if (chosen && chosen.value && chosen.hidden) sel.value = '';
}

function syncGoogleId(val) {
    const g = document.getElementById('edit_google_id');
    if (g) g.value = val;
}

function toggleOtherBank(sel, otherId) {
    const el = document.getElementById(otherId);
    if (el) el.classList.toggle('d-none', sel.value !== 'Other');
}

document.addEventListener('DOMContentLoaded', function () {
    const ce  = document.getElementById('edit_company_email');
    const gid = document.getElementById('edit_google_id');
    if (ce && gid && ce.value && !gid.value) gid.value = ce.value;

    const b = document.getElementById('offBankName');
    if (b) toggleOtherBank(b, 'offBankNameOther');

    var offCo = document.getElementById('offCompanySelect');
    if (offCo && offCo.value) filterManagersByCompany(offCo.value, 'offMgrSelect');
});

// ── NRIC file management ──────────────────────────────────────────────────
function removeNricExisting(btn) {
    const wrapper = btn.closest('.d-inline-flex');
    const keepInput = wrapper.querySelector('.nric-keep-input');
    if (keepInput) keepInput.disabled = true;
    wrapper.style.opacity = '0.4';
    wrapper.style.pointerEvents = 'none';
    btn.disabled = true;
}

let nricNewFiles = [];
function addNricFile() {
    const input = document.getElementById('nricNewFileInput');
    if (!input.files.length) { alert('Please select a file first.'); return; }
    const keepCount = document.querySelectorAll('.nric-keep-input:not([disabled])').length;
    if (keepCount + nricNewFiles.length >= 5) { alert('Maximum 5 files allowed.'); return; }
    nricNewFiles.push(input.files[0]);
    renderNricNewList();
    input.value = '';
}
function removeNricNew(i) {
    nricNewFiles.splice(i, 1);
    renderNricNewList();
}
function renderNricNewList() {
    const list   = document.getElementById('nricNewList');
    const hidden = document.getElementById('nricNewHidden');
    list.innerHTML = '';
    nricNewFiles.forEach((f, i) => {
        list.innerHTML += `<div class="d-inline-flex align-items-center gap-1 me-1 mb-1">
            <span class="btn btn-sm btn-outline-secondary disabled" style="font-size:12px;pointer-events:none;">
                <i class="bi bi-file-earmark me-1"></i>${escHtml(f.name)}</span>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:12px;" onclick="removeNricNew(${i})"><i class="bi bi-x"></i></button>
        </div>`;
    });
    const old = hidden.querySelector('input[data-nric-new]');
    if (old) old.remove();
    if (nricNewFiles.length) {
        const dt = new DataTransfer();
        nricNewFiles.forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = 'nric_files[]'; inp.multiple = true;
        inp.setAttribute('data-nric-new', '1'); inp.style.display = 'none';
        inp.files = dt.files;
        hidden.appendChild(inp);
    }
}

// ── Education management ──────────────────────────────────────────────────
function offToggleEduEdit(btn) {
    const row = btn.closest('.off-edu-row');
    const fields = row.querySelector('.off-edu-fields');
    const isHidden = fields.classList.contains('d-none');
    fields.classList.toggle('d-none', !isHidden);
    btn.innerHTML = isHidden ? '<i class="bi bi-chevron-up me-1"></i>Close' : '<i class="bi bi-pencil me-1"></i>Edit';
}

function offMarkEduDelete(btn, id) {
    const field = document.getElementById('offEduDeleteIds');
    const ids = field.value ? field.value.split(',') : [];
    ids.push(id);
    field.value = ids.join(',');
    btn.closest('.off-edu-row').remove();
}

function offAddEduRow() {
    const noMsg = document.getElementById('offNoEduMsg');
    if (noMsg) noMsg.remove();
    const container = document.getElementById('offEduContainer');
    const newIdx = 'new_' + Date.now();
    const div = document.createElement('div');
    div.className = 'border rounded p-3 mb-3 off-edu-row';
    div.innerHTML = `
        <input type="hidden" name="edu_id[]" value="">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Qualification</label>
                <input type="text" name="edu_qualification[]" class="form-control">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Institution</label>
                <input type="text" name="edu_institution[]" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Year Graduated</label>
                <input type="number" name="edu_year[]" class="form-control" min="1950" max="${new Date().getFullYear()+5}">
            </div>
            <div class="col-md-9">
                <label class="form-label fw-semibold">Certificate(s) <span class="text-muted fw-normal small">(max 5 files)</span></label>
                <div class="off-edu-cert-new-list" data-idx="${newIdx}"></div>
                <div class="off-edu-cert-new-hidden" data-idx="${newIdx}"></div>
                <div class="d-flex gap-2 mt-1">
                    <input type="file" class="off-edu-cert-file-input form-control form-control-sm"
                           accept=".jpg,.jpeg,.png,.pdf" style="max-width:280px;" data-idx="${newIdx}">
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0"
                            onclick="offAddEduCertFile(this, '${newIdx}')">
                        <i class="bi bi-upload me-1"></i>Add
                    </button>
                </div>
                <div class="form-text">Select a file then click Add. Up to 5 files.</div>
            </div>
        </div>
        <div class="mt-2 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.off-edu-row').remove()">
                <i class="bi bi-trash me-1"></i>Remove
            </button>
        </div>`;
    container.appendChild(div);
}

function offRemoveEduCert(btn) {
    const wrapper = btn.closest('.d-inline-flex');
    const keepInput = wrapper.querySelector('.edu-cert-keep-input');
    if (keepInput) keepInput.disabled = true;
    wrapper.style.opacity = '0.4';
    wrapper.style.pointerEvents = 'none';
    btn.disabled = true;
}

const offEduCertNewFiles = {};
// Find the cert container element for any idx — works for both existing and new rows
function offFindCertContainer(idx) {
    // The .off-edu-cert-new-list[data-idx] element is always present in both row types
    const list = document.querySelector(`.off-edu-cert-new-list[data-idx="${idx}"]`);
    return list ? list.closest('.off-edu-fields, .off-edu-row') : null;
}
function offAddEduCertFile(btn, idx) {
    const row   = offFindCertContainer(idx);
    if (!row) return;
    const input = row.querySelector('.off-edu-cert-file-input');
    if (!input || !input.files.length) { alert('Please select a file first.'); return; }
    const keepCount = row.querySelectorAll('.edu-cert-keep-input:not([disabled])').length;
    if (!offEduCertNewFiles[idx]) offEduCertNewFiles[idx] = [];
    if (keepCount + offEduCertNewFiles[idx].length >= 5) { alert('Maximum 5 files per entry.'); return; }
    offEduCertNewFiles[idx].push(input.files[0]);
    offRenderEduCertNewList(idx, row);
    input.value = '';
}
function offRemoveEduCertNew(idx, i) {
    if (offEduCertNewFiles[idx]) offEduCertNewFiles[idx].splice(i, 1);
    const row = offFindCertContainer(idx);
    if (row) offRenderEduCertNewList(idx, row);
}
function offRenderEduCertNewList(idx, row) {
    const list   = row.querySelector(`.off-edu-cert-new-list[data-idx="${idx}"]`);
    const hidden = row.querySelector(`.off-edu-cert-new-hidden[data-idx="${idx}"]`);
    if (!list || !hidden) return;
    list.innerHTML = '';
    (offEduCertNewFiles[idx] || []).forEach((f, i) => {
        list.innerHTML += `<div class="d-inline-flex align-items-center gap-1 me-1 mb-1">
            <span class="btn btn-sm btn-outline-secondary disabled" style="font-size:11px;pointer-events:none;">
                <i class="bi bi-file-earmark me-1"></i>${escHtml(f.name)}</span>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:11px;" onclick="offRemoveEduCertNew('${idx}',${i})"><i class="bi bi-x"></i></button>
        </div>`;
    });
    const old = hidden.querySelector('input[data-edu-cert-new]');
    if (old) old.remove();
    if ((offEduCertNewFiles[idx] || []).length) {
        const dt = new DataTransfer();
        offEduCertNewFiles[idx].forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = `edu_certificate[${idx}][]`; inp.multiple = true;
        inp.setAttribute('data-edu-cert-new', '1'); inp.style.display = 'none';
        inp.files = dt.files;
        hidden.appendChild(inp);
    }
}

// ── Spouse section — disable/enable based on Marital Status ──────────────
function offToggleSpouseSection(value) {
    const section = document.getElementById('offSectionG');
    if (!section) return;
    const isMarried = value === 'married';
    section.querySelectorAll('input, select, textarea, button').forEach(el => {
        if (el.id === 'offMaritalStatus') return;
        el.disabled = !isMarried;
    });
    const body = section.querySelector('.card-body');
    if (body) body.style.opacity = isMarried ? '' : '0.45';
    if (isMarried) {
        const addr   = document.getElementById('offResAddress');
        const form   = document.getElementById('offAddSpouseForm');
        const spAddr = form ? form.querySelector('[name="spouse_address"]') : null;
        if (addr && spAddr && !spAddr.value.trim()) spAddr.value = addr.value;
    }
}

// ── Spouse management ─────────────────────────────────────────────────────
function offToggleSpouseEdit(btn) {
    const card = btn.closest('.off-spouse-card');
    const fields = card.querySelector('.off-spouse-edit-fields');
    const isHidden = fields.classList.contains('d-none');
    fields.classList.toggle('d-none', !isHidden);
    btn.innerHTML = isHidden ? '<i class="bi bi-chevron-up me-1"></i>Close' : '<i class="bi bi-pencil me-1"></i>Edit';
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.addEventListener('DOMContentLoaded', function () {
    const ms = document.getElementById('offMaritalStatus');
    if (ms) offToggleSpouseSection(ms.value);
});

function offValidateSpouseTel(form) {
    const marital = document.getElementById('offMaritalStatus');
    if (!marital || marital.value !== 'married') return true;
    const telInput = form.querySelector('[name="spouse_tel_no"]');
    if (telInput && !telInput.value.trim()) {
        alert('Tel No. is required when Marital Status is Married.');
        telInput.focus();
        return false;
    }
    return true;
}
</script>
@endpush