@extends('layouts.app')

@section('title', 'Edit Onboarding')
@section('page-title', 'Edit Onboarding Record')

@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
    <a href="{{ route('onboarding.show', $onboarding) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <span class="text-muted small">/ Edit #{{ $onboarding->id }}</span>
</div>

@php
$p = $onboarding->personalDetail;
$w = $onboarding->workDetail;
$a = $onboarding->assetProvisioning;
$canEditAll = Auth::user()->canEditAllOnboardingSections();
@endphp

<form action="{{ route('onboarding.update', $onboarding) }}" method="POST" enctype="multipart/form-data">
    @csrf
    @method('PUT')

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    @if(!$canEditAll)
        <div class="alert alert-info small">
            <i class="bi bi-info-circle me-1"></i>As HR Executive, you can edit Section A and Section B only.
        </div>
    @endif

    <!-- Section A -->
    <div class="card mb-3">
        <div class="card-header bg-white py-3">
            <div class="section-header mb-0">
                <h6><i class="bi bi-person me-2 text-primary"></i>Section A — Personal Details</h6>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control @error('full_name') is-invalid @enderror"
                        value="{{ old('full_name', $p?->full_name) }}" required>
                    @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Preferred Name</label>
                    <input type="text" name="preferred_name" class="form-control"
                        value="{{ old('preferred_name', $p?->preferred_name) }}" placeholder="Nickname / Preferred name">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">NRIC / Passport Number <span class="text-danger">*</span></label>
                    <input type="text" name="official_document_id" class="form-control @error('official_document_id') is-invalid @enderror"
                        value="{{ old('official_document_id', $p?->official_document_id) }}" required>
                    @error('official_document_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-12">
                    <label class="form-label fw-semibold">NRIC / Passport Copy Upload
                        <span class="text-muted fw-normal small">(PDF/image, max 5 files)</span>
                    </label>
                    @php $existingNric = $p?->nric_file_paths ?? ($p?->nric_file_path ? [$p->nric_file_path] : []); @endphp
                    @if(!empty($existingNric))
                    <div class="mb-2">
                        @foreach($existingNric as $idx => $path)
                        <a href="{{ asset('storage/'.$path) }}" target="_blank"
                           class="btn btn-sm btn-outline-primary me-1 mb-1" style="font-size:12px;">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $idx+1 }}
                        </a>
                        @endforeach
                    </div>
                    @endif
                    <input type="file" name="nric_files[]" class="form-control" accept=".jpg,.jpeg,.png,.pdf" multiple>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                    @php $ob_dob = old('date_of_birth', $p?->date_of_birth?->format('Y-m-d')); @endphp
                    <input type="hidden" name="date_of_birth" id="ob_dob_combined" value="{{ $ob_dob }}">
                    @error('date_of_birth')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                    <div class="d-flex gap-1">
                        <select id="ob_dob_day" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                            <option value="">Day</option>
                            @for($d = 1; $d <= 31; $d++)
                                <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                    {{ $ob_dob && (int)explode('-',$ob_dob)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                            @endfor
                        </select>
                        <select id="ob_dob_month" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                            <option value="">Month</option>
                            @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                    {{ $ob_dob && (int)explode('-',$ob_dob)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                            @endforeach
                        </select>
                        <select id="ob_dob_year" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                            <option value="">Year</option>
                            @for($y = date('Y'); $y >= 1940; $y--)
                                <option value="{{ $y }}"
                                    {{ $ob_dob && (int)explode('-',$ob_dob)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <script>
                    (function(){
                        function calcObAge(dob){
                            var el=document.getElementById('ob_age'); if(!el) return;
                            if(!dob){el.value='';return;}
                            var p=dob.split('-'),t=new Date();
                            var a=t.getFullYear()-+p[0];
                            el.value=(a>=0&&a<150)?a:'';
                        }
                        function sync(){
                            var d=document.getElementById('ob_dob_day').value,
                                m=document.getElementById('ob_dob_month').value,
                                y=document.getElementById('ob_dob_year').value;
                            var dob=(y&&m&&d)?y+'-'+m+'-'+d:'';
                            document.getElementById('ob_dob_combined').value=dob;
                            calcObAge(dob);
                        }
                        ['ob_dob_day','ob_dob_month','ob_dob_year'].forEach(function(id){
                            document.getElementById(id).addEventListener('change',sync);
                        });
                        document.addEventListener('DOMContentLoaded',function(){
                            calcObAge(document.getElementById('ob_dob_combined').value);
                        });
                    })();
                    </script>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Age</label>
                    <input type="text" id="ob_age" class="form-control bg-light" readonly placeholder="—">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Sex <span class="text-danger">*</span></label>
                    <select name="sex" class="form-select" required>
                        <option value="male" {{ old('sex', $p?->sex) == 'male' ? 'selected' : '' }}>Male</option>
                        <option value="female" {{ old('sex', $p?->sex) == 'female' ? 'selected' : '' }}>Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Marital Status <span class="text-danger">*</span></label>
                    <select name="marital_status" id="obEditMaritalStatus" class="form-select" required onchange="obEditToggleSpouse(this.value)">
                        @foreach(['single', 'married', 'divorced', 'widowed'] as $ms)
                            <option value="{{ $ms }}" {{ old('marital_status', $p?->marital_status) == $ms ? 'selected' : '' }}>{{ ucfirst($ms) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Religion <span class="text-danger">*</span></label>
                    <input type="text" name="religion" class="form-control" value="{{ old('religion', $p?->religion) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Race <span class="text-danger">*</span></label>
                    <input type="text" name="race" class="form-control" value="{{ old('race', $p?->race) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tel No. (H/phone) <span class="text-danger">*</span></label>
                    <input type="text" name="personal_contact_number" class="form-control" value="{{ old('personal_contact_number', $p?->personal_contact_number) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tel No. (House)</label>
                    <input type="text" name="house_tel_no" class="form-control" value="{{ old('house_tel_no', $p?->house_tel_no) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Personal Email <span class="text-danger">*</span></label>
                    <input type="email" name="personal_email" class="form-control" value="{{ old('personal_email', $p?->personal_email) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Bank Account <span class="text-danger">*</span></label>
                    <input type="text" name="bank_account_number" class="form-control" value="{{ old('bank_account_number', $p?->bank_account_number) }}" required>
                </div>

                {{-- Bank Name --}}
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Bank Name</label>
                    <select name="bank_name" id="editOBBankName" class="form-select"
                            onchange="toggleEditOBOtherBank(this,'editOBBankNameOther')">
                        <option value="">— Select Bank —</option>
                        @foreach(['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank','Other'] as $b)
                        <option value="{{ $b }}" {{ old('bank_name',$p?->bank_name)==$b?'selected':'' }}>{{ $b }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 {{ in_array(old('bank_name',$p?->bank_name??''),['Other','other'])?'':'d-none' }}" id="editOBBankNameOther">
                    <label class="form-label fw-semibold">Other Bank Name</label>
                    <input type="text" name="bank_name_other" class="form-control"
                           value="{{ old('bank_name_other', in_array($p?->bank_name??'',['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank'])?'':($p?->bank_name??'')) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">EPF No.</label>
                    <input type="text" name="epf_no" class="form-control" value="{{ old('epf_no', $p?->epf_no) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Income Tax No.</label>
                    <input type="text" name="income_tax_no" class="form-control" value="{{ old('income_tax_no', $p?->income_tax_no) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">SOCSO No.</label>
                    <input type="text" name="socso_no" class="form-control" value="{{ old('socso_no', $p?->socso_no) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Disabled Person</label>
                    <select name="is_disabled" class="form-select">
                        <option value="0" {{ !old('is_disabled',$p?->is_disabled??false)?'selected':'' }}>No</option>
                        <option value="1" {{ old('is_disabled',$p?->is_disabled??false)?'selected':'' }}>Yes</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Residential Address <span class="text-danger">*</span></label>
                    <textarea name="residential_address" id="obEditResAddress" class="form-control" rows="2" required>{{ old('residential_address', $p?->residential_address) }}</textarea>
                </div>
            </div>
        </div>
    </div>

    @php
        $staging = $p?->invite_staging_json ? json_decode($p->invite_staging_json, true) : [];
        $stagingEdu = $staging['education'] ?? [];
        $stagingSpouses = $staging['spouses'] ?? [];
        $stagingEc = $staging['emergency'] ?? [];
        $stagingChildren = $staging['children'] ?? [];
        $lhdnCats=['a'=>'a) Children under 18 years old','b'=>'b) Children aged 18 years and above (still studying at the certificate and matriculation level)','c'=>'c) Above 18 years (studying Diploma level or higher in Malaysia or elsewhere)','d'=>'d) Disabled Child below 18 years old','e'=>'e) Disabled Child (studying Diploma level or higher in Malaysia or elsewhere)'];
    @endphp

    <!-- Section B -->
    <div class="card mb-3">
        <div class="card-header bg-white py-3">
            <div class="section-header mb-0">
                <h6><i class="bi bi-briefcase me-2 text-primary"></i>Section B — Work Data</h6>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employee Status <span class="text-danger">*</span></label>
                    <select name="employee_status" class="form-select" required>
                        <option value="active" {{ old('employee_status', $w?->employee_status) == 'active' ? 'selected' : '' }}>Active</option>
                        <option value="resigned" {{ old('employee_status', $w?->employee_status) == 'resigned' ? 'selected' : '' }}>Resigned</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Staff Status <span class="text-danger">*</span></label>
                    <select name="staff_status" class="form-select" required>
                        <option value="new"      {{ old('staff_status', $w?->staff_status) == 'new'      ? 'selected' : '' }}>New</option>
                        <option value="existing" {{ old('staff_status', $w?->staff_status) == 'existing' ? 'selected' : '' }}>Existing</option>
                        <option value="rehire"   {{ old('staff_status', $w?->staff_status) == 'rehire'   ? 'selected' : '' }}>Rehire</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employment Type <span class="text-danger">*</span></label>
                    <select name="employment_type" class="form-select" required>
                        @foreach(['permanent', 'intern', 'contract'] as $et)
                            <option value="{{ $et }}" {{ old('employment_type', $w?->employment_type) == $et ? 'selected' : '' }}>{{ ucfirst($et) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Designation <span class="text-danger">*</span></label>
                    <input type="text" name="designation" class="form-control" value="{{ old('designation', $w?->designation) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Department</label>
                    <input type="text" name="department" class="form-control" value="{{ old('department', $w?->department) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                    <select name="company" id="editOBCompanySelect"
                            class="form-control" required
                            onchange="autofillOfficeLocation(this, 'editOBOfficeLocation'); filterManagersByCompany(this.value, 'edit_reporting_manager')">
                        <option value="">Select company...</option>
                        @foreach($companies as $c)
                            <option value="{{ $c->name }}"
                                data-address="{{ $c->address }}"
                                {{ old('company', $w?->company) == $c->name ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Office Location <span class="text-danger">*</span></label>
                    <input type="text" name="office_location" id="editOBOfficeLocation"
                           class="form-control" value="{{ old('office_location', $w?->office_location) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Reporting Manager <span class="text-danger">*</span></label>
                    <select name="reporting_manager" id="edit_reporting_manager"
                            class="form-select @error('reporting_manager') is-invalid @enderror"
                            onchange="fetchManagerEmailEdit(this.value)" required>
                        @php
                            $roleLabels = [
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
                        <option value="">Select manager...</option>
                        @foreach($managers as $mgr)
                            <option value="{{ $mgr->full_name }}"
                                data-email="{{ $mgr->company_email }}"
                                data-company="{{ $mgr->company }}"
                                {{ old('reporting_manager', $onboarding->workDetail?->reporting_manager)==$mgr->full_name?'selected':'' }}>
                                {{ $mgr->full_name }}
                                @if($mgr->designation) — {{ $mgr->designation }} @endif
                                ({{ $roleLabels[$mgr->work_role ?? ''] ?? ucfirst(str_replace('_',' ',$mgr->work_role ?? '')) }})
                            </option>
                        @endforeach
                    </select>
                    @error('reporting_manager')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Reporting Manager Email</label>
                    <input type="email" name="reporting_manager_email" id="edit_reporting_manager_email"
                           class="form-control"
                           value="{{ old('reporting_manager_email', $w?->reporting_manager_email) }}"
                           placeholder="Auto-filled from manager selection" readonly
                           style="background:#f8fafc;">
                    <small class="text-muted">Auto-filled based on selected manager</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                    @php $ob_sd = old('start_date', $w?->start_date?->format('Y-m-d')); @endphp
                    <input type="hidden" name="start_date" id="ob_sd_combined" value="{{ $ob_sd }}">
                    @error('start_date')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                    <div class="d-flex gap-1">
                        <select id="ob_sd_day" class="form-select @error('start_date') is-invalid @enderror" style="min-width:0">
                            <option value="">Day</option>
                            @for($d = 1; $d <= 31; $d++)
                                <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                    {{ $ob_sd && (int)explode('-',$ob_sd)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                            @endfor
                        </select>
                        <select id="ob_sd_month" class="form-select @error('start_date') is-invalid @enderror" style="min-width:0">
                            <option value="">Month</option>
                            @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                    {{ $ob_sd && (int)explode('-',$ob_sd)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                            @endforeach
                        </select>
                        <select id="ob_sd_year" class="form-select @error('start_date') is-invalid @enderror" style="min-width:0">
                            <option value="">Year</option>
                            @for($y = date('Y') + 2; $y >= 1990; $y--)
                                <option value="{{ $y }}"
                                    {{ $ob_sd && (int)explode('-',$ob_sd)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <script>
                    (function(){
                        function sync(){
                            var d=document.getElementById('ob_sd_day').value,
                                m=document.getElementById('ob_sd_month').value,
                                y=document.getElementById('ob_sd_year').value;
                            document.getElementById('ob_sd_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                        }
                        ['ob_sd_day','ob_sd_month','ob_sd_year'].forEach(function(id){
                            document.getElementById(id).addEventListener('change',sync);
                        });
                    })();
                    </script>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Exit Date</label>
                    @php $ob_ed = old('exit_date', $w?->exit_date?->format('Y-m-d')); @endphp
                    <input type="hidden" name="exit_date" id="ob_ed_combined" value="{{ $ob_ed }}">
                    <div class="d-flex gap-1">
                        <select id="ob_ed_day" class="form-select" style="min-width:0">
                            <option value="">Day</option>
                            @for($d = 1; $d <= 31; $d++)
                                <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                    {{ $ob_ed && (int)explode('-',$ob_ed)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                            @endfor
                        </select>
                        <select id="ob_ed_month" class="form-select" style="min-width:0">
                            <option value="">Month</option>
                            @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                    {{ $ob_ed && (int)explode('-',$ob_ed)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                            @endforeach
                        </select>
                        <select id="ob_ed_year" class="form-select" style="min-width:0">
                            <option value="">Year</option>
                            @for($y = date('Y') + 2; $y >= 1990; $y--)
                                <option value="{{ $y }}"
                                    {{ $ob_ed && (int)explode('-',$ob_ed)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                            @endfor
                        </select>
                    </div>
                    <script>
                    (function(){
                        function sync(){
                            var d=document.getElementById('ob_ed_day').value,
                                m=document.getElementById('ob_ed_month').value,
                                y=document.getElementById('ob_ed_year').value;
                            document.getElementById('ob_ed_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                        }
                        ['ob_ed_day','ob_ed_month','ob_ed_year'].forEach(function(id){
                            document.getElementById(id).addEventListener('change',sync);
                        });
                    })();
                    </script>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Last Salary Date</label>
                    @if(Auth::user()->isHrManager())
                        @php $ob_lsd = old('last_salary_date', $w?->last_salary_date?->format('Y-m-d')); @endphp
                        <input type="hidden" name="last_salary_date" id="ob_lsd_combined" value="{{ $ob_lsd }}">
                        <div class="d-flex gap-1">
                            <select id="ob_lsd_day" class="form-select" style="min-width:0">
                                <option value="">Day</option>
                                @for($d = 1; $d <= 31; $d++)
                                    <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}"
                                        {{ $ob_lsd && (int)explode('-',$ob_lsd)[2] === $d ? 'selected' : '' }}>{{ $d }}</option>
                                @endfor
                            </select>
                            <select id="ob_lsd_month" class="form-select" style="min-width:0">
                                <option value="">Month</option>
                                @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                    <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}"
                                        {{ $ob_lsd && (int)explode('-',$ob_lsd)[1] === $mi+1 ? 'selected' : '' }}>{{ $mn }}</option>
                                @endforeach
                            </select>
                            <select id="ob_lsd_year" class="form-select" style="min-width:0">
                                <option value="">Year</option>
                                @for($y = date('Y') + 2; $y >= 1990; $y--)
                                    <option value="{{ $y }}"
                                        {{ $ob_lsd && (int)explode('-',$ob_lsd)[0] === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <script>
                        (function(){
                            function sync(){
                                var d=document.getElementById('ob_lsd_day').value,
                                    m=document.getElementById('ob_lsd_month').value,
                                    y=document.getElementById('ob_lsd_year').value;
                                document.getElementById('ob_lsd_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                            }
                            ['ob_lsd_day','ob_lsd_month','ob_lsd_year'].forEach(function(id){
                                document.getElementById(id).addEventListener('change',sync);
                            });
                        })();
                        </script>
                    @else
                        @php $lsdDisplay = $w?->last_salary_date?->format('d M Y'); @endphp
                        <input type="text" class="form-control bg-light" readonly value="{{ $lsdDisplay ?: '—' }}">
                    @endif
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Company Email</label>
                    <input type="email" name="company_email" id="edit_company_email" class="form-control"
                           value="{{ old('company_email', $w?->company_email) }}"
                           oninput="syncEditGoogleId(this.value)">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Google ID</label>
                    <input type="text" name="google_id" id="edit_google_id" class="form-control"
                           value="{{ old('google_id', $w?->google_id) }}"
                           readonly style="background:#f8fafc;">
                    <small class="text-muted">Auto-mirrors Company Email</small>
                </div>
                {{-- HR & IT Email multi-select --}}
                <div class="col-md-6">
                    <label class="form-label fw-semibold">HR Contact(s)
                        <small class="text-muted fw-normal">(hold Ctrl/Cmd for multiple)</small>
                    </label>
                    <select name="hr_emails[]" id="editHrEmailsSelect" class="form-select" multiple size="4">
                            @foreach($hrUsers as $e)
                                @php $roleLabel = ucfirst(str_replace('_',' ',$e->work_role ?? '')); @endphp
                                <option value="{{ $e->company_email }}"
                                    {{ in_array($e->company_email, old('hr_emails', is_array($onboarding->hr_emails) ? $onboarding->hr_emails : json_decode($onboarding->hr_emails ?? '[]', true) ?? [])) ? 'selected' : '' }}>
                                    {{ $e->full_name }} ({{ $roleLabel }}) — {{ $e->company_email }}
                                </option>
                            @endforeach
                        </select>
                    <small class="text-muted">Leave blank to send to all HR staff by default</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">IT Contact(s)
                        <small class="text-muted fw-normal">(hold Ctrl/Cmd for multiple)</small>
                    </label>
                    <select name="it_emails[]" id="editItEmailsSelect" class="form-select" multiple size="4">
                            @foreach($itUsers as $e)
                                @php $roleLabel = ucfirst(str_replace('_',' ',$e->work_role ?? '')); @endphp
                                <option value="{{ $e->company_email }}"
                                    {{ in_array($e->company_email, old('it_emails', is_array($onboarding->it_emails) ? $onboarding->it_emails : json_decode($onboarding->it_emails ?? '[]', true) ?? [])) ? 'selected' : '' }}>
                                    {{ $e->full_name }} ({{ $roleLabel }}) — {{ $e->company_email }}
                                </option>
                            @endforeach
                        </select>
                    <small class="text-muted">Leave blank to send to all IT staff by default</small>
                </div>
            </div>
        </div>
    </div>

    @if($canEditAll)
    <!-- Section C -->
    <div class="card mb-3">
        <div class="card-header bg-white py-3">
            <div class="section-header mb-0">
                <h6><i class="bi bi-laptop me-2 text-primary"></i>Section C — Asset & Access Provisioning</h6>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                @foreach([
                    ['laptop_provision', 'Laptop', 'bi-laptop'],
                    ['monitor_set', 'Monitor', 'bi-display'],
                    ['converter', 'Converter', 'bi-plug'],
                    ['company_phone', 'Phone', 'bi-phone'],
                    ['sim_card', 'SIM', 'bi-sim'],
                    ['access_card_request', 'Access Card', 'bi-credit-card'],
                ] as [$field, $label, $icon])
                @php $checked = old($field, $a?->$field) ? true : false; @endphp
                <div class="col-md-2 col-4">
                    <label class="d-flex flex-column align-items-center p-3 border rounded text-center {{ $checked ? 'border-primary bg-primary bg-opacity-10' : '' }}"
                        style="cursor:pointer;" id="label_{{ $field }}">
                        <input type="checkbox" name="{{ $field }}" value="1" class="d-none" id="{{ $field }}"
                            {{ $checked ? 'checked' : '' }} onchange="toggleAssetLabel(this)">
                        <i class="bi {{ $icon }}" style="font-size:28px; color:{{ $checked ? '#2563eb' : '#94a3b8' }};"></i>
                        <small class="mt-1 fw-semibold" style="font-size:11px;">{{ $label }}</small>
                    </label>
                </div>
                @endforeach
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Office Keys</label>
                    <input type="text" name="office_keys" class="form-control" value="{{ old('office_keys', $a?->office_keys) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Others</label>
                    <input type="text" name="others" class="form-control" value="{{ old('others', $a?->others) }}">
                </div>
            </div>
        </div>
    </div>

    <!-- Section D -->
    <div class="card mb-3">
        <div class="card-header bg-white py-3">
            <div class="section-header mb-0 d-flex align-items-center justify-content-between">
                <h6><i class="bi bi-shield-lock me-2 text-primary"></i>Section D — Access Role</h6>
                @if(!Auth::user()->isSuperadmin())
                <span class="badge bg-light text-secondary border" style="font-size:11px;">
                    <i class="bi bi-lock me-1"></i>Managed by Superadmin
                </span>
                @endif
            </div>
        </div>
        <div class="card-body">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Role</label>
                @if(Auth::user()->isSuperadmin())
                <select name="role" class="form-select @error('role') is-invalid @enderror">
                    <option value="">Select...</option>
                    @foreach(['manager' => 'Manager', 'senior_executive' => 'Senior Executive', 'executive_associate' => 'Executive / Associate', 'director_hod' => 'Director / HOD', 'hr_manager' => 'HR Manager', 'hr_executive' => 'HR Executive', 'hr_intern' => 'HR Intern', 'it_manager' => 'IT Manager', 'it_executive' => 'IT Executive', 'it_intern' => 'IT Intern', 'superadmin' => 'Superadmin', 'system_admin' => 'System Admin', 'others' => 'Others'] as $val => $label)
                        <option value="{{ $val }}" {{ old('role', $onboarding->workDetail?->role) == $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @else
                <input type="text" class="form-control" style="background:#f8fafc;"
                       value="{{ ucfirst(str_replace('_',' ', $onboarding->workDetail?->role ?? 'Others')) }}" readonly>
                <div class="form-text text-muted"><i class="bi bi-lock me-1"></i>Only Superadmin can change roles.</div>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- Section F — Education --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3"><h6><i class="bi bi-mortarboard me-2 text-primary"></i>Section F — Education &amp; Work History</h6></div>
        <div class="card-body">
            <div style="background:#f8fafc;border:1px solid #e9ecef;border-radius:8px;padding:1rem;">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small">Qualification</label>
                        <input type="text" id="editOBEduQual" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold small">Institution</label>
                        <input type="text" id="editOBEduInst" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small">Year</label>
                        <input type="number" id="editOBEduYear" class="form-control form-control-sm" min="1950" max="{{ date('Y')+5 }}">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold small">Certificate <span class="text-muted fw-normal">(PDF/image, multiple allowed)</span></label>
                        <div class="d-flex gap-2">
                            <input type="file" id="editOBEduCertInput" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf">
                            <button type="button" class="btn btn-outline-primary btn-sm flex-shrink-0" onclick="editOBAddCertFile()">
                                <i class="bi bi-upload me-1"></i>Upload
                            </button>
                        </div>
                        <div id="editOBEduCertFileList" class="mt-1"></div>
                    </div>
                </div>
                <div class="mt-2 text-end">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editOBAddEdu()">
                        <i class="bi bi-plus-circle me-1"></i>Add to List
                    </button>
                </div>
            </div>
            <div id="editOBEduList" class="mt-2">
                @foreach($stagingEdu as $i => $edu)
                @php
                    $certPaths = $edu['certificate_paths'] ?? (isset($edu['certificate_path']) && $edu['certificate_path'] ? [$edu['certificate_path']] : []);
                @endphp
                <div class="border rounded p-2 mb-2 bg-white editob-edu-row">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="fw-semibold small">{{ $edu['qualification'] }}</span>
                            <span class="text-muted small ms-2">{{ $edu['institution'] ?? '' }}{{ isset($edu['year_graduated'])?' · '.$edu['year_graduated']:'' }}</span>
                        </div>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                    onclick="toggleOBEduEdit(this)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                    onclick="this.closest('.editob-edu-row').remove();editOBSyncEdu()">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                    {{-- Existing certificates --}}
                    @if(!empty($certPaths))
                    <div class="mt-1">
                        @foreach($certPaths as $ci => $certPath)
                        <a href="{{ asset('storage/'.$certPath) }}" target="_blank"
                           class="btn btn-sm btn-outline-primary me-1 mb-1" style="padding:2px 8px;font-size:11px;">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i>Cert {{ $ci + 1 }}
                        </a>
                        @endforeach
                    </div>
                    @endif
                    {{-- Inline edit panel --}}
                    <div class="ob-edu-edit-fields mt-2 d-none" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:.75rem;">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small mb-1">Qualification</label>
                                <input type="text" class="ob-edu-qual-inp form-control form-control-sm"
                                       placeholder="e.g. Bachelor of Science" value="{{ $edu['qualification'] }}">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small mb-1">Institution</label>
                                <input type="text" class="ob-edu-inst-inp form-control form-control-sm"
                                       placeholder="e.g. University Malaya" value="{{ $edu['institution'] ?? '' }}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label fw-semibold small mb-1">Year Graduated</label>
                                <input type="number" class="ob-edu-year-inp form-control form-control-sm"
                                       placeholder="{{ date('Y') }}" value="{{ $edu['year_graduated'] ?? '' }}" min="1950" max="{{ date('Y')+5 }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold small mb-1">Upload New Certificate(s) <span class="text-muted fw-normal">(PDF/image, optional)</span></label>
                                <input type="file" name="edu_cert_new[{{ $i }}][]" class="form-control form-control-sm" multiple accept=".jpg,.jpeg,.png,.pdf">
                                <div class="form-text">Existing certificates are preserved. Upload here to add more.</div>
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-sm btn-primary py-0 px-3"
                                        onclick="saveOBEduEdit(this)">
                                    <i class="bi bi-check me-1"></i>Update
                                </button>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" class="editob-edu-qual" name="edu_qualification[]" value="{{ $edu['qualification'] }}">
                    <input type="hidden" class="editob-edu-inst" name="edu_institution[]" value="{{ $edu['institution'] ?? '' }}">
                    <input type="hidden" class="editob-edu-year" name="edu_year[]" value="{{ $edu['year_graduated'] ?? '' }}">
                    @foreach($certPaths as $certPath)
                    <input type="hidden" class="editob-edu-cert-path" name="edu_cert_existing[{{ $i }}][]" value="{{ $certPath }}">
                    @endforeach
                </div>
                @endforeach
            </div>
            <div id="editOBEduHidden"></div>
            <div class="mt-3 row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold small">No. of Years of Working Experience <span class="text-muted fw-normal">(not incl. part-time)</span></label>
                    <select name="edu_experience_total" class="form-select form-select-sm">
                        <option value="">— Select —</option>
                        @for($y=0;$y<=40;$y++)
                        <option value="{{ $y }}" {{ old('edu_experience_total',$staging['edu_experience_total']??'')==$y?'selected':'' }}>{{ $y }} {{ $y==1?'year':'years' }}</option>
                        @endfor
                        <option value="40+" {{ old('edu_experience_total',$staging['edu_experience_total']??'')=='40+'?'selected':'' }}>40+ years</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    {{-- Section G — Spouse --}}
    <div class="card mb-3" id="obEditSpouseSection">
        <div class="card-header bg-white py-3"><h6><i class="bi bi-people me-2 text-primary"></i>Section G — Spouse Information <span class="text-danger ob-edit-spouse-required d-none">*</span></h6></div>
        <div class="card-body">
            @foreach($stagingSpouses as $i => $sp)
            <div class="border rounded p-2 mb-2 bg-light ob-spouse-card" data-idx="{{ $i }}">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="fw-semibold small">{{ $sp['name'] }}</span>
                        <span class="text-muted small ms-2">{{ $sp['tel_no'] ?? '' }}{{ $sp['occupation'] ? ' · '.$sp['occupation'] : '' }}</span>
                    </div>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                onclick="toggleOBSpouseEdit(this)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                onclick="removeOBSpouse(this, {{ $i }})">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>
                {{-- Inline edit --}}
                <div class="ob-spouse-edit-fields mt-2 d-none" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:.75rem;">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="ob-sp-name form-control form-control-sm" placeholder="Full Name" value="{{ $sp['name'] }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small mb-1">NRIC No.</label>
                            <input type="text" class="ob-sp-nric form-control form-control-sm" placeholder="e.g. 900101-10-1234" value="{{ $sp['nric_no'] ?? '' }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">Tel No. <span class="text-danger">*</span></label>
                            <input type="text" class="ob-sp-tel form-control form-control-sm" placeholder="e.g. 012-3456789" value="{{ $sp['tel_no'] ?? '' }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">Occupation</label>
                            <input type="text" class="ob-sp-occ form-control form-control-sm" placeholder="e.g. Teacher" value="{{ $sp['occupation'] ?? '' }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small mb-1">Income Tax No.</label>
                            <input type="text" class="ob-sp-tax form-control form-control-sm" placeholder="Income Tax No." value="{{ $sp['income_tax_no'] ?? '' }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small mb-1">Address</label>
                            <textarea class="ob-sp-addr form-control form-control-sm" rows="2" placeholder="Address">{{ $sp['address'] ?? '' }}</textarea>
                        </div>
                        <div class="col-12 text-end">
                            <button type="button" class="btn btn-sm btn-primary py-0 px-3"
                                    onclick="saveOBSpouseEdit(this, {{ $i }})">
                                <i class="bi bi-check me-1"></i>Update
                            </button>
                        </div>
                    </div>
                </div>
                <input type="hidden" class="ob-sp-h-name"     name="spouses[{{ $i }}][name]"          value="{{ $sp['name'] }}">
                <input type="hidden" class="ob-sp-h-nric"     name="spouses[{{ $i }}][nric_no]"       value="{{ $sp['nric_no'] ?? '' }}">
                <input type="hidden" class="ob-sp-h-tel"      name="spouses[{{ $i }}][tel_no]"        value="{{ $sp['tel_no'] ?? '' }}">
                <input type="hidden" class="ob-sp-h-occ"      name="spouses[{{ $i }}][occupation]"    value="{{ $sp['occupation'] ?? '' }}">
                <input type="hidden" class="ob-sp-h-tax"      name="spouses[{{ $i }}][income_tax_no]" value="{{ $sp['income_tax_no'] ?? '' }}">
                <input type="hidden" class="ob-sp-h-addr"     name="spouses[{{ $i }}][address]"       value="{{ $sp['address'] ?? '' }}">
                <input type="hidden" class="ob-sp-h-working"  name="spouses[{{ $i }}][is_working]"    value="{{ $sp['is_working'] ?? 0 }}">
                <input type="hidden" class="ob-sp-h-disabled" name="spouses[{{ $i }}][is_disabled]"   value="{{ $sp['is_disabled'] ?? 0 }}">
            </div>
            @endforeach
            <div style="background:#f8fafc;border:1px solid #e9ecef;border-radius:8px;padding:1rem;" id="editOBSpousePanel">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                        <input type="text" id="editOBSpName" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">NRIC No.</label>
                        <input type="text" id="editOBSpNric" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Tel No. <span class="text-danger">*</span></label>
                        <input type="text" id="editOBSpTel" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Occupation</label>
                        <input type="text" id="editOBSpOccupation" class="form-control form-control-sm"></div>
                    <div class="col-12"><label class="form-label fw-semibold small">Address</label>
                        <textarea id="editOBSpAddress" class="form-control form-control-sm" rows="2"></textarea></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Working?</label>
                        <select id="editOBSpWorking" class="form-select form-select-sm"><option value="0">No</option><option value="1">Yes</option></select></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Disabled?</label>
                        <select id="editOBSpDisabled" class="form-select form-select-sm"><option value="0">No</option><option value="1">Yes</option></select></div>
                </div>
                <div class="mt-2 text-end">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editOBAddSpouse()">
                        <i class="bi bi-plus-circle me-1"></i>Add to List
                    </button>
                </div>
            </div>
            <div id="editOBSpouseList" class="mt-2"></div>
            <div id="editOBSpouseHidden"></div>
        </div>
    </div>

    {{-- Section H — Emergency Contacts --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3"><h6><i class="bi bi-telephone-fill me-2 text-primary"></i>Section H — Emergency Contacts</h6></div>
        <div class="card-body">
            <div id="editOBEcList">
                @foreach([1,2] as $n)
                @php $ec = $stagingEc[$n] ?? null; @endphp
                @if($ec)
                <div class="border rounded p-2 mb-1 bg-white editob-ec-row" data-order="{{ $n }}">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <input type="hidden" name="emergency[{{ $n }}][name]" value="{{ $ec['name'] }}">
                            <input type="hidden" name="emergency[{{ $n }}][tel_no]" value="{{ $ec['tel_no'] }}">
                            <input type="hidden" name="emergency[{{ $n }}][relationship]" value="{{ $ec['relationship'] }}">
                            <span class="fw-semibold small ec-label">Contact {{ $n }}: {{ $ec['name'] }}</span>
                            <span class="text-muted small ms-2 ec-sublabel">{{ $ec['tel_no'] }} · {{ $ec['relationship'] }}</span>
                        </div>
                        <div class="d-flex gap-1 ms-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                    onclick="toggleOBEcEdit(this)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                    onclick="this.closest('.editob-ec-row').remove();obRenumberEc()">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>
                    {{-- Inline edit panel --}}
                    <div class="ob-ec-edit-fields mt-2 d-none" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:.75rem;">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small mb-1">Full Name</label>
                                <input type="text" class="ob-ec-name-inp form-control form-control-sm" placeholder="Contact name" value="{{ $ec['name'] }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small mb-1">Tel No.</label>
                                <input type="text" class="ob-ec-tel-inp form-control form-control-sm" placeholder="e.g. 012-3456789" value="{{ $ec['tel_no'] }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small mb-1">Relationship</label>
                                <select class="ob-ec-rel-inp form-select form-select-sm">
                                    @foreach(['Spouse','Parent','Sibling','Child','Friend','Colleague','Other'] as $rel)
                                    <option value="{{ $rel }}" {{ ($ec['relationship'] ?? '') === $rel ? 'selected' : '' }}>{{ $rel }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 text-end">
                                <button type="button" class="btn btn-sm btn-primary py-0 px-3"
                                        onclick="saveOBEcEdit(this)">
                                    <i class="bi bi-check me-1"></i>Update
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
                @endforeach
            </div>
            <div style="background:#f8fafc;border:1px solid #e9ecef;border-radius:8px;padding:1rem;margin-top:.5rem;">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label fw-semibold small">Name</label>
                        <input type="text" id="editOBEcName" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Tel No.</label>
                        <input type="text" id="editOBEcTel" class="form-control form-control-sm"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold small">Relationship</label>
                        <select id="editOBEcRel" class="form-select form-select-sm">
                            <option value="">— Select —</option>
                            @foreach(['Spouse','Parent','Sibling','Child','Friend','Colleague','Other'] as $rel)
                            <option value="{{ $rel }}">{{ $rel }}</option>
                            @endforeach
                        </select></div>
                </div>
                <div class="mt-2 text-end">
                    <button type="button" class="btn btn-primary btn-sm" onclick="editOBAddEc()">
                        <i class="bi bi-plus-circle me-1"></i>Add / Replace Contact
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Section I — Child Registration --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3"><h6><i class="bi bi-heart me-2 text-primary"></i>Section I — Child Registration (LHDN)</h6></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-sm align-middle" style="font-size:12px;">
                    <thead style="background:#f8fafc;">
                        <tr>
                            <th rowspan="2" style="width:55%;vertical-align:middle;">Category</th>
                            <th colspan="2" class="text-center">Number of children</th>
                        </tr>
                        <tr>
                            <th class="text-center" style="width:120px;">100% (self)</th>
                            <th class="text-center" style="width:120px;">50% (shared)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lhdnCats as $key => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            <td class="text-center">
                                <input type="number" name="cat_{{ $key }}_100" class="form-control form-control-sm text-center"
                                       value="{{ old("cat_{$key}_100",$stagingChildren["cat_{$key}_100"]??0) }}" min="0" max="99" style="width:60px;margin:auto;">
                            </td>
                            <td class="text-center">
                                <input type="number" name="cat_{{ $key }}_50" class="form-control form-control-sm text-center"
                                       value="{{ old("cat_{$key}_50",$stagingChildren["cat_{$key}_50"]??0) }}" min="0" max="99" style="width:60px;margin:auto;">
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Declaration & Consent (read-only) --}}
    <div class="card mb-3">
        <div class="card-header bg-white py-3">
            <h6><i class="bi bi-file-earmark-check me-2 text-primary"></i>Declaration &amp; Consent</h6>
        </div>
        <div class="card-body">
            <div class="p-3 rounded mb-3" style="background:#f8fafc;border:1px solid #e2e8f0;font-size:13.5px;">
                <p class="fw-semibold mb-2">Personal Data Protection Act (PDPA) 2010 — Consent</p>
                <p class="mb-2">I hereby declare that all information provided above is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
                <p class="mb-2">I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
                <p class="mb-0">I also agree to promptly notify the HRA Department of any changes to the information provided above, including updates to my contact details, banking information, or personal particulars.</p>
            </div>
            @if($p && $p->consent_given_at)
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
                    <div class="text-muted small">The new hire has not yet acknowledged the Declaration &amp; Consent. A request email was sent to their work email upon onboarding creation.</div>
                </div>
            </div>
            @endif
        </div>
    </div>

    <!-- Remarks -->
    <div class="card mb-3">
        <div class="card-body">
            <label class="form-label fw-semibold">Remarks (optional)</label>
            <textarea name="remarks" class="form-control" rows="2" placeholder="Reason for update or any notes..."></textarea>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end">
        <a href="{{ route('onboarding.show', $onboarding) }}" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="bi bi-check-circle me-2"></i>Save Changes
        </button>
    </div>
</form>

{{-- ── Edit & Consent Acknowledgement Log ── --}}
@php $editLogs = $onboarding->editLogs ?? collect(); @endphp
@if($editLogs->isNotEmpty())
<div class="card mb-3">
    <div class="card-header bg-white py-3" style="border-left:4px solid #6366f1;">
        <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-2" style="color:#6366f1;"></i>Edit &amp; Consent Acknowledgement Log</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th class="ps-3" style="width:130px;">Date &amp; Time</th>
                    <th>Edited By</th>
                    <th>Sections Changed</th>
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
                        @foreach($log->sections_changed ?? [] as $section)
                        <span class="badge bg-primary bg-opacity-10 text-primary me-1 mb-1">{{ $section }}</span>
                        @endforeach
                        @if($log->change_notes)
                        <div class="text-muted small">{{ $log->change_notes }}</div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

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

document.addEventListener('DOMContentLoaded', function() {
    var editCo = document.getElementById('editOBCompanySelect');
    if (editCo && editCo.value) filterManagersByCompany(editCo.value, 'edit_reporting_manager');
    var ms = document.getElementById('obEditMaritalStatus');
    if (ms) obEditToggleSpouse(ms.value);
});

// ── Section G spouse functions ────────────────────────────────────────────
function obEditToggleSpouse(val) {
    const section = document.getElementById('obEditSpouseSection');
    const star    = document.querySelector('.ob-edit-spouse-required');
    if (!section) return;
    const isMarried = val === 'married';
    section.querySelectorAll('input, select, textarea, button').forEach(el => {
        el.disabled = !isMarried;
    });
    section.style.opacity = isMarried ? '1' : '0.4';
    if (star) star.classList.toggle('d-none', !isMarried);
    if (isMarried) {
        const addr   = document.getElementById('obEditResAddress');
        const spAddr = document.getElementById('editOBSpAddress');
        if (addr && spAddr && !spAddr.value.trim()) spAddr.value = addr.value;
    }
}

function toggleOBSpouseEdit(btn) {
    const card   = btn.closest('.ob-spouse-card');
    const fields = card.querySelector('.ob-spouse-edit-fields');
    const hidden = fields.classList.contains('d-none');
    fields.classList.toggle('d-none', !hidden);
    btn.innerHTML = hidden ? '<i class="bi bi-chevron-up"></i>' : '<i class="bi bi-pencil"></i>';
}

function removeOBSpouse(btn) {
    if (!confirm('Remove this spouse entry?')) return;
    btn.closest('.ob-spouse-card').remove();
}

function saveOBSpouseEdit(btn) {
    const card = btn.closest('.ob-spouse-card');
    const name = card.querySelector('.ob-sp-name').value;
    const tel  = card.querySelector('.ob-sp-tel').value;
    const occ  = card.querySelector('.ob-sp-occ').value;
    card.querySelector('.ob-sp-h-name').value = name;
    card.querySelector('.ob-sp-h-nric').value = card.querySelector('.ob-sp-nric').value;
    card.querySelector('.ob-sp-h-tel').value  = tel;
    card.querySelector('.ob-sp-h-occ').value  = occ;
    card.querySelector('.ob-sp-h-tax').value  = card.querySelector('.ob-sp-tax').value;
    card.querySelector('.ob-sp-h-addr').value = card.querySelector('.ob-sp-addr').value;
    card.querySelector('.fw-semibold.small').textContent = name;
    card.querySelector('.text-muted.small.ms-2').textContent = tel + (occ ? ' · ' + occ : '');
    card.querySelector('.ob-spouse-edit-fields').classList.add('d-none');
    btn.closest('.ob-spouse-card').querySelector('button[onclick*="toggleOBSpouseEdit"]').innerHTML = '<i class="bi bi-pencil"></i>';
}

let _editOBNewCount = 0;
function editOBAddSpouse() {
    const name = document.getElementById('editOBSpName').value.trim();
    if (!name) { alert('Please enter the spouse name.'); return; }
    const marital = document.getElementById('obEditMaritalStatus');
    const tel = document.getElementById('editOBSpTel').value.trim();
    if (marital && marital.value === 'married' && !tel) {
        alert('Tel No. is required when Marital Status is Married.');
        document.getElementById('editOBSpTel').focus();
        return;
    }
    const idx = document.querySelectorAll('.ob-sp-h-name').length + _editOBNewCount;
    _editOBNewCount++;
    const nric = document.getElementById('editOBSpNric').value.trim();
    const occ  = document.getElementById('editOBSpOccupation').value.trim();
    const addr = document.getElementById('editOBSpAddress').value.trim();
    const working  = document.getElementById('editOBSpWorking').value;
    const disabled = document.getElementById('editOBSpDisabled').value;
    const list = document.getElementById('editOBSpouseList');
    list.insertAdjacentHTML('beforeend',
        '<div class="border rounded p-2 mb-1 bg-light d-flex justify-content-between align-items-center">' +
        '<span class="fw-semibold small">' + escHtml(name) + '</span>' +
        '<span class="text-muted small ms-2">' + escHtml(tel) + (occ ? ' · ' + escHtml(occ) : '') + '</span>' +
        '</div>');
    const hidden = document.getElementById('editOBSpouseHidden');
    const fields = {name, nric_no: nric, tel_no: tel, occupation: occ, income_tax_no: '', address: addr, is_working: working, is_disabled: disabled};
    Object.entries(fields).forEach(([k, v]) => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'spouses[' + idx + '][' + k + ']'; inp.value = v;
        hidden.appendChild(inp);
    });
    ['editOBSpName','editOBSpNric','editOBSpTel','editOBSpOccupation','editOBSpAddress'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('editOBSpWorking').value  = '0';
    document.getElementById('editOBSpDisabled').value = '0';
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleAssetLabel(checkbox) {
    var label = document.getElementById('label_' + checkbox.id);
    var icon  = label ? label.querySelector('i') : null;
    if (checkbox.checked) {
        if (label) { label.classList.add('border-primary', 'bg-primary', 'bg-opacity-10'); }
        if (icon)  { icon.style.color = '#2563eb'; }
    } else {
        if (label) { label.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10'); }
        if (icon)  { icon.style.color = '#94a3b8'; }
    }
}
</script>
@endpush

@endsection