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
                <span class="badge bg-info text-dark">{{ str_replace('_',' ', ucwords($user->role)) }}</span>
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

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION A — Personal Details (editable)                                   --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">A</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Personal Details</h6>
        </div>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editBiodataModal">
            <i class="bi bi-pencil me-1"></i>{{ $emp?->full_name ? 'Edit' : 'Add Details' }}
        </button>
    </div>
    <div class="card-body py-3">
        @if($emp?->full_name)
        <div class="row g-0">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr>
                        <td class="text-muted py-2" style="width:46%;padding-left:0;">Full Name</td>
                        <td class="fw-semibold py-2">{{ $emp->full_name }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Preferred Name</td>
                        <td class="py-2">{{ $emp->preferred_name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">NRIC / Passport Number</td>
                        <td class="py-2">{{ $emp->official_document_id ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Date of Birth</td>
                        <td class="py-2">{{ $emp->date_of_birth?->format('d M Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Sex</td>
                        <td class="py-2">{{ $emp->sex ? ucfirst($emp->sex) : '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Marital Status</td>
                        <td class="py-2">{{ $emp->marital_status ? ucfirst($emp->marital_status) : '—' }}</td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr>
                        <td class="text-muted py-2" style="width:46%;padding-left:0;">Religion</td>
                        <td class="py-2">{{ $emp->religion ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Race</td>
                        <td class="py-2">{{ $emp->race ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Tel No. (H/phone)</td>
                        <td class="py-2">{{ $emp->personal_contact_number ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Tel No. (House)</td>
                        <td class="py-2">{{ $emp->house_tel_no ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Personal Email</td>
                        <td class="py-2">{{ $emp->personal_email ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Bank Account No.</td>
                        <td class="py-2">{{ $emp->bank_account_number ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Bank Name</td>
                        <td class="py-2">{{ $emp->bank_name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">EPF No.</td>
                        <td class="py-2">{{ $emp->epf_no ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Income Tax No.</td>
                        <td class="py-2">{{ $emp->income_tax_no ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">SOCSO No.</td>
                        <td class="py-2">{{ $emp->socso_no ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Disabled Person</td>
                        <td class="py-2">{{ ($emp->is_disabled ?? false) ? 'Yes' : 'No' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2 align-top">Residential Address</td>
                        <td class="py-2" style="white-space:pre-line;">{{ $emp->residential_address ?? '—' }}</td>
                    </tr>
                    @php $allNric = $emp->nric_file_paths ?? ($emp->nric_file_path ? [$emp->nric_file_path] : []); @endphp
                    @if(!empty($allNric))
                    <tr>
                        <td class="text-muted py-2">NRIC / Passport File(s)</td>
                        <td class="py-2">
                            @foreach($allNric as $idx => $path)
                            <a href="{{ asset('storage/'.$path) }}" target="_blank"
                               class="btn btn-sm btn-outline-primary me-1 mb-1" style="padding:2px 8px;font-size:12px;">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $idx+1 }}
                            </a>
                            @endforeach
                        </td>
                    </tr>
                    @endif
                </table>
            </div>
        </div>
        @else
        <div class="alert alert-warning mb-0">
            <i class="bi bi-exclamation-circle me-2"></i>Personal details not added yet.
            <button class="btn btn-sm btn-warning ms-2"
                    data-bs-toggle="modal" data-bs-target="#editBiodataModal">Add Now</button>
        </div>
        @endif
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
                    <tr>
                        <td class="text-muted py-2" style="width:46%;padding-left:0;">Employment Type</td>
                        <td class="py-2">{{ $emp->employment_type ? ucfirst($emp->employment_type) : '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Designation</td>
                        <td class="fw-semibold py-2">{{ $emp->designation }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Department</td>
                        <td class="py-2">{{ $emp->department ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Company</td>
                        <td class="py-2">{{ $emp->company ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Office Location</td>
                        <td class="py-2">{{ $emp->office_location ?? '—' }}</td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr>
                        <td class="text-muted py-2" style="width:46%;padding-left:0;">Reporting Manager</td>
                        <td class="py-2">{{ $emp->reporting_manager ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Work Email</td>
                        <td class="py-2">{{ $emp->company_email ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted py-2">Start Date</td>
                        <td class="py-2">{{ $emp->start_date?->format('d M Y') ?? '—' }}</td>
                    </tr>
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
                        {{ str_replace('_',' ', ucwords($emp?->work_role ?? $user->role)) }}
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

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- Edit Section A Modal — only personal details are editable by the user     --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
<div class="modal fade" id="editBiodataModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h5 class="modal-title text-white">
                    <i class="bi bi-person me-2"></i>Edit Personal Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('profile.biodata.update') }}" method="POST" enctype="multipart/form-data">
                @csrf @method('PUT')
                <div class="modal-body">
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
                            @if(!empty($existingNric))
                            <div class="mb-1">
                                @foreach($existingNric as $idx => $path)
                                <a href="{{ asset('storage/'.$path) }}" target="_blank"
                                   class="btn btn-sm btn-outline-primary me-1 mb-1" style="font-size:11px;">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $idx+1 }}
                                </a>
                                @endforeach
                            </div>
                            @endif
                            <input type="file" name="nric_files[]" class="form-control" accept=".jpg,.jpeg,.png,.pdf" multiple>
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
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Re-open modal on validation error --}}
@if($errors->any())
<script>
document.addEventListener('DOMContentLoaded', function () {
    @if($errors->hasAny(['full_name','preferred_name','official_document_id','date_of_birth','sex','marital_status','religion','race','personal_contact_number','personal_email','bank_account_number','residential_address','bank_name','epf_no','income_tax_no','socso_no','is_disabled']))
        new bootstrap.Modal(document.getElementById('editBiodataModal')).show();
    @endif
});
</script>
@endif

@if($emp)
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION F — Education & Work History (editable)                           --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@php $eduList = $emp->educationHistories ?? collect(); @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">F</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-mortarboard me-2 text-primary"></i>Education &amp; Work History</h6>
        </div>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editEducationModal">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
    </div>
    <div class="card-body py-3">
        @if($eduList->isEmpty())
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No education history recorded yet.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-borderless align-middle mb-0" style="font-size:13.5px;">
                <thead style="background:#f8fafc;font-size:12px;">
                    <tr><th class="ps-2">Qualification</th><th>Institution</th><th>Year</th><th>Certificate</th></tr>
                </thead>
                <tbody>
                    @foreach($eduList as $e)
                    <tr>
                        <td class="ps-2 fw-semibold">{{ $e->qualification }}</td>
                        <td class="text-muted">{{ $e->institution ?? '—' }}</td>
                        <td>{{ $e->year_graduated ?? '—' }}</td>
                        <td>
                            @if($e->certificate_path)
                                <a href="{{ asset('storage/'.$e->certificate_path) }}" target="_blank"
                                   class="btn btn-outline-primary" style="padding:2px 8px;font-size:11px;">
                                    <i class="bi bi-file-earmark-arrow-down me-1"></i>View
                                </a>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION G — Spouse Information (editable)                                 --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@php $spouses = $emp->spouseDetails ?? collect(); @endphp
<div class="card mb-3" id="profileSpouseSection">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">G</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-people me-2 text-primary"></i>Spouse Information <span class="text-danger profile-spouse-required d-none">*</span></h6>
        </div>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSpouseModal">
            <i class="bi bi-plus-circle me-1"></i>Add
        </button>
    </div>
    <div class="card-body py-3">
        @if($spouses->isEmpty())
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No spouse information recorded.</p>
        @else
        @foreach($spouses as $sp)
        <div class="border rounded p-3 mb-2" style="position:relative;">
            <form action="{{ route('profile.spouse.delete', $sp->id) }}" method="POST" class="d-inline"
                  onsubmit="return confirm('Remove this spouse record?')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"
                        style="position:absolute;top:10px;right:10px;">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
            <div class="row g-0">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                        <tr><td class="text-muted py-1" style="width:46%;padding-left:0;">Name</td><td class="fw-semibold py-1">{{ $sp->name }}</td></tr>
                        <tr><td class="text-muted py-1">NRIC No.</td><td class="py-1">{{ $sp->nric_no ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Tel No.</td><td class="py-1">{{ $sp->tel_no ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Occupation</td><td class="py-1">{{ $sp->occupation ?? '—' }}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                        <tr><td class="text-muted py-1" style="width:46%;padding-left:0;">Income Tax No.</td><td class="py-1">{{ $sp->income_tax_no ?? '—' }}</td></tr>
                        <tr><td class="text-muted py-1">Working</td><td class="py-1">{{ $sp->is_working ? 'Yes' : 'No' }}</td></tr>
                        <tr><td class="text-muted py-1">Disabled</td><td class="py-1">{{ $sp->is_disabled ? 'Yes' : 'No' }}</td></tr>
                        <tr><td class="text-muted py-1 align-top">Address</td><td class="py-1" style="white-space:pre-line;">{{ $sp->address ?? '—' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
        @endforeach
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════ --}}
{{-- SECTION H — Emergency Contacts (editable)                                 --}}
{{-- ══════════════════════════════════════════════════════════════════════════ --}}
@php $contacts = $emp->emergencyContacts->keyBy('contact_order'); @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">H</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Emergency Contacts</h6>
        </div>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editEmergencyModal">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
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
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between" style="border-left:4px solid #2563eb;">
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">I</span>
            <h6 class="mb-0 fw-bold"><i class="bi bi-heart me-2 text-primary"></i>Child Registration (LHDN Tax Relief)</h6>
        </div>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editChildrenModal">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
    </div>
    <div class="card-body py-3">
        @if(!$ch)
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
                    @foreach($catLabels as $key => $label)
                    <tr>
                        <td>{{ $label }}</td>
                        <td class="text-center">{{ $ch->{"cat_{$key}_100"} ?? 0 }}</td>
                        <td class="text-center">{{ $ch->{"cat_{$key}_50"} ?? 0 }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ── Declaration & Consent ── --}}
@if($emp)
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
@endif

{{-- ── Edit & Consent Acknowledgement Log ── --}}
@if($emp && $editLogs->isNotEmpty())
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #94a3b8;">
        <h6 class="mb-0 fw-bold text-muted"><i class="bi bi-clock-history me-2"></i>Edit &amp; Consent Acknowledgement Log</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:12.5px;">
                <thead style="background:#f8fafc;">
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
                        <td class="text-muted" style="font-size:11px;">
                            {{ $log->consent_sent_to_email ?? '—' }}
                        </td>
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

{{-- ═══════════ EDIT MODALS ═══════════ --}}

{{-- Edit Education Modal (list-based) --}}
<div class="modal fade" id="editEducationModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0052CC,#2684FE);">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-mortarboard me-2"></i>Education &amp; Work History</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                {{-- Existing saved entries --}}
                @if($eduList->isNotEmpty())
                <p class="fw-semibold small text-muted mb-2">Saved Qualifications</p>
                <form action="{{ route('profile.education.update') }}" method="POST" enctype="multipart/form-data" id="profileEduSaveForm">
                @csrf
                <input type="hidden" name="edu_delete_ids" id="profileEduDeleteIds" value="">
                <div id="profileEduContainer">
                    @foreach($eduList as $edu)
                    <div class="border rounded p-3 mb-3 profile-edu-row" style="position:relative;">
                        <button type="button" class="btn btn-sm btn-outline-danger"
                                style="position:absolute;top:10px;right:10px;"
                                onclick="profileMarkEduDelete(this, {{ $edu->id }})">
                            <i class="bi bi-trash"></i>
                        </button>
                        <input type="hidden" name="edu_id[]" value="{{ $edu->id }}">
                        <div class="fw-semibold mb-1">{{ $edu->qualification }}</div>
                        <div class="text-muted small mb-2">
                            {{ $edu->institution ?? '' }}
                            {{ $edu->year_graduated ? '· '.$edu->year_graduated : '' }}
                            @if($edu->certificate_path)
                                · <a href="{{ asset('storage/'.$edu->certificate_path) }}" target="_blank">View cert</a>
                            @endif
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" name="edu_qualification[]" class="form-control form-control-sm"
                                       value="{{ $edu->qualification }}" placeholder="Qualification" required>
                            </div>
                            <div class="col-md-6">
                                <input type="text" name="edu_institution[]" class="form-control form-control-sm"
                                       value="{{ $edu->institution }}" placeholder="Institution">
                            </div>
                            <div class="col-md-3">
                                <input type="number" name="edu_year[]" class="form-control form-control-sm"
                                       value="{{ $edu->year_graduated }}" placeholder="Year" min="1950" max="{{ date('Y')+5 }}">
                            </div>
                            <div class="col-md-9">
                                <input type="file" name="edu_certificate[]" class="form-control form-control-sm"
                                       accept=".jpg,.jpeg,.png,.pdf" multiple>
                                <div class="form-text" style="font-size:11px;">Upload new cert (max 5 files, replaces current)</div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                <div class="text-end mb-3">
                    <button type="submit" class="btn btn-success btn-sm px-4">
                        <i class="bi bi-check-circle me-1"></i>Save Changes to Existing
                    </button>
                </div>
                @php $expTotal = $eduList->first()?->years_experience; @endphp
                <div class="row g-3 mb-3">
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
                </form>
                <hr>
                @endif

                {{-- Add new entry panel --}}
                <p class="fw-semibold small text-muted mb-2">Add New Qualification</p>
                <div id="profileNewEduList" class="mb-3"></div>
                <div id="profileNewEduHidden"></div>
                <div class="border rounded p-3" style="background:#f8fafc;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Qualification (Full Name)</label>
                            <input type="text" id="pEduQual" class="form-control form-control-sm"
                                   placeholder="e.g. Bachelor of Business Administration">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Institution</label>
                            <input type="text" id="pEduInst" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Year Graduated</label>
                            <input type="number" id="pEduYear" class="form-control form-control-sm" min="1950" max="{{ date('Y')+5 }}">
                        </div>
                        <div class="col-md-9">
                            <label class="form-label fw-semibold small">Certificate (max 5 files)</label>
                            <input type="file" id="pEduCert" class="form-control form-control-sm"
                                   accept=".jpg,.jpeg,.png,.pdf" multiple>
                        </div>
                    </div>
                    <div class="mt-2 text-end">
                        <button type="button" class="btn btn-primary btn-sm" onclick="pAddEduEntry()">
                            <i class="bi bi-plus-circle me-1"></i>Add to List
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <form action="{{ route('profile.education.update') }}" method="POST"
                      enctype="multipart/form-data" id="profileNewEduForm">
                @csrf
                <input type="hidden" name="edu_delete_ids" value="">
                <div id="profileNewEduFormFields"></div>
                <button type="submit" class="btn btn-primary" id="profileNewEduSubmit" style="display:none;">
                    <i class="bi bi-check-circle me-1"></i>Save New Entries
                </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Add Spouse Modal (adds a new record each time) --}}
<div class="modal fade" id="editSpouseModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0052CC,#2684FE);">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-people me-2"></i>Add Spouse Information</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('profile.spouse.update') }}" method="POST">
            @csrf
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="spouse_name" class="form-control" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">NRIC No.</label>
                        <input type="text" name="spouse_nric_no" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Tel No.</label>
                        <input type="text" name="spouse_tel_no" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Occupation</label>
                        <input type="text" name="spouse_occupation" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Income Tax No.</label>
                        <input type="text" name="spouse_income_tax_no" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Address</label>
                        <textarea name="spouse_address" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Working?</label>
                        <select name="spouse_is_working" class="form-select">
                            <option value="0">No</option><option value="1">Yes</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Disabled?</label>
                        <select name="spouse_is_disabled" class="form-select">
                            <option value="0">No</option><option value="1">Yes</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>Add Spouse
                </button>
            </div>
            </form>
        </div>
    </div>
</div>
{{-- Edit Emergency Contacts Modal (list-based) --}}
<div class="modal fade" id="editEmergencyModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0052CC,#2684FE);">
                <h5 class="modal-title text-white fw-bold"><i class="bi bi-telephone-fill me-2"></i>Emergency Contacts</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('profile.emergency.update') }}" method="POST" id="profileEmergencyForm">
            @csrf
            <div class="modal-body">
                {{-- Existing contacts shown as cards --}}
                <div id="pEcList">
                @foreach([1,2] as $n)
                @php $contact = $contacts[$n] ?? null; @endphp
                @if($contact)
                <div class="border rounded p-3 mb-2 pec-card" style="position:relative;" data-order="{{ $n }}">
                    <button type="button" class="btn btn-sm btn-outline-danger"
                            style="position:absolute;top:10px;right:10px;"
                            onclick="pRemoveEcCard(this, {{ $n }})">
                        <i class="bi bi-trash"></i>
                    </button>
                    <input type="hidden" name="emergency[{{ $n }}][name]"         value="{{ $contact->name }}">
                    <input type="hidden" name="emergency[{{ $n }}][tel_no]"       value="{{ $contact->tel_no }}">
                    <input type="hidden" name="emergency[{{ $n }}][relationship]" value="{{ $contact->relationship }}">
                    <div class="fw-semibold">Contact {{ $n }}: {{ $contact->name }}</div>
                    <div class="text-muted small">{{ $contact->tel_no }} · {{ $contact->relationship }}</div>
                </div>
                @endif
                @endforeach
                </div>

                <p class="text-muted small mt-2 mb-3" id="pEcCountMsg">
                    <i class="bi bi-info-circle me-1"></i>
                    <span id="pEcCountText">{{ $contacts->count() }} of 2 contacts saved.</span>
                </p>

                {{-- Add new contact input panel --}}
                <div class="border rounded p-3" style="background:#f8fafc;" id="pEcInputPanel">
                    <p class="fw-semibold small text-muted mb-2">Add / Replace Contact</p>
                    <div class="row g-3">
                        <div class="col-md-1">
                            <label class="form-label fw-semibold small">No.</label>
                            <select id="pEcOrder" class="form-select form-select-sm">
                                <option value="1">1</option>
                                <option value="2">2</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                            <input type="text" id="pEcName" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Tel No. <span class="text-danger">*</span></label>
                            <input type="text" id="pEcTel" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Relationship <span class="text-danger">*</span></label>
                            <select id="pEcRel" class="form-select form-select-sm">
                                <option value="">— Select —</option>
                                @foreach(['Spouse','Parent','Sibling','Child','Friend','Colleague','Other'] as $rel)
                                <option value="{{ $rel }}">{{ $rel }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mt-2 text-end">
                        <button type="button" class="btn btn-primary btn-sm" onclick="pAddEcEntry()">
                            <i class="bi bi-plus-circle me-1"></i>Add to List
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>Save Contacts
                </button>
            </div>
            </form>
        </div>
    </div>
</div>
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

// ── Existing edu record inline-delete (for saved entries form) ────────────
function profileMarkEduDelete(btn, id) {
    const field = document.getElementById('profileEduDeleteIds');
    const ids = field.value ? field.value.split(',') : [];
    ids.push(id); field.value = ids.join(',');
    btn.closest('.profile-edu-row').remove();
}

// ═══════════════════════════════════════════════════════
// EDUCATION — Add new entries list
// ═══════════════════════════════════════════════════════
let pEduEntries = [];

function pAddEduEntry() {
    const qual = document.getElementById('pEduQual').value.trim();
    if (!qual) { alert('Please enter a qualification name.'); return; }
    const entry = {
        qualification: qual,
        institution:   document.getElementById('pEduInst').value.trim(),
        year:          document.getElementById('pEduYear').value.trim(),
        certFiles:     Array.from(document.getElementById('pEduCert').files),
    };
    pEduEntries.push(entry);
    pRenderEduList();
    ['pEduQual','pEduInst','pEduYear'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('pEduCert').value = '';
}

function pRemoveEduEntry(i) {
    pEduEntries.splice(i, 1);
    pRenderEduList();
}

function pRenderEduList() {
    const list = document.getElementById('profileNewEduList');
    if (!list) return;
    list.innerHTML = '';
    pEduEntries.forEach((e, i) => {
        const certNames = e.certFiles.map(f => f.name).join(', ') || '—';
        list.innerHTML += `
        <div class="border rounded p-2 mb-2" style="position:relative;background:#fff;">
            <button type="button" class="btn btn-sm btn-outline-danger"
                    style="position:absolute;top:8px;right:8px;"
                    onclick="pRemoveEduEntry(${i})"><i class="bi bi-trash"></i></button>
            <div class="fw-semibold small">${escH(e.qualification)}</div>
            <div class="text-muted" style="font-size:12px;">
                ${e.institution ? e.institution+' · ' : ''}
                ${e.year ? e.year : ''}
                ${e.certFiles.length ? ' · '+e.certFiles.length+' file(s)' : ''}
            </div>
        </div>`;
    });
    pSyncEduHidden();
}

function pSyncEduHidden() {
    const hidden = document.getElementById('profileNewEduFormFields');
    const formHidden = document.getElementById('profileNewEduHidden');
    if (!hidden && !formHidden) return;

    let html = '';
    pEduEntries.forEach((e) => {
        html += `<input type="hidden" name="edu_qualification[]" value="${escH(e.qualification)}">`;
        html += `<input type="hidden" name="edu_institution[]"   value="${escH(e.institution)}">`;
        html += `<input type="hidden" name="edu_year[]"          value="${escH(e.year)}">`;
        html += `<input type="hidden" name="edu_experience[]"    value="${escH(e.experience)}">`;
    });
    if (hidden) hidden.innerHTML = html;
    if (formHidden) formHidden.innerHTML = html;

    // Show/hide save button
    const btn = document.getElementById('profileNewEduSubmit');
    if (btn) btn.style.display = pEduEntries.length > 0 ? '' : 'none';

    // Combine all cert files into a single multi-file input
    const allFiles = pEduEntries.flatMap(e => e.certFiles);
    const form = document.getElementById('profileNewEduForm');
    if (form && allFiles.length > 0) {
        let inp = form.querySelector('input[data-pEduCerts]');
        if (!inp) {
            inp = document.createElement('input');
            inp.type = 'file'; inp.name = 'edu_certificate[]'; inp.multiple = true;
            inp.setAttribute('data-pEduCerts', '1');
            inp.style.display = 'none';
            form.appendChild(inp);
        }
        const dt = new DataTransfer();
        allFiles.forEach(f => dt.items.add(f));
        inp.files = dt.files;
    }
}

}

// ═══════════════════════════════════════════════════════
// EMERGENCY CONTACTS — list-based
// ═══════════════════════════════════════════════════════
// Track which slot numbers are in the list (1 or 2)
let pEcCurrentOrders = new Set(
    Array.from(document.querySelectorAll('.pec-card')).map(el => parseInt(el.dataset.order))
);

function pAddEcEntry() {
    const order = parseInt(document.getElementById('pEcOrder').value);
    const name  = document.getElementById('pEcName').value.trim();
    const tel   = document.getElementById('pEcTel').value.trim();
    const rel   = document.getElementById('pEcRel').value.trim();
    if (!name || !tel || !rel) { alert('Please fill in Name, Tel No., and Relationship.'); return; }

    // Remove existing card for same order slot if present
    const existing = document.querySelector(`.pec-card[data-order="${order}"]`);
    if (existing) existing.remove();

    const list = document.getElementById('pEcList');
    const div = document.createElement('div');
    div.className = 'border rounded p-3 mb-2 pec-card';
    div.dataset.order = order;
    div.style.position = 'relative';
    div.innerHTML = `
        <button type="button" class="btn btn-sm btn-outline-danger"
                style="position:absolute;top:8px;right:8px;"
                onclick="pRemoveEcCard(this, ${order})"><i class="bi bi-trash"></i></button>
        <input type="hidden" name="emergency[${order}][name]"         value="${escH(name)}">
        <input type="hidden" name="emergency[${order}][tel_no]"       value="${escH(tel)}">
        <input type="hidden" name="emergency[${order}][relationship]" value="${escH(rel)}">
        <div class="fw-semibold">Contact ${order}: ${escH(name)}</div>
        <div class="text-muted small">${escH(tel)} · ${escH(rel)}</div>`;
    list.appendChild(div);

    pEcCurrentOrders.add(order);
    pUpdateEcCount();

    document.getElementById('pEcName').value = '';
    document.getElementById('pEcTel').value  = '';
    document.getElementById('pEcRel').value  = '';
}

function pRemoveEcCard(btn, order) {
    btn.closest('.pec-card').remove();
    pEcCurrentOrders.delete(order);
    pUpdateEcCount();
}

function pUpdateEcCount() {
    const count = document.querySelectorAll('.pec-card').length;
    const txt = document.getElementById('pEcCountText');
    if (txt) txt.textContent = `${count} of 2 contacts saved.`;
}

// ── Helper ─────────────────────────────────────────────────────────────────
function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Marital Status → Spouse Section toggle (profile page) ───────────────
function profileToggleSpouse(val) {
    const section = document.getElementById('profileSpouseSection');
    const star    = document.querySelector('.profile-spouse-required');
    if (!section) return;
    if (val === 'married') {
        section.style.opacity = '1';
        section.style.pointerEvents = 'auto';
        if (star) star.classList.remove('d-none');
    } else {
        section.style.opacity = '0.4';
        section.style.pointerEvents = 'none';
        if (star) star.classList.add('d-none');
    }
}
// On modal open, reflect the current saved marital status
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('profileMaritalStatus');
    if (sel) profileToggleSpouse(sel.value);

    // Also re-run when the biodata modal is opened (in case marital status changes live)
    const biodataModal = document.getElementById('editBiodataModal');
    if (biodataModal) {
        biodataModal.addEventListener('shown.bs.modal', function() {
            const s = document.getElementById('profileMaritalStatus');
            if (s) profileToggleSpouse(s.value);
        });
    }
});

</script>
@endpush

@endif

@endsection