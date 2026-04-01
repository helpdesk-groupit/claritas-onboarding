@extends('layouts.app')
@section('title','Edit Asset')
@section('page-title','Edit Asset')
@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ $asset->asset_condition === 'not_good' ? route('assets.disposed.show', $asset) : route('assets.show', $asset) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    @if(Auth::user()->canEditAsset() && ($asset->assigned_employee_id || $asset->status === 'assigned'))
    @php $assignedName = $asset->resolvedAssigneeName(); @endphp
    <button type="button" class="btn btn-sm btn-danger"
            data-bs-toggle="modal" data-bs-target="#releaseModal">
        <i class="bi bi-person-dash me-1"></i>Release
    </button>
    @endif
</div>
@php $canAll = Auth::user()->canEditAllAssetSections(); @endphp

<form action="{{ route('assets.update',$asset) }}" method="POST" enctype="multipart/form-data">
@csrf @method('PUT')
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
@if(!$canAll)<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>As IT Executive, you can edit Sections A, B, and C only.</div>@endif

{{-- Section A --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3"><div class="section-header mb-0"><h6><i class="bi bi-tag me-2 text-primary"></i>Section A — Asset Identification</h6></div></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-2"><label class="form-label fw-semibold">Asset Tag <span class="text-danger">*</span></label>
            <input type="text" name="asset_tag" id="editAssetTagInput"
                   class="form-control @error('asset_tag') is-invalid @enderror"
                   value="{{ old('asset_tag',$asset->asset_tag) }}"
                   oninput="syncAssetNameEdit(this.value)" required>
            @error('asset_tag')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-2"><label class="form-label fw-semibold">Asset Type <span class="text-danger">*</span></label>
            <select name="asset_type" class="form-select" required>
                @foreach(['laptop'=>'Laptop','monitor'=>'Monitor','converter'=>'Converter','phone'=>'Company Phone','sim_card'=>'SIM Card','access_card'=>'Access Card','other'=>'Other'] as $v=>$l)
                    <option value="{{ $v }}" {{ old('asset_type',$asset->asset_type)==$v?'selected':'' }}>{{ $l }}</option>
                @endforeach
            </select></div>
        <div class="col-md-2"><label class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
            <select name="brand" class="form-select" required>
                @foreach(['Dell','HP','Lenovo','Apple','Asus','Acer','Maxis','Internal','Anker','Other'] as $b)
                    <option value="{{ $b }}" {{ old('brand',$asset->brand)==$b?'selected':'' }}>{{ $b }}</option>
                @endforeach
            </select></div>
        <div class="col-md-3"><label class="form-label fw-semibold">Model <span class="text-danger">*</span></label>
            <input type="text" name="model" class="form-control" value="{{ old('model',$asset->model) }}" required></div>
        <div class="col-md-3"><label class="form-label fw-semibold">Asset Name</label>
            <input type="text" name="asset_name" id="editAssetNameInput"
                   class="form-control" value="{{ old('asset_name',$asset->asset_name) }}"
                   placeholder="Auto-filled from Asset Tag"></div>
        <div class="col-md-3"><label class="form-label fw-semibold">Serial Number <span class="text-danger">*</span></label>
            <input type="text" name="serial_number" class="form-control @error('serial_number') is-invalid @enderror" value="{{ old('serial_number',$asset->serial_number) }}" required>
            @error('serial_number')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        </div>
</div>

{{-- Section B --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3"><div class="section-header mb-0"><h6><i class="bi bi-cpu me-2 text-primary"></i>Section B — Specification</h6></div></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label fw-semibold">Processor</label><input type="text" name="processor" class="form-control" value="{{ old('processor',$asset->processor) }}"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">RAM Size</label><input type="text" name="ram_size" class="form-control" value="{{ old('ram_size',$asset->ram_size) }}"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Storage</label><input type="text" name="storage" class="form-control" value="{{ old('storage',$asset->storage) }}"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Operating System</label><input type="text" name="operating_system" class="form-control" value="{{ old('operating_system',$asset->operating_system) }}"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Screen Size</label><input type="text" name="screen_size" class="form-control" value="{{ old('screen_size',$asset->screen_size) }}"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Others</label><input type="text" name="spec_others" class="form-control" value="{{ old('spec_others',$asset->spec_others) }}"></div>
    </div></div>
</div>

{{-- Section C --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3"><div class="section-header mb-0"><h6><i class="bi bi-receipt me-2 text-primary"></i>Section C — Procurement</h6></div></div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label fw-semibold">Ownership Type <span class="text-danger">*</span></label>
            <div class="d-flex gap-3 mt-1">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="ownership_type" id="edit_own_company" value="company"
                        {{ old('ownership_type', $asset->ownership_type ?? 'company') === 'company' ? 'checked' : '' }}
                        onchange="toggleOwnership(this.value)">
                    <label class="form-check-label fw-semibold" for="edit_own_company"><i class="bi bi-building me-1 text-primary"></i>Company Owned</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="ownership_type" id="edit_own_rental" value="rental"
                        {{ old('ownership_type', $asset->ownership_type) === 'rental' ? 'checked' : '' }}
                        onchange="toggleOwnership(this.value)">
                    <label class="form-check-label fw-semibold" for="edit_own_rental"><i class="bi bi-truck me-1 text-warning"></i>Rental / Leased</label>
                </div>
            </div>
        </div>
        <div id="companyFields" class="row g-3" style="{{ old('ownership_type', $asset->ownership_type ?? 'company') === 'rental' ? 'display:none;' : '' }}">
            <div class="col-md-4"><label class="form-label fw-semibold">Company Name</label>
                <select name="company_name" class="form-select">
                    <option value="">— Select Company —</option>
                    @foreach($registeredCompanies as $rc)
                    <option value="{{ $rc->name }}" {{ old('company_name', $asset->company_name) == $rc->name ? 'selected' : '' }}>
                        {{ $rc->name }}
                    </option>
                    @endforeach
                </select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Vendor / Supplier</label>
                <input type="text" name="purchase_vendor" class="form-control" value="{{ old('purchase_vendor',$asset->purchase_vendor) }}"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Purchase Cost (RM)</label>
                <input type="number" name="purchase_cost" class="form-control" value="{{ old('purchase_cost',$asset->purchase_cost) }}" step="0.01"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Purchase Date</label>
                <input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date',$asset->purchase_date?->format('Y-m-d')) }}"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Warranty Expiry</label>
                <input type="date" name="warranty_expiry_date" class="form-control" value="{{ old('warranty_expiry_date',$asset->warranty_expiry_date?->format('Y-m-d')) }}"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Invoice (PDF) {{ $asset->invoice_document ? '— current file exists' : '' }}</label>
                <input type="file" name="invoice_document" class="form-control" accept=".pdf"></div>
        </div>
        <div id="rentalFields" class="row g-3" style="{{ old('ownership_type', $asset->ownership_type) === 'rental' ? '' : 'display:none;' }}">
            <div class="col-md-4"><label class="form-label fw-semibold">Rental Vendor</label>
                <input type="text" name="rental_vendor" class="form-control" value="{{ old('rental_vendor',$asset->rental_vendor) }}" placeholder="Vendor / leasing company"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Vendor Contact</label>
                <input type="text" name="rental_vendor_contact" class="form-control" value="{{ old('rental_vendor_contact',$asset->rental_vendor_contact) }}" placeholder="Phone or email"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Monthly Cost (RM)</label>
                <input type="number" name="rental_cost_per_month" class="form-control" value="{{ old('rental_cost_per_month',$asset->rental_cost_per_month) }}" step="0.01"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Rental Start Date</label>
                <input type="date" name="rental_start_date" class="form-control" value="{{ old('rental_start_date',$asset->rental_start_date?->format('Y-m-d')) }}"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Rental End Date</label>
                <input type="date" name="rental_end_date" class="form-control" value="{{ old('rental_end_date',$asset->rental_end_date?->format('Y-m-d')) }}"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Contract Reference</label>
                <input type="text" name="rental_contract_reference" class="form-control" value="{{ old('rental_contract_reference',$asset->rental_contract_reference) }}" placeholder="Contract / PO number"></div>
            <div class="col-md-3"><label class="form-label fw-semibold">Invoice (PDF) {{ $asset->invoice_document ? '— current exists' : '' }}</label>
                <input type="file" name="invoice_document" class="form-control" accept=".pdf"></div>
        </div>
    </div>
</div>

@if($canAll)
{{-- Section D — hidden for decommissioned (not_good) assets --}}
@if($asset->asset_condition !== 'not_good')
<div class="card mb-3">
    <div class="card-header bg-white py-3"><div class="section-header mb-0"><h6><i class="bi bi-person-check me-2 text-primary"></i>Section D — Assignment</h6></div></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-4"><label class="form-label fw-semibold">Assigned Employee</label>
            @php
                $resolvedEmployeeId   = old('assigned_employee_id', $asset->assigned_employee_id);
                $autoAssignedName     = null; // name from onboarding, when employee not yet activated

                if (!$resolvedEmployeeId) {
                    // Auto-assigned via onboarding: look up AssetAssignment → Onboarding → PersonalDetail
                    $activeAssignment = \App\Models\AssetAssignment::with('onboarding.personalDetail')
                        ->where('asset_inventory_id', $asset->id)
                        ->where('status', 'assigned')
                        ->whereNotNull('onboarding_id')
                        ->first();

                    if ($activeAssignment) {
                        // Try to find activated employee first
                        $assignedEmp = \App\Models\Employee::where('onboarding_id', $activeAssignment->onboarding_id)->first();
                        if ($assignedEmp) {
                            $resolvedEmployeeId = $assignedEmp->id;
                        } else {
                            // Employee not yet activated — show name from personal detail as read-only
                            $autoAssignedName = $activeAssignment->onboarding?->personalDetail?->full_name;
                        }
                    }
                }
            @endphp

            @if($autoAssignedName)
                {{-- New hire not yet activated — show name as read-only, keep hidden input empty so no override --}}
                <input type="text" class="form-control" style="background:#f8fafc;"
                       value="{{ $autoAssignedName }} (pending activation)" readonly>
                <input type="hidden" name="assigned_employee_id" value="">
                <div class="form-text text-muted small">
                    <i class="bi bi-info-circle me-1"></i>Auto-assigned via onboarding. Employee activates on start date.
                </div>
            @else
                @php
                    $editEmpDisplayLabel = '';
                    foreach ($employees as $emp) {
                        if ($emp->id == $resolvedEmployeeId) {
                            $eName  = $emp->onboarding?->personalDetail?->full_name ?? $emp->full_name ?? 'Employee #'.$emp->id;
                            $eEmail = $emp->company_email ?? $emp->personal_email ?? '';
                            $editEmpDisplayLabel = $eEmail ? "{$eName} — {$eEmail}" : $eName;
                            break;
                        }
                    }
                @endphp
                <div class="position-relative">
                    <input type="text" id="editEmpSearchInput" class="form-control"
                           placeholder="Search or select employee..."
                           autocomplete="off"
                           value="{{ $editEmpDisplayLabel }}"
                           oninput="editFilterEmp(this.value)"
                           onfocus="editShowEmpList()"
                           onblur="setTimeout(editHideEmpList, 200)">
                    <ul id="editEmpList"
                        class="list-unstyled border rounded bg-white shadow-sm position-absolute mb-0"
                        style="z-index:1055;max-height:200px;overflow-y:auto;display:none;top:100%;left:0;min-width:100%;width:max-content;max-width:480px;">
                        <li>
                            <button type="button" class="dropdown-item"
                                    onmousedown="editSelectEmp('', '— Not Assigned —')">
                                — Not Assigned —
                            </button>
                        </li>
                        @foreach($employees as $emp)
                        @php
                            $eName  = $emp->onboarding?->personalDetail?->full_name ?? $emp->full_name ?? 'Employee #'.$emp->id;
                            $eEmail = $emp->company_email ?? $emp->personal_email ?? '';
                            $eLabel = $eEmail ? "{$eName} — {$eEmail}" : $eName;
                        @endphp
                        <li>
                            <button type="button" class="dropdown-item"
                                    onmousedown="editSelectEmp('{{ $emp->id }}', {{ json_encode($eLabel) }})"
                                    data-empname="{{ strtolower($eLabel) }}"
                                    style="white-space:normal;word-break:break-word;">
                                {{ $eLabel }}
                            </button>
                        </li>
                        @endforeach
                    </ul>
                    <input type="hidden" name="assigned_employee_id" id="editAssignedEmployeeId"
                           value="{{ $resolvedEmployeeId ?? '' }}">
                </div>
                <div class="form-text text-muted small">Type to search by name or email.</div>
            @endif
        </div>
        <div class="col-md-4"><label class="form-label fw-semibold">Assigned Date</label><input type="date" name="asset_assigned_date" id="assignedDate" class="form-control" value="{{ old('asset_assigned_date',$asset->asset_assigned_date?->format('Y-m-d')) }}"></div>
        <div class="col-md-4"><label class="form-label fw-semibold">Expected Return</label><input type="date" name="expected_return_date" class="form-control" value="{{ old('expected_return_date',$asset->expected_return_date?->format('Y-m-d')) }}"></div>
        <div class="col-md-3">
            <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
            <select name="status" id="assetStatus" class="form-select" required>
                @php
                    $currentStatus = old('status', $asset->status);
                    $currentStatus = in_array($currentStatus, ['available','unavailable']) ? $currentStatus : 'available';
                @endphp
                <option value="available"   {{ $currentStatus === 'available'   ? 'selected' : '' }}>Available</option>
                <option value="unavailable" {{ $currentStatus === 'unavailable' ? 'selected' : '' }}>Unavailable</option>
            </select>
            <div class="form-text text-muted small">Auto-set by Section E condition.</div>
        </div>
    </div></div>
</div>
@else
{{-- Not Good asset: no Section D rendered, but still submit status=unavailable --}}
<input type="hidden" name="status" value="unavailable">
@endif

{{-- Section E — Condition drives status automatically --}}
<div class="card mb-3">
    <div class="card-header bg-white py-3"><div class="section-header mb-0"><h6><i class="bi bi-clipboard-check me-2 text-primary"></i>Section E — Condition & Status</h6></div></div>
    <div class="card-body"><div class="row g-3">

        {{-- Condition: Good / Not Good / Under Maintenance --}}
        <div class="col-md-3">
            <label class="form-label fw-semibold">Condition <span class="text-danger">*</span></label>
            <select name="asset_condition" id="assetCondition" class="form-select" required onchange="syncStatusFromCondition(this.value)">
                @php
                    // Map any legacy/new values → current three-option set
                    $cond = old('asset_condition', $asset->asset_condition);
                    if ($cond === 'under_maintenance') $cond = 'under_maintenance'; // already correct
                    elseif ($cond === 'not_good' || $cond === 'damaged') $cond = 'not_good';
                    elseif (in_array($cond, ['new', 'good', 'fair'])) $cond = 'good';
                    else $cond = 'good'; // safe fallback
                @endphp
                <option value="good"               {{ $cond === 'good'               ? 'selected' : '' }}>Good</option>
                <option value="not_good"           {{ $cond === 'not_good'           ? 'selected' : '' }}>Not Good</option>
                <option value="under_maintenance"  {{ $cond === 'under_maintenance'  ? 'selected' : '' }}>Under Maintenance</option>
            </select>
            <div class="form-text">
                Good → Available &nbsp;|&nbsp; Not Good → Disposed &nbsp;|&nbsp; Under Maintenance → Unavailable
            </div>
        </div>

        {{-- Maintenance Status: Pending / In Progress / Done — shown only when Under Maintenance --}}
        <div class="col-md-3" id="maintenanceStatusWrap" style="{{ $cond === 'under_maintenance' ? '' : 'display:none;' }}">
            <label class="form-label fw-semibold">Maintenance Status <span class="text-danger">*</span></label>
            <select name="maintenance_status" id="maintenanceStatus" class="form-select">
                @php $maint = old('maintenance_status', $asset->maintenance_status ?? 'pending'); @endphp
                <option value="pending"     {{ $maint === 'pending'     ? 'selected' : '' }}>Pending</option>
                <option value="in_progress" {{ $maint === 'in_progress' ? 'selected' : '' }}>In Progress</option>
                <option value="done"        {{ $maint === 'done'        ? 'selected' : '' }}>Done</option>
            </select>
        </div>

        {{-- Decommission Reason — required when condition = Not Good --}}
        <div class="col-md-6" id="decommissionReasonWrap" style="{{ $cond === 'not_good' ? '' : 'display:none;' }}">
            <label class="form-label fw-semibold">Decommission Reason <span class="text-danger">*</span></label>
            @php
                $existingReason = old('decommission_reason',
                    \App\Models\DisposedAsset::where('asset_inventory_id', $asset->id)->value('reason') ?? '');
            @endphp
            <input type="text" name="decommission_reason" id="decommissionReason"
                   class="form-control"
                   value="{{ $existingReason }}"
                   placeholder="e.g. Screen cracked beyond repair, Water damage, Hardware failure..."
                   {{ $cond === 'not_good' ? 'required' : '' }}>
            <div class="form-text">This reason will be shown in the Decommissioning Assets table.</div>
        </div>

        <div class="col-md-3"><label class="form-label fw-semibold">Last Maintenance Date</label><input type="date" name="last_maintenance_date" class="form-control" value="{{ old('last_maintenance_date',$asset->last_maintenance_date?->format('Y-m-d')) }}"></div>
        <div class="col-12">
            <label class="form-label fw-semibold">Asset Photos <span class="text-muted fw-normal">(up to 15 photos, JPG/PNG)</span></label>
            {{-- Existing photos with individual remove buttons --}}
            @php $existingPhotos = $asset->asset_photos ?? []; @endphp
            <input type="hidden" name="photo_keep_submitted" value="1">
            @if(!empty($existingPhotos))
            <div class="d-flex flex-wrap gap-2 mb-3" id="photoExistingList">
                @foreach($existingPhotos as $idx => $photo)
                <div class="d-flex flex-column align-items-center gap-1 photo-keep-item" id="photoItem_{{ $idx }}" style="width:80px;">
                    <img src="{{ asset('storage/'.$photo) }}"
                         style="width:80px;height:70px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">
                    <input type="hidden" name="photo_keep_paths[]" value="{{ $photo }}" class="photo-keep-input">
                    <button type="button" class="btn btn-outline-danger btn-sm w-100 py-0"
                            style="font-size:11px;" onclick="removeExistingPhoto(this)" title="Remove">
                        <i class="bi bi-x me-1"></i>Remove
                    </button>
                </div>
                @endforeach
            </div>
            <div class="form-text text-muted mb-2">{{ count($existingPhotos) }} photo(s) uploaded. Click Remove to delete a photo on save.</div>
            @endif
            {{-- New photo upload --}}
            <div class="d-flex gap-2 mb-1" style="max-width:480px;">
                <input type="file" id="photoNewFileInput" class="form-control" accept=".jpg,.jpeg,.png" style="max-width:340px;">
                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" onclick="addPhotoFile()">
                    <i class="bi bi-upload me-1"></i>Add
                </button>
            </div>
            <div id="photoNewList" class="d-flex flex-wrap gap-2 mb-1"></div>
            <div id="photoNewHidden"></div>
            <div class="form-text text-muted">Select a photo then click Add. New photos are added to remaining existing photos (max 15 total).</div>
            @error('asset_photos')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            @error('asset_photos.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2"
                      placeholder="Any notes about this asset...">{{ old('notes', $asset->notes) }}</textarea>
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Remarks / Assignment Log</label>
            @if($asset->remarks)
            <div class="bg-light border rounded p-3 mb-0"
                 style="font-size:12px;font-family:monospace;white-space:pre-wrap;max-height:160px;overflow-y:auto;">{{ $asset->remarks }}</div>
            @else
            <p class="text-muted small mb-0">No assignment history recorded yet.</p>
            @endif
        </div>
    </div></div>
</div>
@endif

<div class="d-flex gap-2 justify-content-end">
    <a href="{{ $asset->asset_condition === 'not_good' ? route('assets.disposed.show', $asset) : route('assets.show', $asset) }}" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-circle me-2"></i>Save Changes</button>
</div>
</form>

@push('scripts')
<script>
function toggleOwnership(value) {
    const rentalFields  = document.getElementById('rentalFields');
    const companyFields = document.getElementById('companyFields');
    if (rentalFields)  rentalFields.style.display  = value === 'rental'  ? '' : 'none';
    if (companyFields) companyFields.style.display = value === 'company' ? '' : 'none';
}

/**
 * When condition changes, auto-set the Section A status field:
 *   Good              → Available
 *   Under Maintenance → Unavailable
 *   Not Good          → Unavailable (will be disposed on save)
 * Also show/hide the Maintenance Status dropdown.
 */
function onEmployeeChange(employeeId) {
    const dateField = document.getElementById('assignedDate');
    if (!dateField) return;
    if (employeeId) {
        if (!dateField.value) {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm   = String(today.getMonth() + 1).padStart(2, '0');
            const dd   = String(today.getDate()).padStart(2, '0');
            dateField.value = `${yyyy}-${mm}-${dd}`;
        }
    } else {
        const originalDate = '{{ $asset->asset_assigned_date?->format('Y-m-d') ?? '' }}';
        if (!originalDate) dateField.value = '';
    }
}

function syncStatusFromCondition(condition) {
    const statusSelect  = document.getElementById('assetStatus');
    const maintWrap     = document.getElementById('maintenanceStatusWrap');
    const maintSelect   = document.getElementById('maintenanceStatus');
    const reasonWrap    = document.getElementById('decommissionReasonWrap');
    const reasonInput   = document.getElementById('decommissionReason');

    if (statusSelect) {
        statusSelect.value = (condition === 'good') ? 'available' : 'unavailable';
    }

    if (maintWrap) {
        maintWrap.style.display = condition === 'under_maintenance' ? '' : 'none';
        if (condition !== 'under_maintenance' && maintSelect) {
            maintSelect.value = 'pending';
        }
    }

    if (reasonWrap) {
        reasonWrap.style.display = condition === 'not_good' ? '' : 'none';
        if (reasonInput) {
            reasonInput.required = condition === 'not_good';
        }
    }
}

// Run on page load to handle pre-selected condition (e.g. validation error redirect)
document.addEventListener('DOMContentLoaded', function () {
    const condEl = document.getElementById('assetCondition');
    if (condEl) syncStatusFromCondition(condEl.value);
});

// ── Asset Name auto-fill (same as Add form) ───────────────────────────────
function syncAssetNameEdit(tagValue) {
    const nameInput = document.getElementById('editAssetNameInput');
    if (!nameInput) return;
    if (!nameInput.dataset.manuallyEdited) {
        nameInput.value = tagValue;
    }
}
document.addEventListener('DOMContentLoaded', function () {
    const nameInput = document.getElementById('editAssetNameInput');
    const tagInput  = document.getElementById('editAssetTagInput');
    if (nameInput) {
        nameInput.addEventListener('input', function () {
            if (tagInput && this.value !== tagInput.value) {
                this.dataset.manuallyEdited = '1';
            } else {
                delete this.dataset.manuallyEdited;
            }
        });
        // Pre-mark as manually edited if a name already exists that differs from tag
        if (tagInput && nameInput.value && nameInput.value !== tagInput.value) {
            nameInput.dataset.manuallyEdited = '1';
        }
    }
});

// ── Assigned Employee searchable dropdown ────────────────────────────────
function editShowEmpList() {
    const el = document.getElementById('editEmpList');
    if (el) el.style.display = '';
}
function editHideEmpList() {
    const el = document.getElementById('editEmpList');
    if (el) el.style.display = 'none';
}
function editFilterEmp(query) {
    const q = query.toLowerCase().trim();
    document.querySelectorAll('#editEmpList li').forEach(li => {
        const btn = li.querySelector('button');
        const name = btn?.dataset.empname ?? '';
        li.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
    editShowEmpList();
}
function editSelectEmp(id, label) {
    const hidden = document.getElementById('editAssignedEmployeeId');
    const search = document.getElementById('editEmpSearchInput');
    if (hidden) hidden.value = id;
    if (search) search.value = id ? label : '';
    editHideEmpList();
    onEmployeeChange(id);
}

// ── Existing photo remove ─────────────────────────────────────────────────
function removeExistingPhoto(btn) {
    const item = btn.closest('.photo-keep-item');
    const keepInput = item.querySelector('.photo-keep-input');
    if (keepInput) keepInput.disabled = true;
    item.style.opacity = '0.4';
    item.style.pointerEvents = 'none';
    btn.disabled = true;
}

// ── New photo add/remove ──────────────────────────────────────────────────
let photoNewFiles = [];
function addPhotoFile() {
    const input = document.getElementById('photoNewFileInput');
    if (!input.files.length) { alert('Please select a photo first.'); return; }
    const keepCount = document.querySelectorAll('.photo-keep-input:not([disabled])').length;
    if (keepCount + photoNewFiles.length >= 15) { alert('Maximum 15 photos allowed.'); return; }
    photoNewFiles.push(input.files[0]);
    renderPhotoNewList();
    input.value = '';
}
function removePhotoNew(i) {
    photoNewFiles.splice(i, 1);
    renderPhotoNewList();
}
function renderPhotoNewList() {
    const list   = document.getElementById('photoNewList');
    const hidden = document.getElementById('photoNewHidden');
    list.innerHTML = '';
    photoNewFiles.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        list.innerHTML += `<div class="d-flex flex-column align-items-center gap-1" style="width:80px;">
            <img src="${url}" style="width:80px;height:70px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">
            <button type="button" class="btn btn-outline-danger btn-sm w-100 py-0"
                    style="font-size:11px;" onclick="removePhotoNew(${i})">
                <i class="bi bi-x me-1"></i>Remove
            </button>
        </div>`;
    });
    const old = hidden.querySelector('input[data-photo-new]');
    if (old) old.remove();
    if (photoNewFiles.length) {
        const dt = new DataTransfer();
        photoNewFiles.forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = 'asset_photos[]'; inp.multiple = true;
        inp.setAttribute('data-photo-new', '1'); inp.style.display = 'none';
        inp.files = dt.files;
        hidden.appendChild(inp);
    }
}
</script>
@endpush

@if(Auth::user()->canEditAsset() && ($asset->assigned_employee_id || $asset->status === 'assigned'))
{{-- Release confirmation modal --}}
<div class="modal fade" id="releaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold">
                    <i class="bi bi-person-dash me-2"></i>Release Asset Assignment
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Are you sure you want to release:</p>
                <p class="fw-bold mb-1"><code>{{ $asset->asset_tag }}</code> — {{ $asset->brand }} {{ $asset->model }}</p>
                <p class="text-muted small mb-0">from <span class="fw-semibold text-dark">{{ $assignedName }}</span>?</p>
                <div class="alert alert-warning mt-3 mb-0 py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    This will remove the asset assignment and notify the employee via email if they still have other assets assigned.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form action="{{ route('assets.release', $asset) }}" method="POST" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-person-dash me-1"></i>Yes, Release
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif

@endsection