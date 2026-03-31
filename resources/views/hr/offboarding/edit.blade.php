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

<form action="{{ route('hr.offboarding.update', $offboarding) }}" method="POST">
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
                    <input type="date" name="date_of_birth" class="form-control"
                           value="{{ old('date_of_birth', $employee?->date_of_birth?->format('Y-m-d')) }}" required>
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
                    <select name="marital_status" class="form-select" required>
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
                    <label class="form-label fw-semibold">Contact Number <span class="text-danger">*</span></label>
                    <input type="text" name="personal_contact_number" class="form-control"
                           value="{{ old('personal_contact_number', $employee?->personal_contact_number) }}" required>
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
                <div class="col-12">
                    <label class="form-label fw-semibold">Residential Address <span class="text-danger">*</span></label>
                    <textarea name="residential_address" class="form-control" rows="2" required>{{ old('residential_address', $employee?->residential_address) }}</textarea>
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
                    <input type="text" name="company" class="form-control"
                           value="{{ old('company', $offboarding->company ?? $employee?->company) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Office Location</label>
                    <input type="text" name="office_location" class="form-control"
                           value="{{ old('office_location', $employee?->office_location) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Reporting Manager</label>
                    <select name="reporting_manager" class="form-select">
                        <option value="">— Select manager —</option>
                        @php $currentMgr = old('reporting_manager', $employee?->reporting_manager); @endphp
                        @if($currentMgr && !$managers->pluck('name')->contains($currentMgr))
                            <option value="{{ $currentMgr }}" selected>{{ $currentMgr }} (current)</option>
                        @endif
                        @foreach($managers as $mgr)
                            <option value="{{ $mgr->name }}" {{ $currentMgr == $mgr->name ? 'selected' : '' }}>
                                {{ $mgr->name }} ({{ ucfirst(str_replace('_',' ',$mgr->role)) }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Start Date</label>
                    <input type="date" name="start_date" class="form-control"
                           value="{{ old('start_date', $employee?->start_date?->format('Y-m-d')) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Exit Date</label>
                    <input type="date" name="exit_date" class="form-control"
                           value="{{ old('exit_date', $offboarding->exit_date?->format('Y-m-d')) }}">
                    <div class="form-text">Changing this resets pending notification emails.</div>
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
                                <a href="{{ asset('storage/'.$employee->handbook_path) }}" target="_blank" class="btn btn-outline-success btn-sm" style="padding:2px 7px;"><i class="bi bi-eye" style="font-size:12px;"></i></a>
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
                                <a href="{{ asset('storage/'.$employee->orientation_path) }}" target="_blank" class="btn btn-outline-warning btn-sm" style="padding:2px 7px;"><i class="bi bi-eye" style="font-size:12px;"></i></a>
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

@endsection

@push('scripts')
<script>
function syncGoogleId(val) {
    const g = document.getElementById('edit_google_id');
    if (g) g.value = val;
}
document.addEventListener('DOMContentLoaded', function () {
    const ce  = document.getElementById('edit_company_email');
    const gid = document.getElementById('edit_google_id');
    if (ce && gid && ce.value && !gid.value) gid.value = ce.value;
});
</script>
@endpush