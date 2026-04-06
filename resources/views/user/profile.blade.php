@extends('layouts.app')
@section('title', 'My Profile')
@section('page-title', 'My Profile')

@section('content')
@php
    $emp     = $employee;
    $ob      = $emp?->onboarding;
    $aarf    = $aarf ?? $ob?->aarf ?? null;

    $aarfUrl = $aarf?->acknowledgement_token
               ? route('aarf.view', $aarf->acknowledgement_token)
               : null;

    $statusColors = ['active'=>'success','resigned'=>'danger','terminated'=>'danger','contract_ended'=>'secondary'];

    $roleLabels = [
        'hr_manager'   => 'HR Manager',
        'hr_executive' => 'HR Executive',
        'hr_intern'    => 'HR Intern',
        'it_manager'   => 'IT Manager',
        'it_executive' => 'IT Executive',
        'it_intern'    => 'IT Intern',
        'superadmin'   => 'SuperAdmin',
        'system_admin' => 'System Admin',
        'employee'     => 'Employee',
    ];
@endphp

{{-- ── Profile Header ────────────────────────────────────────────────────── --}}
<div class="card mb-4">
    <div class="card-body d-flex align-items-center gap-4 py-3">
        @if($user->profile_picture)
            <img src="{{ asset('storage/' . $user->profile_picture) }}"
                 alt="Profile Photo"
                 class="rounded-circle border shadow-sm flex-shrink-0"
                 style="width:80px;height:80px;object-fit:cover;">
        @else
            <img src="https://ui-avatars.com/api/?name={{ urlencode($emp?->full_name ?? $user->name) }}&background=2563eb&color=fff&size=200"
                 alt="Avatar"
                 class="rounded-circle border shadow-sm flex-shrink-0"
                 style="width:80px;height:80px;object-fit:cover;">
        @endif
        <div class="flex-fill">
            <h5 class="fw-bold mb-1">{{ $emp?->full_name ?? $user->name }}</h5>
            @if($emp?->preferred_name && $emp->preferred_name !== $emp->full_name)
                <p class="text-muted mb-1 small">Known as: <em>{{ $emp->preferred_name }}</em></p>
            @endif
            <p class="text-muted mb-2 small">{{ $emp?->designation ?? '—' }}</p>
            <div class="d-flex flex-wrap gap-1">
                @if($emp?->company)
                    <span class="badge bg-primary">{{ $emp->company }}</span>
                @endif
                @if($emp?->department)
                    <span class="badge bg-secondary">{{ $emp->department }}</span>
                @endif
                <span class="badge bg-info text-dark">{{ $roleLabels[$user->role] ?? ucwords(str_replace('_',' ',$user->role ?? '')) }}</span>
            </div>
        </div>
        <div class="d-flex flex-column gap-2 flex-shrink-0">
            <a href="{{ route('account') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-camera me-1"></i>Change Photo
            </a>
            @if($aarfUrl)
            <a href="{{ $aarfUrl }}" target="_blank" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-file-earmark-check me-1"></i>View AARF
            </a>
            @endif
        </div>
    </div>
</div>

{{-- ── Single form wrapping Sections A–I ────────────────────────────────── --}}
<form action="{{ route('profile.update') }}" method="POST" enctype="multipart/form-data" id="profileMainForm">
    @csrf @method('PUT')

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION A — Personal Details (editable)                                   --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">A</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Personal Details</h6>
    </div>
    <div class="card-body py-3">
        {{-- Sentinel: always present so controller knows the keep list was explicitly submitted --}}
        <input type="hidden" name="nric_keep_submitted" value="1">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Full Name <span class="text-muted fw-normal small">(as per official document)</span></label>
                <input type="text" name="full_name"
                       class="form-control @error('full_name') is-invalid @enderror"
                       value="{{ old('full_name', $emp?->full_name) }}" required>
                @error('full_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Preferred Name <span class="text-muted fw-normal small">(nickname)</span></label>
                <input type="text" name="preferred_name" class="form-control"
                       value="{{ old('preferred_name', $emp?->preferred_name) }}"
                       placeholder="What you'd like to be called">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">NRIC / Passport Number <span class="text-muted fw-normal small">(as per IC / Passport)</span></label>
                <input type="text" name="official_document_id"
                       class="form-control @error('official_document_id') is-invalid @enderror"
                       value="{{ old('official_document_id', $emp?->official_document_id) }}" required>
                @error('official_document_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">NRIC / Passport Copy Upload
                    <span class="text-muted fw-normal small">(PDF/image, max 5 files)</span>
                </label>
                @php $existingNric = $emp?->nric_file_paths ?? ($emp?->nric_file_path ? [$emp->nric_file_path] : []); @endphp
                {{-- Existing files — each with a remove button --}}
                <div id="profileNricExistingList" class="mb-2">
                    @foreach($existingNric as $idx => $path)
                    <div class="d-inline-flex align-items-center gap-1 me-1 mb-1" id="profileNricItem_{{ $idx }}">
                        <a href="{{ secure_file_url($path) }}" target="_blank"
                           class="btn btn-sm btn-outline-primary" style="font-size:12px;">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $idx+1 }}
                        </a>
                        <input type="hidden" name="nric_keep_paths[]" value="{{ $path }}" class="profile-nric-keep-input">
                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                                style="font-size:12px;"
                                onclick="profileRemoveNricExisting(this)"
                                title="Remove this file">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    @endforeach
                </div>
                {{-- New file upload --}}
                <div class="d-flex gap-2 mb-1">
                    <input type="file" id="profileNricNewFileInput" class="form-control" accept=".jpg,.jpeg,.png,.pdf" style="max-width:340px;">
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0"
                            onclick="profileAddNricFile()">
                        <i class="bi bi-upload me-1"></i>Add
                    </button>
                </div>
                <div id="profileNricNewList"></div>
                <div id="profileNricNewHidden"></div>
                <div class="form-text">Max 5 files total. Click <i class="bi bi-x"></i> to remove a file.</div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Date of Birth</label>
                <input type="date" name="date_of_birth"
                       class="form-control @error('date_of_birth') is-invalid @enderror"
                       value="{{ old('date_of_birth', $emp?->date_of_birth?->format('Y-m-d')) }}" required>
                @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Sex</label>
                <select name="sex" class="form-select @error('sex') is-invalid @enderror" required>
                    <option value="">Select...</option>
                    <option value="male"   {{ old('sex',$emp?->sex)==='male'   ? 'selected':'' }}>Male</option>
                    <option value="female" {{ old('sex',$emp?->sex)==='female' ? 'selected':'' }}>Female</option>
                </select>
                @error('sex')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Marital Status</label>
                <select name="marital_status" id="profileMaritalStatus" class="form-select @error('marital_status') is-invalid @enderror" required onchange="profileToggleSpouse(this.value)">
                    <option value="">Select...</option>
                    @foreach(['single'=>'Single','married'=>'Married','divorced'=>'Divorced','widowed'=>'Widowed'] as $v=>$l)
                        <option value="{{ $v }}" {{ old('marital_status',$emp?->marital_status)===$v ? 'selected':'' }}>{{ $l }}</option>
                    @endforeach
                </select>
                @error('marital_status')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Religion</label>
                <input type="text" name="religion"
                       class="form-control @error('religion') is-invalid @enderror"
                       value="{{ old('religion', $emp?->religion) }}" required>
                @error('religion')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Race</label>
                <input type="text" name="race"
                       class="form-control @error('race') is-invalid @enderror"
                       value="{{ old('race', $emp?->race) }}" required>
                @error('race')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tel No. (H/phone)</label>
                <input type="text" name="personal_contact_number"
                       class="form-control @error('personal_contact_number') is-invalid @enderror"
                       value="{{ old('personal_contact_number', $emp?->personal_contact_number) }}" required>
                @error('personal_contact_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tel No. (House)</label>
                <input type="text" name="house_tel_no" class="form-control"
                       value="{{ old('house_tel_no', $emp?->house_tel_no) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Personal Email</label>
                <input type="email" name="personal_email"
                       class="form-control @error('personal_email') is-invalid @enderror"
                       value="{{ old('personal_email', $emp?->personal_email) }}" required>
                @error('personal_email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Bank Account Number</label>
                <input type="text" name="bank_account_number"
                       class="form-control @error('bank_account_number') is-invalid @enderror"
                       value="{{ old('bank_account_number', $emp?->bank_account_number) }}" required>
                @error('bank_account_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Bank Name</label>
                <select name="bank_name" id="profileBankName" class="form-select"
                        onchange="toggleOtherBank(this,'profileBankNameOther')">
                    <option value="">— Select Bank —</option>
                    @foreach(['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank','Other'] as $bank)
                    <option value="{{ $bank }}" {{ old('bank_name',$emp?->bank_name)==$bank?'selected':'' }}>{{ $bank }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 {{ in_array(old('bank_name',$emp?->bank_name??''),['Other','other'])?'':'d-none' }}" id="profileBankNameOther">
                <label class="form-label fw-semibold">Other Bank Name</label>
                <input type="text" name="bank_name_other" class="form-control"
                       value="{{ old('bank_name_other', in_array($emp?->bank_name ?? '', ['Maybank','CIMB Bank','Public Bank','RHB Bank','Hong Leong Bank','AmBank','Bank Islam','Bank Rakyat','BSN','OCBC Bank','UOB Malaysia','HSBC Bank','Standard Chartered','Affin Bank','Alliance Bank']) ? '' : ($emp?->bank_name ?? '')) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">EPF No.</label>
                <input type="text" name="epf_no" class="form-control"
                       value="{{ old('epf_no', $emp?->epf_no) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Income Tax No.</label>
                <input type="text" name="income_tax_no" class="form-control"
                       value="{{ old('income_tax_no', $emp?->income_tax_no) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">SOCSO No.</label>
                <input type="text" name="socso_no" class="form-control"
                       value="{{ old('socso_no', $emp?->socso_no) }}">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Disabled Person</label>
                <select name="is_disabled" class="form-select">
                    <option value="0" {{ !old('is_disabled',$emp?->is_disabled??false)?'selected':'' }}>No</option>
                    <option value="1" {{ old('is_disabled',$emp?->is_disabled??false)?'selected':'' }}>Yes</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Residential Address</label>
                <textarea name="residential_address"
                          class="form-control @error('residential_address') is-invalid @enderror"
                          rows="2" required>{{ old('residential_address', $emp?->residential_address) }}</textarea>
                @error('residential_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION B — Work Details (read-only, managed by HR)                       --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">B</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-briefcase me-2 text-primary"></i>Work Details</h6>
        </div>
        <span class="badge bg-light text-muted border" style="font-size:11px;">
            <i class="bi bi-lock me-1"></i>Managed by HR
        </span>
    </div>
    <div class="card-body py-3">
        @if($emp?->designation)
        <div class="row g-0">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Employment Type</td>
                        <td class="py-2">{{ $emp->employment_type ? ucfirst($emp->employment_type) : '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Employment Status</td>
                        <td class="py-2">
                            <span class="badge bg-{{ $statusColors[$emp->employment_status ?? 'active'] ?? 'success' }}">
                                {{ ucfirst(str_replace('_',' ', $emp->employment_status ?? 'active')) }}
                            </span>
                        </td></tr>
                    <tr><td class="text-muted py-2">Designation</td>
                        <td class="fw-semibold py-2">{{ $emp->designation }}</td></tr>
                    <tr><td class="text-muted py-2">Department</td>
                        <td class="py-2">{{ $emp->department ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Company</td>
                        <td class="py-2">{{ $emp->company ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Office Location</td>
                        <td class="py-2">{{ $emp->office_location ?? '—' }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Reporting Manager</td>
                        <td class="py-2">{{ $emp->reporting_manager ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Start Date</td>
                        <td class="py-2">{{ $emp->start_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Exit Date</td>
                        <td class="py-2">{{ $emp->exit_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Last Salary Date</td>
                        <td class="py-2">{{ $emp->last_salary_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Company Email</td>
                        <td class="py-2">{{ $emp->company_email ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Google ID</td>
                        <td class="py-2">{{ $emp->google_id ?? '—' }}</td></tr>
                </table>
            </div>
        </div>
        @else
        <p class="text-muted small mb-0">
            <i class="bi bi-info-circle me-1"></i>Work information not yet assigned. Contact HR if this is an error.
        </p>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION C — Asset Assignment (read-only)                                  --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">C</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Asset Assignment</h6>
        </div>
        <span class="badge bg-light text-muted border" style="font-size:11px;">
            <i class="bi bi-lock me-1"></i>Managed by IT
        </span>
    </div>
    <div class="card-body p-0">
        @php $assets = $allAssets ?? collect(); @endphp
        @if($assets->isEmpty())
            <p class="text-muted small p-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>No assets assigned. Contact IT if you believe this is an error.
            </p>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Asset</th>
                        <th>Type</th>
                        <th>Serial / Tag</th>
                        <th>Assigned Date</th>
                        <th>Photos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($assets as $assignment)
                    @php $a = $assignment->asset; @endphp
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold">{{ trim(($a?->brand ?? '').' '.($a?->model ?? '')) ?: '—' }}</div>
                            @if($a)
                            <div class="text-muted" style="font-size:11px;">
                                @if($a->processor) <span>{{ $a->processor }}</span> @endif
                                @if($a->ram_size) · <span>{{ $a->ram_size }}</span> @endif
                                @if($a->storage) · <span>{{ $a->storage }}</span> @endif
                            </div>
                            @endif
                        </td>
                        <td class="text-muted small">
                            {{ $a?->asset_type ? ucfirst(str_replace('_',' ',$a->asset_type)) : '—' }}
                        </td>
                        <td class="text-muted small">
                            <code>{{ $a?->serial_number ?? $a?->asset_tag ?? '—' }}</code>
                        </td>
                        <td class="text-muted small">
                            {{ $assignment->assigned_date ? \Carbon\Carbon::parse($assignment->assigned_date)->format('d M Y') : '—' }}
                        </td>
                        <td>
                            @if($a && $a->asset_photos && count($a->asset_photos))
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#photoModal{{ $a->id }}"
                                    title="View Photos">
                                <i class="bi bi-images me-1"></i>{{ count($a->asset_photos) }}
                            </button>
                            @else
                            <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Photo Lightbox Modals --}}
            @foreach($assets as $assignment)
            @php $a = $assignment->asset; @endphp
            @if($a && $a->asset_photos && count($a->asset_photos))
            <div class="modal fade" id="photoModal{{ $a->id }}" tabindex="-1">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header py-2" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                            <h6 class="modal-title text-white fw-bold mb-0">
                                <i class="bi bi-images me-2"></i>{{ trim(($a->brand ?? '').' '.($a->model ?? '')) }} — Photos
                            </h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2">
                                @foreach($a->asset_photos as $photo)
                                <div class="col-md-4 col-6">
                                    <a href="{{ asset('storage/'.$photo) }}" target="_blank">
                                        <img src="{{ asset('storage/'.$photo) }}"
                                             class="img-fluid rounded" style="width:100%;height:180px;object-fit:cover;">
                                    </a>
                                </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @endif
            @endforeach
        </div>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION D — Access Role (read-only)                                       --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">D</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Access Role</h6>
        </div>
        <span class="badge bg-light text-muted border" style="font-size:11px;">
            <i class="bi bi-lock me-1"></i>Managed by HR
        </span>
    </div>
    <div class="card-body py-3">
        <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
            <tr>
                <td class="text-muted py-2" style="width:22%;padding-left:0;">System Role</td>
                <td class="py-2">
                    <span class="badge bg-primary px-3 py-2" style="font-size:13px;">
                        {{ $roleLabels[$emp?->work_role ?? $user->role] ?? ucwords(str_replace('_',' ',$emp?->work_role ?? $user->role ?? '')) }}
                    </span>
                </td>
            </tr>
            <tr>
                <td class="text-muted py-2">Login Email</td>
                <td class="py-2">{{ $user->work_email }}</td>
            </tr>
        </table>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION E — Documents (read-only for user)                                --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">E</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-folder2-open me-2 text-primary"></i>Documents</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">

            {{-- Employment Contracts --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:38px;height:38px;background:#dbeafe;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-file-earmark-text" style="font-size:18px;color:#2563eb;"></i>
                        </div>
                        <div class="fw-semibold small">Employment Contract</div>
                    </div>
                    @if($contracts->isEmpty())
                        <p class="text-muted small mb-0">No contract on file. Contact HR if you expect to see one here.</p>
                    @else
                        @foreach($contracts as $contract)
                        <div class="d-flex align-items-center justify-content-between gap-2 py-1 {{ !$loop->last ? 'border-bottom' : '' }}">
                            <div class="text-truncate" style="font-size:12px;">
                                <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
                                <span title="{{ $contract->original_filename }}">{{ $contract->original_filename }}</span>
                                <div class="text-muted" style="font-size:11px;">
                                    {{ $contract->file_size_label }} &middot; {{ $contract->created_at->format('d M Y') }}
                                    @if($contract->notes)<br>{{ $contract->notes }}@endif
                                </div>
                            </div>
                            <a href="{{ route('employees.contracts.download', [$emp, $contract]) }}"
                               class="btn btn-outline-primary btn-sm flex-shrink-0" style="padding:3px 8px;" title="Download">
                                <i class="bi bi-download" style="font-size:12px;"></i>
                            </a>
                        </div>
                        @endforeach
                    @endif
                </div>
            </div>

            {{-- Employee Handbook --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:38px;height:38px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-book" style="font-size:18px;color:#16a34a;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small">Employee Handbook</div>
                            <div class="text-muted" style="font-size:11px;">Official company policies and guidelines</div>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <a href="{{ route('profile.download', 'handbook') }}"
                           class="btn btn-outline-success btn-sm w-100" target="_blank">
                            <i class="bi bi-eye me-1"></i>View Handbook
                        </a>
                    </div>
                </div>
            </div>

            {{-- Orientation Slide --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:38px;height:38px;background:#fef3c7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-easel" style="font-size:18px;color:#d97706;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small">Orientation Slide</div>
                            <div class="text-muted" style="font-size:11px;">New employee orientation presentation</div>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <a href="{{ route('profile.download', 'orientation') }}"
                           class="btn btn-outline-warning btn-sm w-100" target="_blank">
                            <i class="bi bi-eye me-1"></i>View Orientation
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

@if($emp)
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION F — Education & Work History (editable)                           --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@php $eduList = $emp->educationHistories ?? collect(); @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">F</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Education &amp; Work History</h6>
    </div>
    <div class="card-body py-3">
        <input type="hidden" name="edu_delete_ids" id="profileEduDeleteIds" value="">
        <div id="profileEduContainer">
            @forelse($eduList as $edu)
            <div class="border rounded p-3 mb-3 profile-edu-row" data-edu-idx="{{ $loop->index }}">
                <input type="hidden" name="edu_id[]" value="{{ $edu->id }}">
                {{-- Summary row --}}
                <div class="d-flex align-items-start justify-content-between">
                    <div class="edu-summary">
                        <div class="fw-semibold">{{ $edu->qualification }}</div>
                        <div class="text-muted small">
                            {{ $edu->institution ?? '' }}{{ $edu->year_graduated ? ' · '.$edu->year_graduated : '' }}
                        </div>
                        @php $editCertFiles = $edu->certificate_paths ?? ($edu->certificate_path ? [$edu->certificate_path] : []); @endphp
                        @if(!empty($editCertFiles))
                        <div class="mt-1">
                            @foreach($editCertFiles as $cf)
                            <a href="{{ secure_file_url($cf) }}" target="_blank"
                               class="btn btn-outline-primary me-1" style="padding:2px 8px;font-size:11px;">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $loop->iteration }}
                            </a>
                            @endforeach
                        </div>
                        @endif
                    </div>
                    <div class="d-flex gap-1 ms-2 flex-shrink-0">
                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                onclick="profileToggleEduEdit(this)">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                onclick="profileMarkEduDelete(this, {{ $edu->id }})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                {{-- Inline edit fields (collapsed by default) --}}
                @php $inlineCerts = $edu->certificate_paths ?? ($edu->certificate_path ? [$edu->certificate_path] : []); @endphp
                <div class="profile-edu-fields mt-3 d-none" data-edu-idx="{{ $loop->index }}">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Qualification</label>
                            <input type="text" name="edu_qualification[]" class="form-control form-control-sm"
                                   value="{{ $edu->qualification }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Institution</label>
                            <input type="text" name="edu_institution[]" class="form-control form-control-sm"
                                   value="{{ $edu->institution }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Year Graduated</label>
                            <input type="number" name="edu_year[]" class="form-control form-control-sm"
                                   value="{{ $edu->year_graduated }}" min="1950" max="{{ date('Y')+5 }}">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label fw-semibold small">Certificate(s)
                                <span class="text-muted fw-normal">(max 5 files)</span>
                            </label>
                            {{-- Sentinel: always submitted so controller knows keep-list was explicitly managed --}}
                            <input type="hidden" name="edu_cert_keep_sent[{{ $loop->index }}]" value="1">
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
                                            style="font-size:11px;"
                                            onclick="profileRemoveEduCert(this)"
                                            title="Remove this file">
                                        <i class="bi bi-x"></i>
                                    </button>
                                </div>
                                @endforeach
                            </div>
                            <div class="d-flex gap-2 mb-1">
                                <input type="file" class="profile-edu-cert-file-input form-control form-control-sm"
                                       accept=".jpg,.jpeg,.png,.pdf" style="max-width:260px;"
                                       data-idx="{{ $loop->index }}">
                                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0"
                                        onclick="profileAddEduCertFile(this, {{ $loop->index }})">
                                    <i class="bi bi-upload me-1"></i>Add
                                </button>
                            </div>
                            <div class="profile-edu-cert-new-list" data-idx="{{ $loop->index }}"></div>
                            <div class="profile-edu-cert-new-hidden" data-idx="{{ $loop->index }}"></div>
                            <div class="form-text" style="font-size:11px;">Click <i class="bi bi-x"></i> to mark for removal.</div>
                        </div>
                    </div>
                </div>
            </div>
            @empty
            <p class="text-muted small" id="profileNoEduMsg">No education history yet.</p>
            @endforelse
        </div>

        <div class="d-flex gap-2 mt-2 mb-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="profileAddEduRow()">
                <i class="bi bi-plus-circle me-1"></i>Add Qualification
            </button>
        </div>

        @php $expTotal = $eduList->first()?->years_experience; @endphp
        <div class="row g-3 mb-1">
            <div class="col-md-6">
                <label class="form-label fw-semibold small">
                    No. of Years of Working Experience
                    <span class="text-muted fw-normal">(not incl. part-time)</span>
                </label>
                <select name="edu_experience_total" class="form-select form-select-sm">
                    <option value="">— Select —</option>
                    @for($y=0;$y<=40;$y++)
                    <option value="{{ $y }}" {{ old('edu_experience_total',$expTotal)==$y?'selected':'' }}>
                        {{ $y }} {{ $y==1?'year':'years' }}
                    </option>
                    @endfor
                    <option value="40+" {{ old('edu_experience_total',$expTotal)==='40+'?'selected':'' }}>40+ years</option>
                </select>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION G — Spouse Information (editable)                                 --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@php $spouses = $emp->spouseDetails ?? collect(); @endphp
<input type="hidden" name="del_spouse_ids" id="profDelSpouseIds" value="">
<div class="card mb-3" id="profileSpouseSection">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">G</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Spouse Information <span class="text-danger profile-spouse-required d-none">*</span></h6>
    </div>
    <div class="card-body py-3">

        {{-- Existing spouse records --}}
        @foreach($spouses as $spIdx => $sp)
        <div class="border rounded p-3 mb-3 prof-spouse-card" style="background:#f8fafc;" data-spouse-id="{{ $sp->id }}">
            {{-- Hidden inputs carrying current values --}}
            <input type="hidden" name="spouses[{{ $spIdx }}][id]"            value="{{ $sp->id }}">
            <input type="hidden" name="spouses[{{ $spIdx }}][name]"          value="{{ $sp->name }}"           class="sp-ph-name">
            <input type="hidden" name="spouses[{{ $spIdx }}][nric_no]"       value="{{ $sp->nric_no }}"        class="sp-ph-nric">
            <input type="hidden" name="spouses[{{ $spIdx }}][tel_no]"        value="{{ $sp->tel_no }}"         class="sp-ph-tel">
            <input type="hidden" name="spouses[{{ $spIdx }}][occupation]"    value="{{ $sp->occupation }}"     class="sp-ph-occ">
            <input type="hidden" name="spouses[{{ $spIdx }}][income_tax_no]" value="{{ $sp->income_tax_no }}"  class="sp-ph-tax">
            <input type="hidden" name="spouses[{{ $spIdx }}][address]"       value="{{ $sp->address }}"        class="sp-ph-addr">
            <input type="hidden" name="spouses[{{ $spIdx }}][is_working]"    value="{{ $sp->is_working ? 1 : 0 }}"  class="sp-ph-working">
            <input type="hidden" name="spouses[{{ $spIdx }}][is_disabled]"   value="{{ $sp->is_disabled ? 1 : 0 }}" class="sp-ph-disabled">

            {{-- Summary row --}}
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="fw-semibold sp-display-name">{{ $sp->name }}</div>
                    <div class="text-muted small sp-display-sub">
                        {{ $sp->nric_no ? 'NRIC: '.$sp->nric_no.' · ' : '' }}{{ $sp->tel_no ? 'Tel: '.$sp->tel_no : '' }}{{ $sp->occupation ? ' · '.$sp->occupation : '' }}
                    </div>
                </div>
                <div class="d-flex gap-1 ms-2 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="profToggleSpouseEdit(this)">
                        <i class="bi bi-pencil me-1"></i>Edit
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            onclick="markProfSpouseDelete(this, {{ $sp->id }})">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            {{-- Inline edit fields (hidden by default) --}}
            <div class="prof-spouse-edit-fields mt-3 d-none">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm sp-p-name" value="{{ $sp->name }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">NRIC No.</label>
                        <input type="text" class="form-control form-control-sm sp-p-nric" value="{{ $sp->nric_no }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Tel No. <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm sp-p-tel" value="{{ $sp->tel_no }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Occupation</label>
                        <input type="text" class="form-control form-control-sm sp-p-occ" value="{{ $sp->occupation }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Income Tax No.</label>
                        <input type="text" class="form-control form-control-sm sp-p-tax" value="{{ $sp->income_tax_no }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Address</label>
                        <textarea class="form-control form-control-sm sp-p-addr" rows="2">{{ $sp->address }}</textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Working?</label>
                        <select class="form-select form-select-sm sp-p-working">
                            <option value="0" {{ !$sp->is_working ? 'selected' : '' }}>No</option>
                            <option value="1" {{ $sp->is_working ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Disabled?</label>
                        <select class="form-select form-select-sm sp-p-disabled">
                            <option value="0" {{ !$sp->is_disabled ? 'selected' : '' }}>No</option>
                            <option value="1" {{ $sp->is_disabled ? 'selected' : '' }}>Yes</option>
                        </select>
                    </div>
                    <div class="col-12 text-end">
                        <button type="button" class="btn btn-primary btn-sm px-4"
                                onclick="saveProfSpouseEdit(this)">
                            <i class="bi bi-check-circle me-1"></i>Update
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endforeach

        {{-- New spouse rendered cards --}}
        <div id="profNewSpouseList"></div>

        {{-- Add new spouse panel --}}
        <div id="profAddSpousePanel">
            <p class="fw-semibold small text-muted mb-2">Add {{ $spouses->isEmpty() ? 'Spouse' : 'Another Spouse' }}</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="profNewSpName" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">NRIC No.</label>
                    <input type="text" id="profNewSpNric" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tel No. <span class="text-danger">*</span></label>
                    <input type="text" id="profNewSpTel" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Occupation</label>
                    <input type="text" id="profNewSpOcc" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Income Tax No.</label>
                    <input type="text" id="profNewSpTax" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Address</label>
                    <textarea id="profNewSpAddr" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Working?</label>
                    <select id="profNewSpWorking" class="form-select">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Disabled?</label>
                    <select id="profNewSpDisabled" class="form-select">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>
            </div>
            <div class="text-end mt-3">
                <button type="button" class="btn btn-outline-primary btn-sm px-4"
                        onclick="addProfNewSpouse()">
                    <i class="bi bi-plus-circle me-1"></i>Add to List
                </button>
            </div>
        </div>
        {{-- Hidden container for new spouse hidden inputs --}}
        <div id="profSpouseNewHidden"></div>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION H — Emergency Contacts (editable)                                 --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@php $contacts = $emp->emergencyContacts->keyBy('contact_order'); @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">H</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Emergency Contacts</h6>
        <span class="text-muted small ms-1">(2 required)</span>
    </div>
    <div class="card-body py-3">
        @foreach([1,2] as $n)
        @php $contact = $contacts[$n] ?? null; @endphp
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
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION I — Child Registration (editable)                                 --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@php
    $ch = $emp->childRegistration;
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
    <div class="card-body py-3">
        <p class="text-muted small mb-3">Enter the number of children in each category for LHDN tax relief purposes.</p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th style="width:60%;vertical-align:middle;" rowspan="2">Number of children according to the category below for tax relief purpose</th>
                        <th colspan="2" class="text-center">Number of children</th>
                    </tr>
                    <tr>
                        <th class="text-center" style="width:110px;">100%<br><small class="fw-normal">(tax relief by self)</small></th>
                        <th class="text-center" style="width:110px;">50%<br><small class="fw-normal">(shared with spouse)</small></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($catLabels as $key => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="text-center" style="padding:4px;">
                            <input type="number" name="cat_{{ $key }}_100" class="form-control form-control-sm text-center"
                                   style="width:70px;margin:auto;"
                                   value="{{ old('cat_'.$key.'_100', $ch?->{'cat_'.$key.'_100'} ?? 0) }}"
                                   min="0" max="99">
                        </td>
                        <td class="text-center" style="padding:4px;">
                            <input type="number" name="cat_{{ $key }}_50" class="form-control form-control-sm text-center"
                                   style="width:70px;margin:auto;"
                                   value="{{ old('cat_'.$key.'_50', $ch?->{'cat_'.$key.'_50'} ?? 0) }}"
                                   min="0" max="99">
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Save Changes ── --}}
<div class="card mb-4">
    <div class="card-body d-flex align-items-center gap-3">
        <button type="submit" class="btn btn-primary px-5">
            <i class="bi bi-check-circle me-2"></i>Save Changes
        </button>
        <span class="text-muted small">Saving will update all sections and send a consent acknowledgement email if any data changed.</span>
    </div>
</div>
</form>

{{-- ── Declaration & Consent ── --}}
@php
    // Show acknowledge button if: no consent yet, OR there is a pending unacknowledged edit log
    $needsAcknowledgement = !$emp->consent_given_at || !empty($pendingConsentLog);
@endphp
<div class="card mb-4 @if($needsAcknowledgement) border-warning @endif">
    <div class="card-header py-3 d-flex align-items-center gap-2
        @if($needsAcknowledgement) bg-warning bg-opacity-10 @else bg-white @endif"
        style="border-left:4px solid {{ $needsAcknowledgement ? '#f59e0b' : '#16a34a' }};">
        <h6 class="mb-0 fw-bold">
            @if($needsAcknowledgement)
                <i class="bi bi-exclamation-triangle me-2 text-warning"></i>Action Required: Declaration &amp; Consent
            @else
                <i class="bi bi-file-earmark-check me-2 text-success"></i>Declaration &amp; Consent
            @endif
        </h6>
        @if(!$needsAcknowledgement)
            <span class="ms-auto badge bg-success bg-opacity-10 text-success border border-success" style="font-size:11px;">
                <i class="bi bi-check-circle me-1"></i>Acknowledged
            </span>
        @endif
    </div>
    <div class="card-body">
        {{-- PDPA text always visible --}}
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1.25rem;font-size:13.5px;line-height:1.8;" class="mb-3">
            <p class="fw-semibold mb-2">Personal Data Protection Act (PDPA) 2010 — Consent</p>
            <p>I hereby declare that all information provided above is true and accurate to the best of my knowledge. I understand that any false or misleading information may result in disciplinary action or termination of employment.</p>
            <p>I consent to the collection, use, and disclosure of my personal data by the Company for purposes related to employment administration, payroll processing, statutory contributions and deductions (EPF, SOCSO, EIS, PCB), and employee benefits management, in compliance with the Personal Data Protection Act (PDPA) 2010 of Malaysia.</p>
            <p class="mb-0">I also agree to promptly notify the HRA Department of any changes to the information provided above, including updates to my contact details, banking information, or personal particulars.</p>
        </div>

        @if($needsAcknowledgement)
            {{-- Pending: show what changed (if triggered by HR edit) --}}
            @if(!empty($pendingConsentLog) && !empty($pendingConsentLog->sections_changed))
            <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:13px;">
                <i class="bi bi-info-circle me-2"></i>
                Your information was updated by HR on <strong>{{ $pendingConsentLog->created_at->format('d M Y') }}</strong>.
                Sections changed:
                @foreach($pendingConsentLog->sections_changed as $sec)
                    <span class="badge bg-warning text-dark ms-1">{{ $sec }}</span>
                @endforeach
                Please re-acknowledge below.
            </div>
            @endif
            <form method="POST" action="{{ route('profile.consent') }}">
                @csrf
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-pen me-2"></i>I Acknowledge &amp; Give Consent
                </button>
                <p class="text-muted small mt-2 mb-0">By clicking the button above, you confirm that you have read and agreed to the declaration above.</p>
            </form>
        @else
            {{-- Acknowledged: show status --}}
            <div class="d-flex align-items-center gap-3 p-3 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                <i class="bi bi-check-circle-fill text-success" style="font-size:22px;"></i>
                <div>
                    <div class="fw-semibold text-success small">Consent Acknowledged</div>
                    <div class="text-muted small">Submitted on {{ $emp->consent_given_at->format('d M Y, h:i A') }}</div>
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ── Edit & Consent Acknowledgement Log ── --}}
@if($editLogs->isNotEmpty())
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #94a3b8;">
        <h6 class="mb-0 fw-bold text-muted"><i class="bi bi-clock-history me-2"></i>Edit &amp; Consent Acknowledgement Log</h6>
    </div>
    <div style="max-height:320px;overflow-y:auto;overflow-x:auto;">
        <table class="table table-sm align-middle mb-0" style="font-size:12.5px;min-width:900px;">
            <thead style="background:#f8fafc;position:sticky;top:0;z-index:1;">
                <tr>
                    <th class="ps-3" style="width:150px;">Date &amp; Time</th>
                    <th style="width:150px;">Edited By</th>
                    <th>Sections Changed</th>
                    <th style="width:180px;">Sent To</th>
                    <th style="width:130px;">Consent Status</th>
                    <th style="width:150px;">Acknowledged By</th>
                    <th class="pe-3" style="width:150px;">Acknowledged At</th>
                </tr>
            </thead>
            <tbody>
                @foreach($editLogs as $log)
                <tr>
                        <td class="ps-3 text-muted">{{ $log->created_at->format('d M Y, h:i A') }}</td>
                        <td>
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
@endif

@push('scripts')
<script>
// ── Bank Name Other toggle ─────────────────────────────────────────────────
function toggleOtherBank(sel, otherId) {
    const el = document.getElementById(otherId);
    if (el) el.classList.toggle('d-none', sel.value !== 'Other');
}
document.addEventListener('DOMContentLoaded', function() {
    const b = document.getElementById('profileBankName');
    if (b) toggleOtherBank(b, 'profileBankNameOther');
});

// ── Section A — NRIC file management ──────────────────────────────────────
function profileRemoveNricExisting(btn) {
    const wrapper = btn.closest('.d-inline-flex');
    const keepInput = wrapper.querySelector('.profile-nric-keep-input');
    if (keepInput) keepInput.disabled = true;
    wrapper.style.opacity = '0.4';
    wrapper.style.pointerEvents = 'none';
    btn.disabled = true;
}

let profileNricNewFiles = [];

function profileAddNricFile() {
    const input = document.getElementById('profileNricNewFileInput');
    if (!input.files.length) return;
    const existing = document.querySelectorAll('.profile-nric-keep-input:not([disabled])').length;
    const total    = existing + profileNricNewFiles.length + input.files.length;
    if (total > 5) { alert('Maximum 5 NRIC/Passport files allowed.'); return; }
    Array.from(input.files).forEach(f => profileNricNewFiles.push(f));
    profileRenderNricNewList();
    input.value = '';
}

function profileRemoveNricNew(i) {
    profileNricNewFiles.splice(i, 1);
    profileRenderNricNewList();
}

function profileRenderNricNewList() {
    const list   = document.getElementById('profileNricNewList');
    const hidden = document.getElementById('profileNricNewHidden');
    if (!list) return;
    list.innerHTML = '';
    profileNricNewFiles.forEach((f, i) => {
        list.innerHTML += `<div class="d-inline-flex align-items-center gap-1 me-1 mb-1">
            <span class="btn btn-sm btn-outline-secondary" style="font-size:11px;pointer-events:none;">
                <i class="bi bi-file-earmark me-1"></i>${escH(f.name)}
            </span>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:11px;"
                    onclick="profileRemoveNricNew(${i})"><i class="bi bi-x"></i></button>
        </div>`;
    });
    // Sync a hidden file input named nric_files[]
    if (hidden) {
        hidden.innerHTML = '';
        if (profileNricNewFiles.length > 0) {
            const inp = document.createElement('input');
            inp.type = 'file'; inp.name = 'nric_files[]'; inp.multiple = true; inp.style.display = 'none';
            hidden.appendChild(inp);
            const dt = new DataTransfer();
            profileNricNewFiles.forEach(f => dt.items.add(f));
            inp.files = dt.files;
        }
    }
}

// ── Section F — Education cert per-file management ─────────────────────────
function profileRemoveEduCert(btn) {
    const wrapper = btn.closest('.d-inline-flex');
    const keepInput = wrapper.querySelector('.edu-cert-keep-input');
    if (keepInput) keepInput.disabled = true;
    wrapper.style.opacity = '0.4';
    wrapper.style.pointerEvents = 'none';
    btn.disabled = true;
}

const profileEduCertNewFiles = {};

function profileAddEduCertFile(btn, idx) {
    const row   = btn.closest('.profile-edu-fields');
    const input = row ? row.querySelector(`.profile-edu-cert-file-input[data-idx="${idx}"]`) : null;
    if (!input || !input.files.length) { alert('Please select a file first.'); return; }
    const keepCount = row.querySelectorAll('.edu-cert-keep-input:not([disabled])').length;
    if (!profileEduCertNewFiles[idx]) profileEduCertNewFiles[idx] = [];
    if (keepCount + profileEduCertNewFiles[idx].length >= 5) { alert('Maximum 5 files per entry.'); return; }
    profileEduCertNewFiles[idx].push(input.files[0]);
    profileRenderEduCertNewList(idx, row);
    input.value = '';
}

function profileRemoveEduCertNew(idx, i) {
    if (profileEduCertNewFiles[idx]) profileEduCertNewFiles[idx].splice(i, 1);
    const row = document.querySelector(`.profile-edu-fields[data-edu-idx="${idx}"]`);
    if (row) profileRenderEduCertNewList(idx, row);
}

function profileRenderEduCertNewList(idx, row) {
    const list   = row.querySelector(`.profile-edu-cert-new-list[data-idx="${idx}"]`);
    const hidden = row.querySelector(`.profile-edu-cert-new-hidden[data-idx="${idx}"]`);
    if (!list || !hidden) return;
    list.innerHTML = '';
    (profileEduCertNewFiles[idx] || []).forEach((f, i) => {
        list.innerHTML += `<div class="d-inline-flex align-items-center gap-1 me-1 mb-1">
            <span class="btn btn-sm btn-outline-secondary disabled" style="font-size:11px;pointer-events:none;">
                <i class="bi bi-file-earmark me-1"></i>${escH(f.name)}</span>
            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" style="font-size:11px;"
                    onclick="profileRemoveEduCertNew(${idx},${i})"><i class="bi bi-x"></i></button>
        </div>`;
    });
    const old = hidden.querySelector('input[data-edu-cert-new]');
    if (old) old.remove();
    if ((profileEduCertNewFiles[idx] || []).length) {
        const dt = new DataTransfer();
        profileEduCertNewFiles[idx].forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = `edu_certificate[${idx}][]`; inp.multiple = true;
        inp.setAttribute('data-edu-cert-new', '1'); inp.style.display = 'none';
        inp.files = dt.files;
        hidden.appendChild(inp);
    }
}

function profileToggleEduEdit(btn) {
    const row = btn.closest('.profile-edu-row');
    const fields = row.querySelector('.profile-edu-fields');
    const isHidden = fields.classList.contains('d-none');
    fields.classList.toggle('d-none', !isHidden);
    btn.innerHTML = isHidden
        ? '<i class="bi bi-chevron-up me-1"></i>Collapse'
        : '<i class="bi bi-pencil me-1"></i>Edit';
}

function profileMarkEduDelete(btn, id) {
    const field = document.getElementById('profileEduDeleteIds');
    const ids = field.value ? field.value.split(',') : [];
    ids.push(id);
    field.value = ids.join(',');
    btn.closest('.profile-edu-row').remove();
}

function profileAddEduRow() {
    const noMsg = document.getElementById('profileNoEduMsg');
    if (noMsg) noMsg.remove();
    const container = document.getElementById('profileEduContainer');
    const allRows = container.querySelectorAll('.profile-edu-row');
    const nextIdx = allRows.length;
    const div = document.createElement('div');
    div.className = 'border rounded p-3 mb-3 profile-edu-row';
    div.innerHTML = `
        <input type="hidden" name="edu_id[]" value="">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Qualification</label>
                <input type="text" name="edu_qualification[]" class="form-control form-control-sm" placeholder="e.g. Bachelor of Business Administration">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold small">Institution</label>
                <input type="text" name="edu_institution[]" class="form-control form-control-sm">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small">Year Graduated</label>
                <input type="number" name="edu_year[]" class="form-control form-control-sm" min="1950" max="${new Date().getFullYear()+5}">
            </div>
            <div class="col-md-9">
                <label class="form-label fw-semibold small">Certificate (max 5 files)</label>
                <input type="file" name="edu_certificate[${nextIdx}][]" class="form-control form-control-sm"
                       accept=".jpg,.jpeg,.png,.pdf" multiple>
            </div>
        </div>
        <div class="mt-2 text-end">
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="this.closest('.profile-edu-row').remove()">
                <i class="bi bi-trash me-1"></i>Remove
            </button>
        </div>`;
    container.appendChild(div);
}

// ═══════════════════════════════════════════════════════
// SECTION G — Spouse inline editing
// ═══════════════════════════════════════════════════════
function profToggleSpouseEdit(btn) {
    const card   = btn.closest('.prof-spouse-card');
    const fields = card.querySelector('.prof-spouse-edit-fields');
    const hidden = fields.classList.contains('d-none');
    fields.classList.toggle('d-none', !hidden);
    btn.innerHTML = hidden ? '<i class="bi bi-chevron-up me-1"></i>Close' : '<i class="bi bi-pencil me-1"></i>Edit';
}

function saveProfSpouseEdit(btn) {
    const card = btn.closest('.prof-spouse-card');
    const name = card.querySelector('.sp-p-name').value.trim();
    if (!name) { alert('Please enter the spouse name.'); return; }
    const tel = card.querySelector('.sp-p-tel').value.trim();
    if (!tel) { alert('Please enter the spouse tel no.'); return; }
    card.querySelector('.sp-ph-name').value    = name;
    card.querySelector('.sp-ph-nric').value    = card.querySelector('.sp-p-nric').value;
    card.querySelector('.sp-ph-tel').value     = tel;
    card.querySelector('.sp-ph-occ').value     = card.querySelector('.sp-p-occ').value;
    card.querySelector('.sp-ph-tax').value     = card.querySelector('.sp-p-tax').value;
    card.querySelector('.sp-ph-addr').value    = card.querySelector('.sp-p-addr').value;
    card.querySelector('.sp-ph-working').value  = card.querySelector('.sp-p-working').value;
    card.querySelector('.sp-ph-disabled').value = card.querySelector('.sp-p-disabled').value;
    const occ = card.querySelector('.sp-p-occ').value;
    const displayName = card.querySelector('.sp-display-name');
    const displaySub  = card.querySelector('.sp-display-sub');
    if (displayName) displayName.textContent = name;
    if (displaySub)  displaySub.textContent  = (tel ? 'Tel: ' + tel : '') + (occ ? ' · ' + occ : '');
    card.querySelector('.prof-spouse-edit-fields').classList.add('d-none');
    const editBtn = card.querySelector('button[onclick*="profToggleSpouseEdit"]');
    if (editBtn) editBtn.innerHTML = '<i class="bi bi-pencil me-1"></i>Edit';
}

function markProfSpouseDelete(btn, id) {
    const field = document.getElementById('profDelSpouseIds');
    const ids   = field.value ? field.value.split(',') : [];
    ids.push(id);
    field.value = ids.join(',');
    btn.closest('.prof-spouse-card').remove();
}

function addProfNewSpouse() {
    const name = document.getElementById('profNewSpName').value.trim();
    if (!name) { alert('Please enter the spouse name.'); return; }
    const nric     = document.getElementById('profNewSpNric').value.trim();
    const tel      = document.getElementById('profNewSpTel').value.trim();
    if (!tel) { alert('Please enter the spouse tel no.'); return; }
    const occ      = document.getElementById('profNewSpOcc').value.trim();
    const tax      = document.getElementById('profNewSpTax').value.trim();
    const addr     = document.getElementById('profNewSpAddr').value.trim();
    const working  = document.getElementById('profNewSpWorking').value;
    const disabled = document.getElementById('profNewSpDisabled').value;
    const existingCount = document.querySelectorAll('.prof-spouse-card, .prof-new-spouse-card').length;
    const idx = existingCount;
    const list   = document.getElementById('profNewSpouseList');
    const hidden = document.getElementById('profSpouseNewHidden');
    list.insertAdjacentHTML('beforeend',
        `<div class="border rounded p-3 mb-3 prof-new-spouse-card" style="background:#f8fafc;" data-new-sp-idx="${idx}">
            <div class="d-flex align-items-start justify-content-between">
                <div>
                    <div class="fw-semibold prof-new-sp-display-name">${escH(name)}</div>
                    <div class="text-muted small prof-new-sp-display-sub">${nric ? 'NRIC: ' + escH(nric) + ' · ' : ''}${tel ? 'Tel: ' + escH(tel) : ''}${occ ? ' · ' + escH(occ) : ''}</div>
                </div>
                <div class="d-flex gap-1 ms-2 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            onclick="removeProfNewSpouse(this, ${idx})"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        </div>`
    );
    const fields = {name, nric_no: nric, tel_no: tel, occupation: occ, income_tax_no: tax, address: addr, is_working: working, is_disabled: disabled};
    Object.entries(fields).forEach(([k, v]) => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = `spouses[${idx}][${k}]`; inp.value = v;
        inp.setAttribute('data-prof-new-spouse', idx);
        hidden.appendChild(inp);
    });
    ['profNewSpName','profNewSpNric','profNewSpTel','profNewSpOcc','profNewSpTax','profNewSpAddr'].forEach(id => {
        const el = document.getElementById(id); if (el) el.value = '';
    });
    document.getElementById('profNewSpWorking').value  = '0';
    document.getElementById('profNewSpDisabled').value = '0';
}

function removeProfNewSpouse(btn, idx) {
    btn.closest('.prof-new-spouse-card').remove();
    document.querySelectorAll(`#profSpouseNewHidden input[data-prof-new-spouse="${idx}"]`).forEach(el => el.remove());
}

// ── Helper ─────────────────────────────────────────────────────────────────
function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Marital Status → Spouse Section toggle (profile page) ───────────────
function profileToggleSpouse(val) {
    const section = document.getElementById('profileSpouseSection');
    const star    = document.querySelector('.profile-spouse-required');
    const addPanel = document.getElementById('profAddSpousePanel');
    if (!section) return;
    if (val === 'married') {
        section.style.opacity = '1';
        section.style.pointerEvents = 'auto';
        if (star) star.classList.remove('d-none');
        if (addPanel) { addPanel.style.opacity = '1'; addPanel.style.pointerEvents = 'auto'; }
    } else {
        section.style.opacity = '0.4';
        section.style.pointerEvents = 'none';
        if (star) star.classList.add('d-none');
        if (addPanel) { addPanel.style.opacity = '0.4'; addPanel.style.pointerEvents = 'none'; }
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('profileMaritalStatus');
    if (sel) profileToggleSpouse(sel.value);
});

</script>
@endpush

@endif

@endsection
