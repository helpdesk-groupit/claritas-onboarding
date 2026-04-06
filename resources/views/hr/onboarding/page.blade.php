@extends('layouts.app')
@section('title', 'Onboarding')
@section('page-title', 'Onboarding')

@section('content')

{{-- ─── PAGE HEADER with Add button ─── --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="text-muted mb-0 small">Manage all new hire onboarding records</p>
    </div>
    @if(Auth::user()->canAddOnboarding())
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sendInviteModal">
            <i class="bi bi-envelope me-2"></i>Send Link
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOnboardingModal">
            <i class="bi bi-person-plus me-2"></i>Add New Onboarding
        </button>
    </div>
    @endif
</div>

{{-- ─── ONBOARDING LISTING ─── --}}
<div class="card">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i>Onboarding Records</h6>
    </div>
    {{-- Filters --}}
    <div class="card-body border-bottom pb-3">
        <form action="{{ route('onboarding.index') }}" method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                    placeholder="Search name, email, position..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <select name="company" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    @foreach($companies as $c)
                        <option value="{{ $c }}" {{ request('company')==$c?'selected':'' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" name="position" class="form-control form-control-sm"
                    placeholder="Position..." value="{{ request('position') }}">
            </div>
            <div class="col-md-2">
                <input type="date" name="start_date_from" class="form-control form-control-sm"
                    value="{{ request('start_date_from') }}" title="Start date from">
            </div>
            <div class="col-md-2">
                <input type="date" name="start_date_to" class="form-control form-control-sm"
                    value="{{ request('start_date_to') }}" title="Start date to">
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['search','company','position','start_date_from','start_date_to']))
                    <a href="{{ route('onboarding.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
              <!--  <a href="{{ route('onboarding.export', request()->query()) }}"
                   class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>CSV</a> -->
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <small class="text-muted px-3 pt-2 d-block">{{ $onboardings->total() }} record(s)</small>
        @if($onboardings->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox" style="font-size:40px;"></i>
                <p class="mt-2">No records found</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background:#f8fafc;font-size:13px;">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Position</th>
                        <th>Company</th>
                        <th>Department</th>
                        <th>Start Date</th>
                        <th>Status</th>
                        <th>AARF</th>
                        <th>Welcome Email</th>
                        <th>Calendar Invite</th>
                        <th>Asset Prep</th>
                        <th>Work Email/GID</th>
                        @if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
                        <th>Assigned PIC</th>
                        @endif
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($onboardings as $ob)
                    <tr>
                        <td class="ps-3"><strong>{{ $ob->personalDetail?->full_name ?? '—' }}</strong></td>
                        <td>{{ $ob->workDetail?->designation ?? '—' }}</td>
                        <td>{{ $ob->workDetail?->company ?? '—' }}</td>
                        <td>{{ $ob->workDetail?->department ?? '—' }}</td>
                        <td>{{ $ob->workDetail?->start_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            <span class="badge bg-{{ $ob->status==='active'?'success':($ob->status==='pending'?'warning text-dark':'secondary') }}">
                                {{ ucfirst($ob->status) }}
                            </span>
                            @if($ob->invite_submitted && !$ob->calendar_invite_sent)
                            <br><span class="badge bg-info text-dark mt-1" style="font-size:10px;">
                                <i class="bi bi-hourglass-split me-1"></i>Awaiting HR
                            </span>
                            @endif
                        </td>
                        <td>
                            @if($ob->aarf)
                                <span class="badge bg-{{ $ob->aarf->acknowledged?'success':'warning text-dark' }}">
                                    {{ $ob->aarf->acknowledged ? 'Acknowledged' : 'Pending' }}
                                </span>
                            @else
                                <span class="badge bg-light text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if($ob->welcome_email_sent)
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
                            @elseif($ob->invite_submitted && !$ob->calendar_invite_sent)
                                <span class="badge bg-secondary">Pending</span>
                            @elseif(($ob->workDetail?->start_date ?? now())->isFuture() || ($ob->workDetail?->start_date ?? now())->isToday())
                                <span class="badge bg-secondary">Pending</span>
                            @else
                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
                            @endif
                        </td>
                        <td>
                            @if($ob->calendar_invite_sent)
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
                            @elseif($ob->invite_submitted && !$ob->calendar_invite_sent)
                                <span class="badge bg-secondary">Pending</span>
                            @else
                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
                            @endif
                        </td>
                        <td>
                            @php $assetColor = match($ob->asset_preparation_status) { 'done'=>'success','in_progress'=>'warning text-dark',default=>'secondary' }; @endphp
                            <span class="badge bg-{{ $assetColor }}">{{ ucfirst(str_replace('_',' ',$ob->asset_preparation_status ?? 'pending')) }}</span>
                        </td>
                        <td>
                            @php $emailColor = match($ob->work_email_status) { 'done'=>'success','in_progress'=>'warning text-dark',default=>'secondary' }; @endphp
                            <span class="badge bg-{{ $emailColor }}">{{ ucfirst(str_replace('_',' ',$ob->work_email_status ?? 'pending')) }}</span>
                        </td>
                        @if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
                        @php $pastStartPic = $ob->workDetail?->start_date && \Carbon\Carbon::today()->gt($ob->workDetail->start_date); @endphp
                        <td>
                            @if($pastStartPic)
                                <span class="text-muted small"><i class="bi bi-lock me-1"></i>Passed</span>
                                @if($ob->assignedPic)
                                    <br><small class="text-success"><i class="bi bi-person-check me-1"></i>{{ $ob->assignedPic->employee?->preferred_name ?? $ob->assignedPic->name }}</small>
                                @endif
                            @else
                                <div class="d-flex align-items-center gap-1">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Assign PIC"
                                            data-bs-toggle="modal"
                                            data-bs-target="#picModal{{ $ob->id }}">
                                        <i class="bi bi-person-gear"></i>
                                    </button>
                                    @if($ob->assignedPic)
                                        <small class="text-success"><i class="bi bi-person-check me-1"></i>{{ $ob->assignedPic->employee?->preferred_name ?? $ob->assignedPic->name }}</small>
                                    @endif
                                </div>
                            @endif
                        </td>
                        @endif
                        <td>
                            <div class="d-flex gap-1">
                                <a href="{{ route('onboarding.show', $ob) }}"
                                   class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if(Auth::user()->canEditOnboarding())
                                @php $pastStart = $ob->workDetail?->start_date && \Carbon\Carbon::today()->gt($ob->workDetail->start_date); @endphp
                                @if($pastStart)
                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Start date has passed">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                @else
                                    <a href="{{ route('onboarding.edit', $ob) }}"
                                       class="btn btn-sm btn-outline-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $onboardings->links() }}</div>
        @endif
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════
     SEND INVITE LINK MODAL
═══════════════════════════════════════════════════════ --}}
@if(Auth::user()->canAddOnboarding())
<div class="modal fade" id="sendInviteModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0052CC,#2684FE);">
                <h6 class="modal-title text-white fw-bold mb-0">
                    <i class="bi bi-envelope me-2"></i>Send Onboarding Link
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('onboarding.invite.send') }}" method="POST">
                @csrf
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Enter the new hire's email address. They will receive a link to fill in their personal details.
                        The link expires in <strong>24 hours</strong>.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                        <select name="invite_company"
                                class="form-select @error('invite_company') is-invalid @enderror" required>
                            <option value="">— Select Company —</option>
                            @foreach($companies as $c)
                            <option value="{{ $c->name }}" {{ old('invite_company') == $c->name ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                            @endforeach
                        </select>
                        @error('invite_company')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-semibold">New Hire's Email <span class="text-danger">*</span></label>
                        <input type="email" name="invite_email"
                               class="form-control @error('invite_email') is-invalid @enderror"
                               placeholder="newhire@email.com" required>
                        @error('invite_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-send me-1"></i>Send Link
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- ═══════════════════════════════════════════════════════
     ADD NEW ONBOARDING MODAL
═══════════════════════════════════════════════════════ --}}
@if(Auth::user()->canAddOnboarding())
<div class="modal fade" id="addOnboardingModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0052CC,#2684FE);">
                <h5 class="modal-title text-white fw-bold">
                    <i class="bi bi-person-plus me-2"></i>New Onboarding Record
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form action="{{ route('onboarding.store') }}" method="POST" id="onboardingForm">
            @csrf
            <div class="modal-body">

                @if($errors->any())
                    <div class="alert alert-danger">
                        <strong><i class="bi bi-exclamation-circle me-1"></i>Please fix the following:</strong>
                        <ul class="mb-0 mt-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                {{-- ── Section A — Personal Details ── --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-person me-2 text-primary"></i>Section A — Personal Details</h6>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control @error('full_name') is-invalid @enderror"
                               value="{{ old('full_name') }}" required>
                        @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Preferred Name</label>
                        <input type="text" name="preferred_name" class="form-control"
                               value="{{ old('preferred_name') }}" placeholder="Nickname / Preferred name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">NRIC / Passport Number <span class="text-danger">*</span></label>
                        <input type="text" name="official_document_id"
                               class="form-control @error('official_document_id') is-invalid @enderror"
                               value="{{ old('official_document_id') }}" placeholder="NRIC / Passport" required>
                        @error('official_document_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                        <input type="hidden" name="date_of_birth" id="hr_dob_combined">
                        @error('date_of_birth')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                        <div class="d-flex gap-1">
                            <select id="hr_dob_day" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                                <option value="">Day</option>
                                @for($d = 1; $d <= 31; $d++)
                                    <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}">{{ $d }}</option>
                                @endfor
                            </select>
                            <select id="hr_dob_month" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                                <option value="">Month</option>
                                @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                    <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}">{{ $mn }}</option>
                                @endforeach
                            </select>
                            <select id="hr_dob_year" class="form-select @error('date_of_birth') is-invalid @enderror" style="min-width:0">
                                <option value="">Year</option>
                                @for($y = date('Y'); $y >= 1940; $y--)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <script>
                        (function(){
                            function calcHrAge(dob){
                                var el=document.getElementById('hr_age'); if(!el) return;
                                if(!dob){el.value='';return;}
                                var p=dob.split('-'),t=new Date();
                                var a=t.getFullYear()-+p[0];
                                el.value=(a>=0&&a<150)?a:'';
                            }
                            var old = '{{ old('date_of_birth') }}';
                            if(old){ var p=old.split('-');
                                document.getElementById('hr_dob_year').value=p[0];
                                document.getElementById('hr_dob_month').value=p[1];
                                document.getElementById('hr_dob_day').value=p[2];
                                document.getElementById('hr_dob_combined').value=old;
                                document.addEventListener('DOMContentLoaded',function(){ calcHrAge(old); });
                            }
                            function sync(){
                                var d=document.getElementById('hr_dob_day').value,
                                    m=document.getElementById('hr_dob_month').value,
                                    y=document.getElementById('hr_dob_year').value;
                                var dob=(y&&m&&d)?y+'-'+m+'-'+d:'';
                                document.getElementById('hr_dob_combined').value=dob;
                                calcHrAge(dob);
                            }
                            ['hr_dob_day','hr_dob_month','hr_dob_year'].forEach(function(id){
                                document.getElementById(id).addEventListener('change',sync);
                            });
                        })();
                        </script>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Age</label>
                        <input type="text" id="hr_age" class="form-control bg-light" readonly placeholder="—">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Sex <span class="text-danger">*</span></label>
                        <select name="sex" class="form-select @error('sex') is-invalid @enderror" required>
                            <option value="">Select...</option>
                            <option value="male"   {{ old('sex')=='male'   ?'selected':'' }}>Male</option>
                            <option value="female" {{ old('sex')=='female' ?'selected':'' }}>Female</option>
                        </select>
                        @error('sex')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Marital Status <span class="text-danger">*</span></label>
                        <select name="marital_status" id="obMaritalStatus" class="form-select @error('marital_status') is-invalid @enderror" required onchange="obToggleSpouseSection(this.value)">
                            <option value="">Select...</option>
                            @foreach(['single'=>'Single','married'=>'Married','divorced'=>'Divorced','widowed'=>'Widowed'] as $v=>$l)
                                <option value="{{ $v }}" {{ old('marital_status')==$v?'selected':'' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                        @error('marital_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Religion <span class="text-danger">*</span></label>
                        <input type="text" name="religion"
                               class="form-control @error('religion') is-invalid @enderror"
                               value="{{ old('religion') }}" required>
                        @error('religion')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Race <span class="text-danger">*</span></label>
                        <input type="text" name="race"
                               class="form-control @error('race') is-invalid @enderror"
                               value="{{ old('race') }}" required>
                        @error('race')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Residential Address <span class="text-danger">*</span></label>
                        <textarea name="residential_address" id="obResAddress"
                                  class="form-control @error('residential_address') is-invalid @enderror"
                                  rows="2" required>{{ old('residential_address') }}</textarea>
                        @error('residential_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                        <input type="text" name="house_tel_no" class="form-control"
                               value="{{ old('house_tel_no') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Personal Email <span class="text-danger">*</span></label>
                        <input type="email" name="personal_email"
                               class="form-control @error('personal_email') is-invalid @enderror"
                               value="{{ old('personal_email') }}" required>
                        @error('personal_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Bank Account Number <span class="text-danger">*</span></label>
                        <input type="text" name="bank_account_number"
                               class="form-control @error('bank_account_number') is-invalid @enderror"
                               value="{{ old('bank_account_number') }}" required>
                        @error('bank_account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Bank Name --}}
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Bank Name</label>
                        <select name="bank_name" id="addOBBankName" class="form-select"
                                onchange="toggleOBOtherBank(this,'addOBBankNameOther')">
                            <option value="">— Select Bank —</option>
                            @foreach(['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank','Other'] as $b)
                            <option value="{{ $b }}" {{ old('bank_name')==$b?'selected':'' }}>{{ $b }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 d-none" id="addOBBankNameOther">
                        <label class="form-label fw-semibold">Other Bank Name</label>
                        <input type="text" name="bank_name_other" class="form-control"
                               value="{{ old('bank_name_other') }}" placeholder="Enter bank name">
                    </div>

                    {{-- EPF / Income Tax / SOCSO --}}
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

                    {{-- Disabled Person --}}
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Disabled Person</label>
                        <select name="is_disabled" class="form-select">
                            <option value="0" {{ old('is_disabled','0')=='0'?'selected':'' }}>No</option>
                            <option value="1" {{ old('is_disabled')=='1'?'selected':'' }}>Yes</option>
                        </select>
                    </div>
                </div>


                {{-- ── Section B — Work Data ── --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-briefcase me-2 text-primary"></i>Section B — Work Data</h6>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Employee Status <span class="text-danger">*</span></label>
                        <select name="employee_status" class="form-select @error('employee_status') is-invalid @enderror" required>
                            <option value="">Select...</option>
                            <option value="active"   {{ old('employee_status')=='active'   ?'selected':'' }}>Active</option>
                            <option value="resigned" {{ old('employee_status')=='resigned' ?'selected':'' }}>Resigned</option>
                        </select>
                        @error('employee_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Staff Status <span class="text-danger">*</span></label>
                        <select name="staff_status" class="form-select @error('staff_status') is-invalid @enderror" required>
                            <option value="">Select...</option>
                            <option value="new"      {{ old('staff_status')=='new'      ?'selected':'' }}>New</option>
                            <option value="existing" {{ old('staff_status')=='existing' ?'selected':'' }}>Existing</option>
                            <option value="rehire"   {{ old('staff_status')=='rehire'   ?'selected':'' }}>Rehire</option>
                        </select>
                        @error('staff_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Employment Type <span class="text-danger">*</span></label>
                        <select name="employment_type" class="form-select @error('employment_type') is-invalid @enderror" required>
                            <option value="">Select...</option>
                            <option value="permanent" {{ old('employment_type')=='permanent'?'selected':'' }}>Permanent</option>
                            <option value="intern"    {{ old('employment_type')=='intern'   ?'selected':'' }}>Intern</option>
                            <option value="contract"  {{ old('employment_type')=='contract' ?'selected':'' }}>Contract</option>
                        </select>
                        @error('employment_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Designation <span class="text-danger">*</span></label>
                        <input type="text" name="designation"
                               class="form-control @error('designation') is-invalid @enderror"
                               value="{{ old('designation') }}" required>
                        @error('designation')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Company <span class="text-danger">*</span></label>
                        <select name="company" id="addOBCompanySelect"
                                class="form-select @error('company') is-invalid @enderror"
                                onchange="autofillOfficeLocation(this, 'addOBOfficeLocation'); filterManagersByCompany(this.value, 'reporting_manager')" required>
                            <option value="">Select company...</option>
                            @foreach($companies as $c)
                            <option value="{{ $c->name }}"
                                    data-address="{{ $c->address }}"
                                    {{ old('company') == $c->name ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                            @endforeach
                        </select>
                        @error('company')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Office Location <span class="text-danger">*</span></label>
                        <input type="text" name="office_location" id="addOBOfficeLocation"
                               class="form-control @error('office_location') is-invalid @enderror"
                               value="{{ old('office_location') }}" required>
                        @error('office_location')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Department</label>
                        <input type="text" name="department" class="form-control" value="{{ old('department') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Reporting Manager <span class="text-danger">*</span></label>
                        <select name="reporting_manager" id="reporting_manager"
                                class="form-select @error('reporting_manager') is-invalid @enderror"
                                onchange="fetchManagerEmail(this.value)" required>
                            <option value="">Select manager...</option>
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
                            @foreach($managers as $mgr)
                                <option value="{{ $mgr->full_name }}"
                                    data-email="{{ $mgr->company_email }}"
                                    data-company="{{ $mgr->company }}"
                                    data-employee-id="{{ $mgr->id }}"
                                    {{ old('reporting_manager')==$mgr->full_name?'selected':'' }}>
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
                        <input type="email" name="reporting_manager_email" id="reporting_manager_email"
                               class="form-control" value="{{ old('reporting_manager_email') }}"
                               placeholder="Auto-filled from manager selection" readonly
                               style="background:#f8fafc;">
                        <small class="text-muted">Auto-filled based on selected manager</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label>
                        <input type="hidden" name="start_date" id="hr_sd_combined">
                        @error('start_date')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                        <div class="d-flex gap-1">
                            <select id="hr_sd_day" class="form-select @error('start_date') is-invalid @enderror" style="min-width:0">
                                <option value="">Day</option>
                                @for($d = 1; $d <= 31; $d++)
                                    <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}">{{ $d }}</option>
                                @endfor
                            </select>
                            <select id="hr_sd_month" class="form-select @error('start_date') is-invalid @enderror" style="min-width:0">
                                <option value="">Month</option>
                                @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                    <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}">{{ $mn }}</option>
                                @endforeach
                            </select>
                            <select id="hr_sd_year" class="form-select @error('start_date') is-invalid @enderror" style="min-width:0">
                                <option value="">Year</option>
                                @for($y = date('Y') + 2; $y >= 1990; $y--)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <script>
                        (function(){
                            var old='{{ old('start_date') }}';
                            if(old){var p=old.split('-');
                                document.getElementById('hr_sd_year').value=p[0];
                                document.getElementById('hr_sd_month').value=p[1];
                                document.getElementById('hr_sd_day').value=p[2];
                                document.getElementById('hr_sd_combined').value=old;
                            }
                            function sync(){
                                var d=document.getElementById('hr_sd_day').value,
                                    m=document.getElementById('hr_sd_month').value,
                                    y=document.getElementById('hr_sd_year').value;
                                document.getElementById('hr_sd_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                            }
                            ['hr_sd_day','hr_sd_month','hr_sd_year'].forEach(function(id){
                                document.getElementById(id).addEventListener('change',sync);
                            });
                        })();
                        </script>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Exit Date</label>
                        <input type="hidden" name="exit_date" id="hr_ed_combined">
                        <div class="d-flex gap-1">
                            <select id="hr_ed_day" class="form-select" style="min-width:0">
                                <option value="">Day</option>
                                @for($d = 1; $d <= 31; $d++)
                                    <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}">{{ $d }}</option>
                                @endfor
                            </select>
                            <select id="hr_ed_month" class="form-select" style="min-width:0">
                                <option value="">Month</option>
                                @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                    <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}">{{ $mn }}</option>
                                @endforeach
                            </select>
                            <select id="hr_ed_year" class="form-select" style="min-width:0">
                                <option value="">Year</option>
                                @for($y = date('Y') + 2; $y >= 1990; $y--)
                                    <option value="{{ $y }}">{{ $y }}</option>
                                @endfor
                            </select>
                        </div>
                        <script>
                        (function(){
                            var old='{{ old('exit_date') }}';
                            if(old){var p=old.split('-');
                                document.getElementById('hr_ed_year').value=p[0];
                                document.getElementById('hr_ed_month').value=p[1];
                                document.getElementById('hr_ed_day').value=p[2];
                                document.getElementById('hr_ed_combined').value=old;
                            }
                            function sync(){
                                var d=document.getElementById('hr_ed_day').value,
                                    m=document.getElementById('hr_ed_month').value,
                                    y=document.getElementById('hr_ed_year').value;
                                document.getElementById('hr_ed_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                            }
                            ['hr_ed_day','hr_ed_month','hr_ed_year'].forEach(function(id){
                                document.getElementById(id).addEventListener('change',sync);
                            });
                        })();
                        </script>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Last Salary Date</label>
                        @if(Auth::user()->isHrManager())
                            <input type="hidden" name="last_salary_date" id="hr_lsd_combined">
                            <div class="d-flex gap-1">
                                <select id="hr_lsd_day" class="form-select" style="min-width:0">
                                    <option value="">Day</option>
                                    @for($d = 1; $d <= 31; $d++)
                                        <option value="{{ str_pad($d,2,'0',STR_PAD_LEFT) }}">{{ $d }}</option>
                                    @endfor
                                </select>
                                <select id="hr_lsd_month" class="form-select" style="min-width:0">
                                    <option value="">Month</option>
                                    @foreach(['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'] as $mi => $mn)
                                        <option value="{{ str_pad($mi+1,2,'0',STR_PAD_LEFT) }}">{{ $mn }}</option>
                                    @endforeach
                                </select>
                                <select id="hr_lsd_year" class="form-select" style="min-width:0">
                                    <option value="">Year</option>
                                    @for($y = date('Y') + 2; $y >= 1990; $y--)
                                        <option value="{{ $y }}">{{ $y }}</option>
                                    @endfor
                                </select>
                            </div>
                            <script>
                            (function(){
                                var old='{{ old('last_salary_date') }}';
                                if(old){var p=old.split('-');
                                    document.getElementById('hr_lsd_year').value=p[0];
                                    document.getElementById('hr_lsd_month').value=p[1];
                                    document.getElementById('hr_lsd_day').value=p[2];
                                    document.getElementById('hr_lsd_combined').value=old;
                                }
                                function sync(){
                                    var d=document.getElementById('hr_lsd_day').value,
                                        m=document.getElementById('hr_lsd_month').value,
                                        y=document.getElementById('hr_lsd_year').value;
                                    document.getElementById('hr_lsd_combined').value=(y&&m&&d)?y+'-'+m+'-'+d:'';
                                }
                                ['hr_lsd_day','hr_lsd_month','hr_lsd_year'].forEach(function(id){
                                    document.getElementById(id).addEventListener('change',sync);
                                });
                            })();
                            </script>
                        @else
                            <input type="text" class="form-control bg-light" readonly value="—">
                        @endif
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Company Email</label>
                        <input type="email" name="company_email" id="company_email"
                               class="form-control" value="{{ old('company_email') }}"
                               oninput="syncGoogleId(this.value)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Google ID</label>
                        <input type="text" name="google_id" id="google_id"
                               class="form-control" value="{{ old('google_id') }}"
                               readonly style="background:#f8fafc;">
                        <small class="text-muted">Auto-mirrors Company Email</small>
                    </div>

                    {{-- HR & IT Email multi-select --}}
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">HR Contact(s)
                            <small class="text-muted fw-normal">(for calendar invite — hold Ctrl/Cmd to select multiple)</small>
                        </label>
                        <select name="hr_emails[]" id="hrEmailsSelect" class="form-select" multiple size="4">
                            @foreach($hrUsers as $e)
                                @php $roleLabel = ucfirst(str_replace('_',' ',$e->work_role ?? '')); @endphp
                                <option value="{{ $e->company_email }}"
                                    {{ in_array($e->company_email, old('hr_emails', [])) ? 'selected' : '' }}>
                                    {{ $e->full_name }} ({{ $roleLabel }}) — {{ $e->company_email }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Leave blank to send to all HR staff by default</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">IT Contact(s)
                            <small class="text-muted fw-normal">(for calendar invite — hold Ctrl/Cmd to select multiple)</small>
                        </label>
                        <select name="it_emails[]" id="itEmailsSelect" class="form-select" multiple size="4">
                            @foreach($itUsers as $e)
                                @php $roleLabel = ucfirst(str_replace('_',' ',$e->work_role ?? '')); @endphp
                                <option value="{{ $e->company_email }}"
                                    {{ in_array($e->company_email, old('it_emails', [])) ? 'selected' : '' }}>
                                    {{ $e->full_name }} ({{ $roleLabel }}) — {{ $e->company_email }}
                                </option>
                            @endforeach
                        </select>
                        <small class="text-muted">Leave blank to send to all IT staff by default</small>
                    </div>
                </div>

                {{-- ── Section C — Asset Provisioning ── --}}
                @if(Auth::user()->canEditAllOnboardingSections())
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-laptop me-2 text-primary"></i>Section C — Asset & Access Provisioning</h6>
                </div>
                <div class="row g-3 mb-4">
                    @php $assetItems = [
                        ['name'=>'laptop_provision',  'label'=>'Laptop',        'icon'=>'bi-laptop'],
                        ['name'=>'monitor_set',        'label'=>'Monitor Set',   'icon'=>'bi-display'],
                        ['name'=>'converter',          'label'=>'Converter',     'icon'=>'bi-usb-plug'],
                        ['name'=>'company_phone',      'label'=>'Company Phone', 'icon'=>'bi-phone'],
                        ['name'=>'sim_card',           'label'=>'SIM Card',      'icon'=>'bi-sim'],
                        ['name'=>'access_card_request','label'=>'Access Card',   'icon'=>'bi-credit-card'],
                    ]; @endphp
                    @foreach($assetItems as $a)
                    <div class="col-md-2">
                        <div class="card text-center p-3 h-100 {{ old($a['name']) ? 'border-primary' : '' }}"
                             style="cursor:pointer;" onclick="toggleAsset('{{ $a['name'] }}')">
                            <i class="bi {{ $a['icon'] }}" style="font-size:28px;color:#2684FE;"></i>
                            <div class="small fw-semibold mt-2">{{ $a['label'] }}</div>
                            <input type="checkbox" name="{{ $a['name'] }}" id="{{ $a['name'] }}" value="1"
                                   class="d-none" {{ old($a['name']) ? 'checked' : '' }}>
                            <div id="{{ $a['name'] }}_badge"
                                 class="badge mt-1 {{ old($a['name']) ? 'bg-success' : 'bg-secondary' }}">
                                {{ old($a['name']) ? 'Yes' : 'No' }}
                            </div>
                        </div>
                    </div>
                    @endforeach
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Office Keys</label>
                        <input type="text" name="office_keys" class="form-control" value="{{ old('office_keys') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Others</label>
                        <input type="text" name="others" class="form-control" value="{{ old('others') }}">
                    </div>
                </div>

                {{-- ── Section D — Role ── --}}
                @if(Auth::user()->isSuperadmin())
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-shield-lock me-2 text-primary"></i>Section D — Access Role</h6>
                </div>
                <div class="row g-3 mb-2">
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select @error('role') is-invalid @enderror" required>
                            <option value="">Select role...</option>
                            @foreach([
                                'manager'=>'Manager',
                                'senior_executive'=>'Senior Executive',
                                'executive_associate'=>'Executive / Associate',
                                'director_hod'=>'Director / Head of Department',
                                'hr_manager'=>'HR Manager',
                                'hr_executive'=>'HR Executive',
                                'hr_intern'=>'HR Intern',
                                'it_manager'=>'IT Manager',
                                'it_executive'=>'IT Executive',
                                'it_intern'=>'IT Intern',
                                'superadmin'=>'Superadmin',
                                'system_admin'=>'System Admin',
                                'others'=>'Others',
                            ] as $v=>$l)
                                <option value="{{ $v }}" {{ old('role')==$v?'selected':'' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                        @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                @else
                {{-- Non-superadmin: role defaults to 'others', not shown --}}
                <input type="hidden" name="role" value="others">
                @endif
                @endif {{-- canEditAllOnboardingSections --}}

                {{-- ── Section F — Education & Work History ── --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-mortarboard me-2 text-primary"></i>Section F — Education &amp; Work History</h6>
                </div>
                <div class="mb-4">
                    <div style="background:#f8fafc;border:1px solid #e9ecef;border-radius:8px;padding:1rem;" id="obEduPanel">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Qualification</label>
                                <input type="text" id="obEduQual" class="form-control form-control-sm"
                                       placeholder="e.g. Bachelor of Business Administration">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Institution</label>
                                <input type="text" id="obEduInst" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Year Graduated</label>
                                <input type="number" id="obEduYear" class="form-control form-control-sm"
                                       min="1950" max="{{ date('Y')+5 }}">
                            </div>
                            <div class="col-md-9">
                                <label class="form-label fw-semibold small">Certificate <span class="text-muted fw-normal">(PDF/image)</span></label>
                                <input type="file" id="obEduCert" class="form-control form-control-sm"
                                       accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                        </div>
                        <div class="mt-2 text-end">
                            <button type="button" class="btn btn-primary btn-sm" onclick="obAddEduEntry()">
                                <i class="bi bi-plus-circle me-1"></i>Add to List
                            </button>
                        </div>
                    </div>
                    <div id="obEduList" class="mt-2"></div>
                    <div id="obEduHidden"></div>
                    <div class="mt-3 row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold small">
                                No. of Years of Working Experience
                                <span class="text-muted fw-normal">(not incl. part-time)</span>
                            </label>
                            <select name="edu_experience_total" class="form-select form-select-sm">
                                <option value="">— Select —</option>
                                @for($y=0;$y<=40;$y++)
                                <option value="{{ $y }}" {{ old('edu_experience_total')==$y?'selected':'' }}>
                                    {{ $y }} {{ $y==1?'year':'years' }}
                                </option>
                                @endfor
                                <option value="40+" {{ old('edu_experience_total')=='40+'?'selected':'' }}>40+ years</option>
                            </select>
                        </div>
                    </div>
                </div>

                {{-- ── Section G — Spouse Information ── --}}
                <div class="section-header mb-3" id="obSpouseSectionHeader">
                    <h6 class="mb-0"><i class="bi bi-people me-2 text-primary"></i>Section G — Spouse Information <span class="text-danger ob-spouse-required d-none">*</span></h6>
                </div>
                <div class="mb-4" id="obSpouseSection">
                    <div style="background:#f8fafc;border:1px solid #e9ecef;border-radius:8px;padding:1rem;" id="obSpousePanel">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                                <input type="text" id="obSpName" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">NRIC No.</label>
                                <input type="text" id="obSpNric" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Tel No. <span class="text-danger">*</span></label>
                                <input type="text" id="obSpTel" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Occupation</label>
                                <input type="text" id="obSpOccupation" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Income Tax No.</label>
                                <input type="text" id="obSpIncomeTax" class="form-control form-control-sm">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold small">Address</label>
                                <textarea id="obSpAddress" class="form-control form-control-sm" rows="2"></textarea>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Working?</label>
                                <select id="obSpWorking" class="form-select form-select-sm">
                                    <option value="0">No</option><option value="1">Yes</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold small">Disabled?</label>
                                <select id="obSpDisabled" class="form-select form-select-sm">
                                    <option value="0">No</option><option value="1">Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="mt-2 text-end">
                            <button type="button" class="btn btn-primary btn-sm" onclick="obAddSpouseEntry()">
                                <i class="bi bi-plus-circle me-1"></i>Add to List
                            </button>
                        </div>
                    </div>
                    <div id="obSpouseList" class="mt-2"></div>
                    <div id="obSpouseHidden"></div>
                </div>

                {{-- ── Section H — Emergency Contacts ── --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-telephone-fill me-2 text-primary"></i>Section H — Emergency Contacts</h6>
                    <small class="text-muted">Two contacts required.</small>
                </div>
                <div class="mb-4">
                    <div style="background:#f8fafc;border:1px solid #e9ecef;border-radius:8px;padding:1rem;" id="obEcPanel">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Name</label>
                                <input type="text" id="obEcName" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Tel No.</label>
                                <input type="text" id="obEcTel" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold small">Relationship</label>
                                <select id="obEcRel" class="form-select form-select-sm">
                                    <option value="">— Select —</option>
                                    @foreach(['Spouse','Parent','Sibling','Child','Friend','Colleague','Other'] as $rel)
                                    <option value="{{ $rel }}">{{ $rel }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="mt-2 text-end">
                            <button type="button" class="btn btn-primary btn-sm" onclick="obAddEcEntry()">
                                <i class="bi bi-plus-circle me-1"></i>Add to List
                            </button>
                        </div>
                    </div>
                    <div id="obEcList" class="mt-2"></div>
                    <div id="obEcHidden"></div>
                    <p class="text-muted small mt-1 mb-0">
                        <i class="bi bi-info-circle me-1"></i><span id="obEcCountText">0 of 2 contacts added.</span>
                    </p>
                </div>

                {{-- ── Section I — Child Registration (LHDN) ── --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-heart me-2 text-primary"></i>Section I — Child Registration (LHDN)</h6>
                    <small class="text-muted">Put 0 if not applicable.</small>
                </div>
                <div class="mb-4">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle" style="font-size:12px;">
                            <thead style="background:#f8fafc;">
                                <tr>
                                    <th rowspan="2" style="width:55%;vertical-align:middle;">Category</th>
                                    <th colspan="2" class="text-center">Number of children</th>
                                </tr>
                                <tr>
                                    <th class="text-center" style="width:120px;">100%<br><small class="fw-normal">(self)</small></th>
                                    <th class="text-center" style="width:120px;">50%<br><small class="fw-normal">(shared)</small></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $lhdnCats=['a'=>'a) Children under 18 years old','b'=>'b) Children aged 18 years and above (still studying at the certificate and matriculation level)','c'=>'c) Above 18 years (studying Diploma level or higher in Malaysia or elsewhere)','d'=>'d) Disabled Child below 18 years old','e'=>'e) Disabled Child (studying Diploma level or higher in Malaysia or elsewhere)']; @endphp
                                @foreach($lhdnCats as $key => $label)
                                <tr>
                                    <td>{{ $label }}</td>
                                    <td class="text-center">
                                        <input type="number" name="cat_{{ $key }}_100" class="form-control form-control-sm text-center"
                                               value="{{ old("cat_{$key}_100",0) }}" min="0" max="99" style="width:60px;margin:auto;">
                                    </td>
                                    <td class="text-center">
                                        <input type="number" name="cat_{{ $key }}_50" class="form-control form-control-sm text-center"
                                               value="{{ old("cat_{$key}_50",0) }}" min="0" max="99" style="width:60px;margin:auto;">
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>



                {{-- ── Declaration & Consent Notice ── --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-file-earmark-check me-2 text-primary"></i>Declaration &amp; Consent</h6>
                </div>
                <div class="mb-2">
                    <div class="d-flex align-items-start gap-3 p-3 rounded" style="background:#eff6ff;border:1px solid #bfdbfe;">
                        <i class="bi bi-info-circle-fill text-primary mt-1" style="font-size:18px;flex-shrink:0;"></i>
                        <div style="font-size:13.5px;">
                            <div class="fw-semibold text-primary mb-1">Consent will be obtained from the new hire directly.</div>
                            <div class="text-muted">
                                Upon submission, a <strong>Declaration &amp; Consent</strong> request email will be sent to the new hire's work email address. They will be prompted to log in to the Employee Portal and give their consent before their start date.
                            </div>
                        </div>
                    </div>
                </div>

            </div>{{-- modal-body --}}
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Cancel
                </button>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-circle me-2"></i>Submit Onboarding Record
                </button>
            </div>
            </form>
        </div>
    </div>
</div>
@endif


{{-- ── Assign PIC Modals (Onboarding) ──────────────────────────────────── --}}
@if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
@foreach($onboardings as $ob)
@php $pastStartPic = $ob->workDetail?->start_date && \Carbon\Carbon::today()->gt($ob->workDetail->start_date); @endphp
@if(!$pastStartPic)
<div class="modal fade" id="picModal{{ $ob->id }}" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:linear-gradient(135deg,#0052CC,#2684FE);">
                <h6 class="modal-title text-white fw-bold mb-0">
                    <i class="bi bi-person-gear me-2"></i>Assign PIC
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('it.assign.pic', $ob) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <p class="small text-muted mb-2">{{ $ob->personalDetail?->full_name ?? '—' }}</p>
                    <label class="form-label fw-semibold small">Select PIC</label>
                    <div class="d-grid gap-1">
                        <label class="d-flex align-items-center gap-2 p-2 border rounded cursor-pointer">
                            <input type="radio" name="assigned_pic_user_id" value=""
                                {{ !$ob->assigned_pic_user_id ? 'checked' : '' }}>
                            <span class="small text-muted">— Remove PIC —</span>
                        </label>
                        @foreach($itStaff as $staff)
                        <label class="d-flex align-items-center gap-2 p-2 border rounded" style="cursor:pointer;">
                            <input type="radio" name="assigned_pic_user_id" value="{{ $staff->id }}"
                                {{ $ob->assigned_pic_user_id == $staff->id ? 'checked' : '' }}>
                            <div>
                                <div class="fw-semibold small">{{ $staff->employee?->preferred_name ?? $staff->name }}</div>
                                <div class="text-muted" style="font-size:11px;">{{ ucfirst(str_replace('_',' ',$staff->role)) }}</div>
                            </div>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check2 me-1"></i>Assign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endforeach
@endif

@endsection

@push('scripts')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// ── Filter Reporting Manager by Company ──────────────────────────────────
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

// ── Company → Office Location autofill ───────────────────────────────────
function autofillOfficeLocation(selectEl, targetId) {
    const selected = selectEl.options[selectEl.selectedIndex];
    const target   = document.getElementById(targetId);
    if (!target || !selected || !selected.value) return;
    target.value = selected.dataset.address || '-';
}


// ── Google ID mirrors Company Email ──────────────────────────────────────
function syncGoogleId(val) {
    const g = document.getElementById('google_id');
    if (g) g.value = val;
}

// ── Reporting Manager Email auto-fill ────────────────────────────────────
function fetchManagerEmail(selectedName) {
    const sel = document.getElementById('reporting_manager');
    const emailInput = document.getElementById('reporting_manager_email');
    if (!sel || !emailInput) return;
    // Read the data-email attribute from the selected option
    const opt = sel.options[sel.selectedIndex];
    emailInput.value = opt ? (opt.getAttribute('data-email') || '') : '';
}

// ── Pre-fill on page load if old() values are set ────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    // Sync google_id from company_email if google_id is empty
    const ce = document.getElementById('company_email');
    const gid = document.getElementById('google_id');
    if (ce && gid && ce.value && !gid.value) {
        gid.value = ce.value;
    }
    // Filter managers by pre-selected company (on validation error re-open)
    const addCo = document.getElementById('addOBCompanySelect');
    if (addCo && addCo.value) filterManagersByCompany(addCo.value, 'reporting_manager');

    // Pre-fill manager email if manager is already selected (on validation error re-open)
    const mgr = document.getElementById('reporting_manager');
    const mgrEmail = document.getElementById('reporting_manager_email');
    if (mgr && mgrEmail && mgr.value && !mgrEmail.value) {
        const opt = mgr.options[mgr.selectedIndex];
        if (opt) mgrEmail.value = opt.getAttribute('data-email') || '';
    }
});

function toggleAsset(name) {
    const cb    = document.getElementById(name);
    const badge = document.getElementById(name + '_badge');
    const card  = cb.closest('.card');
    cb.checked  = !cb.checked;
    if (cb.checked) {
        badge.textContent = 'Yes'; badge.className = 'badge mt-1 bg-success';
        card.classList.add('border-primary');
    } else {
        badge.textContent = 'No'; badge.className = 'badge mt-1 bg-secondary';
        card.classList.remove('border-primary');
    }
}

// Re-open modal if there are validation errors
@if($errors->any())
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('addOnboardingModal')).show();
});
@endif

// ── Bank Name Other toggle (add modal) ───────────────────────────────────
function toggleOBOtherBank(sel, otherId) {
    document.getElementById(otherId)?.classList.toggle('d-none', sel.value !== 'Other');
}

// ── Education list (add modal) ───────────────────────────────────────────
let obEduEntries = [];
function obAddEduEntry() {
    const qual = document.getElementById('obEduQual').value.trim();
    if (!qual) { alert('Please enter a qualification name.'); return; }
    const certInput = document.getElementById('obEduCert');
    const certFile = certInput && certInput.files.length ? certInput.files[0] : null;
    obEduEntries.push({
        qualification: qual,
        institution:   document.getElementById('obEduInst').value.trim(),
        year:          document.getElementById('obEduYear').value.trim(),
        certFile:      certFile,
    });
    obRenderEduList();
    ['obEduQual','obEduInst','obEduYear'].forEach(id => document.getElementById(id).value = '');
    if (certInput) certInput.value = '';
}
function obRemoveEduEntry(i) { obEduEntries.splice(i,1); obRenderEduList(); }
function obRenderEduList() {
    const list = document.getElementById('obEduList');
    const h    = document.getElementById('obEduHidden');
    list.innerHTML = '';
    h.innerHTML    = '';
    obEduEntries.forEach((e,i) => {
        list.innerHTML += `<div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-1 bg-white">
            <div><span class="fw-semibold small">${obEsc(e.qualification)}</span>
            <span class="text-muted small ms-2">${e.institution?e.institution:''}${e.year?' · '+e.year:''}${e.certFile?' · '+obEsc(e.certFile.name):''}</span></div>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="obRemoveEduEntry(${i})">
                <i class="bi bi-x"></i></button></div>`;
        h.innerHTML += `<input type="hidden" name="edu_qualification[]" value="${obEsc(e.qualification)}">
            <input type="hidden" name="edu_institution[]" value="${obEsc(e.institution)}">
            <input type="hidden" name="edu_year[]" value="${obEsc(e.year)}">`;
    });
    // Combine all cert files into one multi-file input
    const allFiles = obEduEntries.map(e => e.certFile).filter(Boolean);
    const old = h.querySelector('input[data-ob-certs]');
    if (old) old.remove();
    if (allFiles.length) {
        const dt = new DataTransfer();
        allFiles.forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = 'edu_certificate[]'; inp.multiple = true;
        inp.setAttribute('data-ob-certs','1'); inp.style.display = 'none';
        inp.files = dt.files;
        h.appendChild(inp);
    }
}

// ── Spouse list (add modal) ──────────────────────────────────────────────
let obSpouseEntries = [];
function obAddSpouseEntry() {
    const name = document.getElementById('obSpName').value.trim();
    if (!name) { alert('Please enter the spouse name.'); return; }
    const marital = document.getElementById('obMaritalStatus');
    const tel = document.getElementById('obSpTel').value.trim();
    if (marital && marital.value === 'married' && !tel) {
        alert('Tel No. is required when Marital Status is Married.');
        document.getElementById('obSpTel').focus();
        return;
    }
    obSpouseEntries.push({
        name, nric: document.getElementById('obSpNric').value.trim(),
        tel:  document.getElementById('obSpTel').value.trim(),
        occupation: document.getElementById('obSpOccupation').value.trim(),
        incomeTax:  document.getElementById('obSpIncomeTax').value.trim(),
        address:    document.getElementById('obSpAddress').value.trim(),
        working:    document.getElementById('obSpWorking').value,
        disabled:   document.getElementById('obSpDisabled').value,
    });
    obRenderSpouseList();
    ['obSpName','obSpNric','obSpTel','obSpOccupation','obSpIncomeTax','obSpAddress'].forEach(id=>document.getElementById(id).value='');
}
function obRemoveSpouseEntry(i) { obSpouseEntries.splice(i,1); obRenderSpouseList(); }
function obRenderSpouseList() {
    const list = document.getElementById('obSpouseList');
    const h    = document.getElementById('obSpouseHidden');
    list.innerHTML = '';
    h.innerHTML    = '';
    obSpouseEntries.forEach((e,i) => {
        list.innerHTML += `<div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-1 bg-white">
            <div><span class="fw-semibold small">${obEsc(e.name)}</span>
            <span class="text-muted small ms-2">${e.tel?e.tel:''}</span></div>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="obRemoveSpouseEntry(${i})">
                <i class="bi bi-x"></i></button></div>`;
        h.innerHTML += `<input type="hidden" name="spouses[${i}][name]" value="${obEsc(e.name)}">
            <input type="hidden" name="spouses[${i}][nric_no]" value="${obEsc(e.nric)}">
            <input type="hidden" name="spouses[${i}][tel_no]" value="${obEsc(e.tel)}">
            <input type="hidden" name="spouses[${i}][occupation]" value="${obEsc(e.occupation)}">
            <input type="hidden" name="spouses[${i}][income_tax_no]" value="${obEsc(e.incomeTax)}">
            <input type="hidden" name="spouses[${i}][address]" value="${obEsc(e.address||'')}">
            <input type="hidden" name="spouses[${i}][is_working]" value="${e.working}">
            <input type="hidden" name="spouses[${i}][is_disabled]" value="${e.disabled}">`;
    });
}

// ── Emergency contacts (add modal) ───────────────────────────────────────
let obEcEntries = [];
function obAddEcEntry() {
    const name = document.getElementById('obEcName').value.trim();
    const tel  = document.getElementById('obEcTel').value.trim();
    const rel  = document.getElementById('obEcRel').value;
    if (!name || !tel || !rel) { alert('Please fill in Name, Tel No., and Relationship.'); return; }
    if (obEcEntries.length >= 2) { alert('Maximum 2 emergency contacts.'); return; }
    obEcEntries.push({ name, tel, relationship: rel });
    obRenderEcList();
    document.getElementById('obEcName').value='';
    document.getElementById('obEcTel').value='';
    document.getElementById('obEcRel').value='';
}
function obRemoveEcEntry(i) { obEcEntries.splice(i,1); obRenderEcList(); }
function obRenderEcList() {
    const list = document.getElementById('obEcList');
    const h    = document.getElementById('obEcHidden');
    const txt  = document.getElementById('obEcCountText');
    list.innerHTML = '';
    h.innerHTML    = '';
    obEcEntries.forEach((e,i) => {
        const order = i+1;
        list.innerHTML += `<div class="d-flex align-items-center justify-content-between border rounded px-3 py-2 mb-1 bg-white">
            <div><span class="fw-semibold small">Contact ${order}: ${obEsc(e.name)}</span>
            <span class="text-muted small ms-2">${obEsc(e.tel)} · ${obEsc(e.relationship)}</span></div>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="obRemoveEcEntry(${i})">
                <i class="bi bi-x"></i></button></div>`;
        h.innerHTML += `<input type="hidden" name="emergency[${order}][name]" value="${obEsc(e.name)}">
            <input type="hidden" name="emergency[${order}][tel_no]" value="${obEsc(e.tel)}">
            <input type="hidden" name="emergency[${order}][relationship]" value="${obEsc(e.relationship)}">`;
    });
    if (txt) txt.textContent = `${obEcEntries.length} of 2 contacts added.`;
}
function obEsc(s){return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// ── Marital Status → Spouse Section toggle (HR full form) ───────────────
function obToggleSpouseSection(val) {
    const section = document.getElementById('obSpouseSection');
    const star    = document.querySelector('.ob-spouse-required');
    if (!section) return;
    const isMarried = val === 'married';
    section.querySelectorAll('input, select, textarea, button').forEach(el => {
        el.disabled = !isMarried;
    });
    section.style.opacity = isMarried ? '1' : '0.4';
    if (star) star.classList.toggle('d-none', !isMarried);
    if (isMarried) {
        const addr   = document.getElementById('obResAddress');
        const spAddr = document.getElementById('obSpAddress');
        if (addr && spAddr && !spAddr.value.trim()) spAddr.value = addr.value;
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('obMaritalStatus');
    if (sel) obToggleSpouseSection(sel.value);
});

</script>