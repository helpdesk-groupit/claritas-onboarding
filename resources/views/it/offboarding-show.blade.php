@extends('layouts.app')
@section('title', 'Offboarding Detail')
@section('page-title', 'Offboarding Detail')
@section('content')

@php
    $statusColors = ['active'=>'success','resigned'=>'danger','terminated'=>'danger','contract_ended'=>'secondary'];
    $empName = $employee?->full_name ?? $offboarding->full_name ?? 'Employee';
    $profilePicUrl = $employee?->user?->profile_picture_url
        ?? 'https://ui-avatars.com/api/?name='.urlencode($empName).'&background=dc2626&color=fff&size=200';
@endphp

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="{{ route('it.offboarding.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <span class="badge bg-info text-dark align-self-center" style="font-size:12px;">
        <i class="bi bi-eye me-1"></i>View Only — IT cannot edit employee records
    </span>
</div>

{{-- Profile header --}}
<div class="card mb-4">
    <div class="card-body d-flex align-items-center gap-4 py-3">
        <img src="{{ $profilePicUrl }}" alt="{{ $empName }}" class="rounded-circle border shadow-sm flex-shrink-0" style="width:80px;height:80px;object-fit:cover;">
        <div class="flex-fill">
            <h5 class="fw-bold mb-1">{{ $empName }}</h5>
            @if($employee?->preferred_name && $employee->preferred_name !== $employee->full_name)
                <p class="text-muted mb-1 small">Known as: <em>{{ $employee->preferred_name }}</em></p>
            @endif
            <p class="text-muted mb-2 small">{{ $offboarding->designation ?? $employee?->designation ?? '—' }}</p>
            <div class="d-flex flex-wrap gap-1">
                @if($offboarding->company ?? $employee?->company)
                    <span class="badge bg-primary">{{ $offboarding->company ?? $employee->company }}</span>
                @endif
                @if($offboarding->department ?? $employee?->department)
                    <span class="badge bg-secondary">{{ $offboarding->department ?? $employee->department }}</span>
                @endif
                @php $st = $employee?->employment_status ?? 'resigned'; @endphp
                <span class="badge bg-{{ $statusColors[$st] ?? 'danger' }}">{{ ucfirst(str_replace('_',' ',$st)) }}</span>
            </div>
        </div>
        <div class="text-end text-muted small flex-shrink-0 d-none d-md-block">
            @if($offboarding->company_email ?? $employee?->company_email)
                <div><i class="bi bi-envelope me-1"></i>{{ $offboarding->company_email ?? $employee->company_email }}</div>
            @endif
            @if($offboarding->exit_date)
                <div class="mt-1 text-danger fw-semibold"><i class="bi bi-calendar-x me-1"></i>Exit: {{ $offboarding->exit_date->format('d M Y') }}</div>
            @endif
        </div>
    </div>
</div>

{{-- SECTION A — Personal Details --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">A</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-person me-2 text-primary"></i>Personal Details</h6>
    </div>
    <div class="card-body py-3">
        <div class="row g-0">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Full Name</td><td class="fw-semibold py-2">{{ $employee?->full_name ?? $offboarding->full_name ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Preferred Name</td><td class="py-2">{{ $employee?->preferred_name ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Document ID (IC / Passport)</td><td class="py-2">{{ $employee?->official_document_id ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Date of Birth</td><td class="py-2">{{ $employee?->date_of_birth?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Sex</td><td class="py-2">{{ $employee?->sex ? ucfirst($employee->sex) : '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Marital Status</td><td class="py-2">{{ $employee?->marital_status ? ucfirst($employee->marital_status) : '—' }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Religion</td><td class="py-2">{{ $employee?->religion ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Race</td><td class="py-2">{{ $employee?->race ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Contact Number</td><td class="py-2">{{ $employee?->personal_contact_number ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Personal Email</td><td class="py-2">{{ $employee?->personal_email ?? $offboarding->personal_email ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Bank Account No.</td><td class="py-2">{{ $employee?->bank_account_number ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2 align-top">Residential Address</td><td class="py-2" style="white-space:pre-line;">{{ $employee?->residential_address ?? '—' }}</td></tr>
                </table>
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
    <div class="card-body py-3">
        <div class="row g-0">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Employment Type</td><td class="py-2">{{ $employee?->employment_type ? ucfirst($employee->employment_type) : '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Employment Status</td><td class="py-2">
                        @php $st = $employee?->employment_status ?? 'resigned'; @endphp
                        <span class="badge bg-{{ $statusColors[$st] ?? 'danger' }}">{{ ucfirst(str_replace('_',' ',$st)) }}</span>
                    </td></tr>
                    <tr><td class="text-muted py-2">Designation</td><td class="fw-semibold py-2">{{ $offboarding->designation ?? $employee?->designation ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Department</td><td class="py-2">{{ $offboarding->department ?? $employee?->department ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Company</td><td class="py-2">{{ $offboarding->company ?? $employee?->company ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Office Location</td><td class="py-2">{{ $employee?->office_location ?? '—' }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Reporting Manager</td><td class="py-2">{{ $employee?->reporting_manager ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Start Date</td><td class="py-2">{{ $employee?->start_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Exit Date</td><td class="py-2 fw-semibold text-danger">{{ $offboarding->exit_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Company Email</td><td class="py-2">{{ $offboarding->company_email ?? $employee?->company_email ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Google ID</td><td class="py-2">{{ $employee?->google_id ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Assigned PIC</td><td class="py-2">{{ $offboarding->picUser?->name ?? '—' }}</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- SECTION C — Asset Assignment --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">C</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Asset Assignment</h6>
    </div>
    <div class="card-body p-0">
        @if($directAssets->isEmpty())
            <p class="text-muted small p-3 mb-0"><i class="bi bi-info-circle me-1"></i>No assets currently assigned to this employee.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Asset Tag</th>
                        <th>Type</th>
                        <th>Brand / Model</th>
                        <th>Serial No.</th>
                        <th>Assigned Date</th>
                        <th>Condition</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($directAssets as $asset)
                    <tr>
                        <td class="ps-3"><a href="{{ route('assets.show', $asset) }}" class="text-decoration-none"><code>{{ $asset->asset_tag }}</code></a></td>
                        <td>{{ ucfirst(str_replace('_',' ',$asset->asset_type)) }}</td>
                        <td class="text-muted small">{{ trim(($asset->brand ?? '').' '.($asset->model ?? '')) ?: '—' }}</td>
                        <td class="text-muted small">{{ $asset->serial_number ?? '—' }}</td>
                        <td>{{ $asset->asset_assigned_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            @php $cc = ['new'=>'success','good'=>'primary','fair'=>'warning','damaged'=>'danger','not_good'=>'danger','under_maintenance'=>'warning'][$asset->asset_condition ?? ''] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $cc }}">{{ ucfirst(str_replace('_',' ',$asset->asset_condition ?? '—')) }}</span>
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
    <div class="card-body py-3">
        <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
            <tr>
                <td class="text-muted py-2" style="width:22%;padding-left:0;">System Role</td>
                <td class="py-2">
                    @if($employee?->work_role)
                        <span class="badge bg-primary px-3 py-2" style="font-size:13px;">{{ str_replace('_',' ', ucwords($employee->work_role)) }}</span>
                    @else
                        <span class="text-muted">Not assigned</span>
                    @endif
                </td>
            </tr>
            @if($offboarding->remarks ?? $employee?->remarks)
            <tr>
                <td class="text-muted py-2 align-top">Remarks</td>
                <td class="py-2" style="white-space:pre-wrap;">{{ $offboarding->remarks ?? $employee->remarks }}</td>
            </tr>
            @endif
        </table>
    </div>
</div>

{{-- SECTION E — Documents (IT view: contracts locked, handbook/orientation visible) --}}
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">E</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-folder2-open me-2 text-primary"></i>Documents</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            {{-- Contract — locked for IT --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:38px;height:38px;background:#dbeafe;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-file-earmark-text" style="font-size:18px;color:#2563eb;"></i>
                        </div>
                        <div class="fw-semibold small">Employment Contract</div>
                    </div>
                    @if(!$employee || $employee->contracts->isEmpty())
                        <p class="text-muted small mb-0">No contract uploaded yet.</p>
                    @else
                        @foreach($employee->contracts as $contract)
                        <div class="d-flex align-items-center justify-content-between gap-2 py-1 {{ !$loop->last ? 'border-bottom' : '' }}">
                            <div class="text-truncate" style="font-size:12px;">
                                <i class="bi bi-file-earmark-pdf text-danger me-1"></i>
                                <span title="{{ $contract->original_filename }}">{{ $contract->original_filename }}</span>
                                <div class="text-muted" style="font-size:11px;">{{ $contract->created_at->format('d M Y') }}</div>
                            </div>
                            <span class="badge bg-light border text-secondary" style="font-size:10px;white-space:nowrap;">
                                <i class="bi bi-lock me-1"></i>HR only
                            </span>
                        </div>
                        @endforeach
                    @endif
                </div>
            </div>
            {{-- Handbook --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:38px;height:38px;background:#dcfce7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-book" style="font-size:18px;color:#16a34a;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small">Employee Handbook</div>
                            <div class="text-muted" style="font-size:11px;">{{ $employee?->handbook_path ? 'Personalised handbook' : 'Default company handbook' }}</div>
                        </div>
                    </div>
                    @if($employee?->handbook_path)
                        <div class="d-flex align-items-center justify-content-between gap-2 p-2 rounded-2" style="background:#dcfce7;font-size:12px;">
                            <span><i class="bi bi-file-earmark-check-fill text-success me-1"></i>Personalised handbook uploaded</span>
                            <span class="badge bg-light border text-secondary" style="font-size:10px;"><i class="bi bi-lock me-1"></i>HR only</span>
                        </div>
                    @else
                        <p class="text-muted small mb-0">Default company handbook will be used.</p>
                    @endif
                </div>
            </div>
            {{-- Orientation --}}
            <div class="col-md-4">
                <div class="border rounded-3 p-3 h-100 d-flex flex-column gap-2">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <div style="width:38px;height:38px;background:#fef3c7;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class="bi bi-easel" style="font-size:18px;color:#d97706;"></i>
                        </div>
                        <div>
                            <div class="fw-semibold small">Orientation Slide</div>
                            <div class="text-muted" style="font-size:11px;">{{ $employee?->orientation_path ? 'Personalised slide' : 'Default orientation slide' }}</div>
                        </div>
                    </div>
                    @if($employee?->orientation_path)
                        <div class="d-flex align-items-center justify-content-between gap-2 p-2 rounded-2" style="background:#fef3c7;font-size:12px;">
                            <span><i class="bi bi-file-earmark-check-fill text-warning me-1"></i>Personalised slide uploaded</span>
                            <span class="badge bg-light border text-secondary" style="font-size:10px;"><i class="bi bi-lock me-1"></i>HR only</span>
                        </div>
                    @else
                        <p class="text-muted small mb-0">Default orientation slide will be used.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- SECTIONS F–I --}}
@if($employee)
@include('partials.employee-extra-sections-view', ['employee' => $employee, 'showConsent' => false])
@endif

@endsection