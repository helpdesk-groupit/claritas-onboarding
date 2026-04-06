@extends('layouts.app')
@section('title', 'Offboarding Detail')
@section('page-title', 'Offboarding Detail')
@section('content')

@php
    $authUser        = Auth::user();
    $isHrManager     = $authUser->isHrManager() || $authUser->isSuperadmin();
    $canViewContracts= $isHrManager;
    $statusColors = ['active'=>'success','resigned'=>'danger','terminated'=>'danger','contract_ended'=>'secondary'];
    $empName = $employee?->full_name ?? $offboarding->full_name ?? 'Employee';
    $profilePicUrl = $employee?->user?->profile_picture_url
        ?? 'https://ui-avatars.com/api/?name='.urlencode($empName).'&background=dc2626&color=fff&size=200';
@endphp

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="{{ route('hr.offboarding.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    @if($isHrManager)
    <a href="{{ route('hr.offboarding.edit', $offboarding) }}" class="btn btn-sm btn-warning">
        <i class="bi bi-pencil me-1"></i>Edit Record
    </a>
    @endif
    @if($aarf?->acknowledgement_token)
    <a href="{{ route('aarf.view', $aarf->acknowledgement_token) }}" target="_blank" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-file-earmark-check me-1"></i>View AARF
    </a>
    @endif
    @if(!$isHrManager)
    <span class="badge bg-info text-dark ms-auto" style="font-size:12px;">
        <i class="bi bi-eye me-1"></i>View Only
    </span>
    @endif
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
                    <tr><td class="text-muted py-2">Age</td><td class="py-2">{{ $employee?->date_of_birth ? now()->year - $employee->date_of_birth->year : '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Sex</td><td class="py-2">{{ $employee?->sex ? ucfirst($employee->sex) : '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Marital Status</td><td class="py-2">{{ $employee?->marital_status ? ucfirst($employee->marital_status) : '—' }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0" style="font-size:13.5px;">
                    <tr><td class="text-muted py-2" style="width:46%;padding-left:0;">Religion</td><td class="py-2">{{ $employee?->religion ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Race</td><td class="py-2">{{ $employee?->race ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Tel No. (H/phone)</td><td class="py-2">{{ $employee?->personal_contact_number ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Tel No. (House)</td><td class="py-2">{{ $employee?->house_tel_no ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Personal Email</td><td class="py-2">{{ $employee?->personal_email ?? $offboarding->personal_email ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Bank Account No.</td><td class="py-2">{{ $employee?->bank_account_number ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Bank Name</td><td class="py-2">{{ $employee?->bank_name ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">EPF No.</td><td class="py-2">{{ $employee?->epf_no ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Income Tax No.</td><td class="py-2">{{ $employee?->income_tax_no ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">SOCSO No.</td><td class="py-2">{{ $employee?->socso_no ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Disabled Person</td><td class="py-2">{{ $employee?->is_disabled ? 'Yes' : 'No' }}</td></tr>
                    <tr><td class="text-muted py-2 align-top">Residential Address</td><td class="py-2" style="white-space:pre-line;">{{ $employee?->residential_address ?? '—' }}</td></tr>
                    @php $allNric = $employee?->nric_file_paths ?? ($employee?->nric_file_path ? [$employee->nric_file_path] : []); @endphp
                    @if(!empty($allNric))
                    <tr><td class="text-muted py-2">NRIC / Passport File(s)</td>
                        <td class="py-2">
                            @foreach($allNric as $idx => $path)
                            <a href="{{ secure_file_url($path) }}" target="_blank"
                               class="btn btn-sm btn-outline-primary me-1 mb-1" style="padding:2px 8px;font-size:12px;">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>File {{ $idx+1 }}
                            </a>
                            @endforeach
                        </td></tr>
                    @endif
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
                    <tr><td class="text-muted py-2">Last Salary Date</td><td class="py-2">{{ $employee?->last_salary_date?->format('d M Y') ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Reason for Leaving</td><td class="py-2">{{ $offboarding->reason ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Company Email</td><td class="py-2">{{ $offboarding->company_email ?? $employee?->company_email ?? '—' }}</td></tr>
                    <tr><td class="text-muted py-2">Google ID</td><td class="py-2">{{ $employee?->google_id ?? '—' }}</td></tr>
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

{{-- SECTION E — Documents --}}
<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2" style="border-left:4px solid #2563eb;">
        <span class="badge bg-primary rounded-pill fw-bold" style="font-size:12px;min-width:26px;padding:4px 8px;">E</span>
        <h6 class="mb-0 fw-bold"><i class="bi bi-folder2-open me-2 text-primary"></i>Documents</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            {{-- Contract --}}
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
                                <div class="text-muted" style="font-size:11px;">{{ $contract->file_size_label }} &middot; {{ $contract->created_at->format('d M Y') }}@if($contract->notes)<br>{{ $contract->notes }}@endif</div>
                            </div>
                            @if($canViewContracts)
                            <a href="{{ route('employees.contracts.download', [$employee, $contract]) }}"
                               class="btn btn-outline-primary btn-sm flex-shrink-0" style="padding:3px 8px;" title="Download">
                                <i class="bi bi-download" style="font-size:12px;"></i>
                            </a>
                            @else
                            <span class="badge bg-light border text-secondary flex-shrink-0" style="font-size:10px;">
                                <i class="bi bi-lock me-1"></i>HR only
                            </span>
                            @endif
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
                            @if($canViewContracts)
                            <a href="{{ secure_file_url($employee->handbook_path) }}" target="_blank" class="btn btn-outline-success btn-sm" style="padding:2px 7px;">
                                <i class="bi bi-eye" style="font-size:12px;"></i>
                            </a>
                            @else
                            <span class="badge bg-light border text-secondary" style="font-size:10px;">
                                <i class="bi bi-lock me-1"></i>HR only
                            </span>
                            @endif
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
                            @if($canViewContracts)
                            <a href="{{ secure_file_url($employee->orientation_path) }}" target="_blank" class="btn btn-outline-warning btn-sm" style="padding:2px 7px;">
                                <i class="bi bi-eye" style="font-size:12px;"></i>
                            </a>
                            @else
                            <span class="badge bg-light border text-secondary" style="font-size:10px;">
                                <i class="bi bi-lock me-1"></i>HR only
                            </span>
                            @endif
                        </div>
                    @else
                        <p class="text-muted small mb-0">Default orientation slide will be used.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- SECTIONS F–I + Declaration & Consent --}}
@if($employee)
@include('partials.employee-extra-sections-view', ['employee' => $employee, 'showConsent' => true])
@endif

{{-- Edit & Consent Acknowledgement Log --}}
@if($employee && $employee->editLogs->isNotEmpty())
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

@endsection