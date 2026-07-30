@extends('layouts.app')
@section('title','Edit Asset')
@section('page-title','Edit Asset')
@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ $asset->isStagedForDecommission() ? route('assets.disposed.show', array_merge(request()->query(), ['asset' => $asset->id])) : route('assets.show', array_merge(request()->query(), ['asset' => $asset->id])) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
    @if(Auth::user()->canEditAsset() && ($asset->assigned_employee_id || $asset->status === 'assigned'))
    @php $assignedName = $asset->resolvedAssigneeName(); @endphp
    <button type="button" class="btn btn-sm btn-danger"
            data-bs-toggle="modal" data-bs-target="#releaseModal">
        <i class="bi bi-person-dash me-1"></i>Release
    </button>
    @endif
</div>
@php $canAll = Auth::user()->canEditAllAssetSections(); @endphp

<form action="{{ route('assets.update', array_merge(request()->query(), ['asset' => $asset->id])) }}" method="POST" enctype="multipart/form-data">
@csrf @method('PUT')
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
@if(!$canAll)<div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>As IT Executive, you can edit Sections A, B, and C only.</div>@endif

{{-- Section A --}}
@php $catCfg = config('asset-categories'); @endphp
<div class="card mb-3">
    <div class="card-header bg-white py-3"><div class="section-header mb-0"><h6><i class="bi bi-tag me-2 text-primary"></i>Section A — Asset Identification</h6></div></div>
    <div class="card-body"><div class="row g-3">
        <div class="col-md-3"><label class="form-label fw-semibold">Asset Tag <span class="text-danger">*</span></label>
            <input type="text" name="asset_tag" id="editAssetTagInput"
                   class="form-control @error('asset_tag') is-invalid @enderror"
                   value="{{ old('asset_tag',$asset->asset_tag) }}"
                   required>
            @error('asset_tag')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label fw-semibold">Asset Name</label>
            <input type="text" name="asset_name" id="editAssetNameInput"
                   class="form-control" value="{{ old('asset_name',$asset->asset_name) }}"
                   placeholder="Auto-filled from Asset Tag"></div>
        <div class="col-md-3"><label class="form-label fw-semibold">Asset Category <span class="text-danger">*</span></label>
            <select name="asset_category" id="editAssetCategory" class="form-select @error('asset_category') is-invalid @enderror" required>
                <option value="">Select category...</option>
                @foreach($catCfg['categories'] as $k => $label)
                    <option value="{{ $k }}" {{ old('asset_category',$asset->asset_category)==$k?'selected':'' }}>{{ $label }}</option>
                @endforeach
            </select>
            @error('asset_category')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-3"><label class="form-label fw-semibold">Asset Type <span class="text-danger">*</span></label>
            <select name="asset_type" id="editAssetType" class="form-select @error('asset_type') is-invalid @enderror" required>
                <option value="">Select category first...</option>
            </select>
            @error('asset_type')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        <div class="col-md-3" id="editBrandContainer"><label class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
            <select name="brand" id="editBrandSelect" class="form-select" required>
                <option value="">Select type first...</option>
            </select>
            <input type="text" name="brand" id="editBrandText" class="form-control"
                   placeholder="Enter brand" style="display:none" disabled></div>
        <div class="col-md-3"><label class="form-label fw-semibold">Model <span class="text-danger">*</span></label>
            <input type="text" name="model" id="editModelInput" class="form-control"
                   value="{{ old('model',$asset->model) }}"
                   list="editModelSuggestions"
                   autocomplete="off"
                   placeholder="Pick from list or type your own" required>
            <datalist id="editModelSuggestions"></datalist>
            <div class="form-text text-muted small" id="editModelHint" style="display:none;">
                <i class="bi bi-lightbulb me-1"></i>Suggestions are filled from common market models. You can also type any other model.
            </div>
        </div>
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
                    <input class="form-check-input edit-ownership-radio" type="radio" name="ownership_type" id="edit_own_company" value="company"
                        {{ old('ownership_type', $asset->ownership_type ?? 'company') === 'company' ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="edit_own_company"><i class="bi bi-building me-1 text-primary"></i>Company Owned</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input edit-ownership-radio" type="radio" name="ownership_type" id="edit_own_rental" value="rental"
                        {{ old('ownership_type', $asset->ownership_type) === 'rental' ? 'checked' : '' }}>
                    <label class="form-check-label fw-semibold" for="edit_own_rental"><i class="bi bi-truck me-1 text-warning"></i>Rental / Leased</label>
                </div>
            </div>
        </div>
        @php
            $ownershipType     = old('ownership_type', $asset->ownership_type ?? 'company');
            $existingInvoices  = $asset->invoice_documents ?? [];
            $existingContracts = $asset->rental_contract_documents ?? [];
        @endphp
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
            <div class="col-md-4"><label class="form-label fw-semibold">Invoice(s) {{ $asset->invoice_documents ? '— '.count($asset->invoice_documents).' file(s)' : '' }}</label>
                <input type="file" name="invoice_documents[]" id="editCompanyInvoiceInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png" multiple>
                <div class="form-text text-muted small">PDF or images. Multiple files allowed.</div>
                @if($ownershipType !== 'rental' && !empty($existingInvoices))
                <input type="hidden" name="invoice_keep_submitted" value="1">
                <div id="invoiceExistingList" class="d-flex flex-column gap-1 mt-2">
                    @foreach($existingInvoices as $idx => $path)
                    <div class="d-flex align-items-center gap-2 doc-keep-item border rounded px-2 py-1">
                        <a href="{{ secure_file_url($path) }}" target="_blank" class="text-decoration-none flex-grow-1 text-truncate small">
                            <i class="bi bi-{{ str_ends_with(strtolower($path), '.pdf') ? 'file-earmark-pdf text-danger' : 'file-earmark-image text-primary' }} me-1"></i>Invoice {{ $idx + 1 }}
                        </a>
                        <input type="hidden" name="invoice_keep_paths[]" value="{{ $path }}" class="doc-keep-input">
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 doc-remove-btn" title="Remove"><i class="bi bi-x"></i></button>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
        <div id="rentalFields" class="row g-3" style="{{ old('ownership_type', $asset->ownership_type) === 'rental' ? '' : 'display:none;' }}">
            <div class="col-md-4"><label class="form-label fw-semibold">Rental Vendor <span class="text-danger">*</span></label>
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
            <div class="col-md-3"><label class="form-label fw-semibold">Invoice(s) {{ $asset->invoice_documents ? '— '.count($asset->invoice_documents).' file(s)' : '' }}</label>
                <input type="file" name="invoice_documents[]" id="editRentalInvoiceInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png" multiple
                    {{ old('ownership_type', $asset->ownership_type) !== 'rental' ? 'disabled' : '' }}>
                <div class="form-text text-muted small">PDF or images.</div>
                @if($ownershipType === 'rental' && !empty($existingInvoices))
                <input type="hidden" name="invoice_keep_submitted" value="1">
                <div id="invoiceExistingList" class="d-flex flex-column gap-1 mt-2">
                    @foreach($existingInvoices as $idx => $path)
                    <div class="d-flex align-items-center gap-2 doc-keep-item border rounded px-2 py-1">
                        <a href="{{ secure_file_url($path) }}" target="_blank" class="text-decoration-none flex-grow-1 text-truncate small">
                            <i class="bi bi-{{ str_ends_with(strtolower($path), '.pdf') ? 'file-earmark-pdf text-danger' : 'file-earmark-image text-primary' }} me-1"></i>Invoice {{ $idx + 1 }}
                        </a>
                        <input type="hidden" name="invoice_keep_paths[]" value="{{ $path }}" class="doc-keep-input">
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 doc-remove-btn" title="Remove"><i class="bi bi-x"></i></button>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            <div class="col-md-4"><label class="form-label fw-semibold">Contract Doc(s) {{ $asset->rental_contract_documents ? '— '.count($asset->rental_contract_documents).' file(s)' : '' }}</label>
                <input type="file" name="rental_contract_documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png" multiple>
                <div class="form-text text-muted small">Upload rental/lease contract.</div>
                @if(!empty($existingContracts))
                <input type="hidden" name="contract_keep_submitted" value="1">
                <div id="contractExistingList" class="d-flex flex-column gap-1 mt-2">
                    @foreach($existingContracts as $idx => $path)
                    <div class="d-flex align-items-center gap-2 doc-keep-item border rounded px-2 py-1">
                        <a href="{{ secure_file_url($path) }}" target="_blank" class="text-decoration-none flex-grow-1 text-truncate small">
                            <i class="bi bi-{{ str_ends_with(strtolower($path), '.pdf') ? 'file-earmark-pdf text-danger' : 'file-earmark-image text-primary' }} me-1"></i>Contract {{ $idx + 1 }}
                        </a>
                        <input type="hidden" name="contract_keep_paths[]" value="{{ $path }}" class="doc-keep-input">
                        <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1 doc-remove-btn" title="Remove"><i class="bi bi-x"></i></button>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            <div class="col-md-4"><label class="form-label fw-semibold">Supplied To (Company)</label>
                <select name="company_supplied_to" class="form-select">
                    <option value="">— Select Company —</option>
                    @foreach($registeredCompanies as $rc)
                    <option value="{{ $rc->name }}" {{ old('company_supplied_to', $asset->company_supplied_to) == $rc->name ? 'selected' : '' }}>
                        {{ $rc->name }}
                    </option>
                    @endforeach
                </select></div>
        </div>
    </div>
</div>

@if($canAll)
{{-- Section D — hidden for assets staged for decommissioning (Not Good / Returned) --}}
@if(! $asset->isStagedForDecommission())
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
                           value="{{ $editEmpDisplayLabel }}">
                    <ul id="editEmpList"
                        class="list-unstyled border rounded bg-white shadow-sm position-absolute mb-0"
                        style="z-index:1055;max-height:200px;overflow-y:auto;display:none;top:100%;left:0;min-width:100%;width:max-content;max-width:480px;">
                        <li>
                            <button type="button" class="dropdown-item"
                                    data-empid="" data-emplabel="— Not Assigned —">
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
                                    data-empid="{{ $emp->id }}" data-emplabel="{{ $eLabel }}"
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
                    // 'assigned' displays as 'unavailable' (asset is locked to employee)
                    $currentStatus = ($currentStatus === 'assigned') ? 'unavailable' : (in_array($currentStatus, ['available','unavailable']) ? $currentStatus : 'available');
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

        {{-- Condition: Good / Under Maintenance / Not Good / Returned --}}
        <div class="col-md-3">
            <label class="form-label fw-semibold">Condition <span class="text-danger">*</span></label>
            <select name="asset_condition" id="assetCondition" class="form-select" required>
                @php
                    // Map any legacy value → the current condition set (drives Decommissioning).
                    $cond = old('asset_condition', $asset->asset_condition);
                    if (! array_key_exists($cond, \App\Models\AssetInventory::CONDITIONS)) {
                        $cond = $cond === 'damaged' ? 'not_good' : 'good'; // legacy 'damaged'; else safe fallback
                    }
                @endphp
                @foreach(\App\Models\AssetInventory::CONDITIONS as $condValue => $condLabel)
                    <option value="{{ $condValue }}" {{ $cond === $condValue ? 'selected' : '' }}>{{ $condLabel }}</option>
                @endforeach
            </select>
            <div class="form-text">
                Good → Available &nbsp;|&nbsp; Under Maintenance → Unavailable &nbsp;|&nbsp; Not Good / Returned → moved to Decommissioning
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

        {{-- Decommission / Return reason — shown for Not Good (e-waste) and Returned (vendor return).
             Required only for Not Good; a return is usually just "end of lease". --}}
        @php $isDecommissioning = in_array($cond, \App\Models\AssetInventory::DECOMMISSION_CONDITIONS); @endphp
        <div class="col-md-6" id="decommissionReasonWrap" style="{{ $isDecommissioning ? '' : 'display:none;' }}">
            <label class="form-label fw-semibold">Decommission / Return Reason @if($cond === 'not_good')<span class="text-danger">*</span>@endif</label>
            @php
                $existingReason = old('decommission_reason',
                    \App\Models\DisposedAsset::where('asset_inventory_id', $asset->id)->value('reason') ?? '');
            @endphp
            <input type="text" name="decommission_reason" id="decommissionReason"
                   class="form-control"
                   value="{{ $existingReason }}"
                   placeholder="e.g. End of lease, Screen cracked beyond repair, Hardware failure..."
                   {{ $cond === 'not_good' ? 'required' : '' }}>
            <div class="form-text">Shown in the Decommissioning Assets table. Recommended for a returned rental asset (e.g. contract ended).</div>
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
                    <button type="button" class="btn btn-outline-danger btn-sm w-100 py-0 photo-remove-btn"
                            style="font-size:11px;" title="Remove">
                        <i class="bi bi-x me-1"></i>Remove
                    </button>
                </div>
                @endforeach
            </div>
            <div class="form-text text-muted mb-2">{{ count($existingPhotos) }} photo(s) uploaded. Click Remove to delete a photo on save.</div>
            @endif
            {{-- New photo upload --}}
            <div class="mb-2">
                <input type="file" id="photoNewFileInput" class="form-control" accept=".jpg,.jpeg,.png" multiple style="max-width:480px;">
            </div>
            <div id="photoNewList" class="d-flex flex-wrap gap-2 mb-1"></div>
            <div id="photoNewHidden"></div>
            <div id="photoCompressStatus" class="text-muted small mb-1" style="display:none;"></div>
            <div class="form-text text-muted">Select one or more photos. New photos are auto-compressed and added to existing (max 15 total).</div>
            @error('asset_photos')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
            @error('asset_photos.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2"
                      maxlength="1500" placeholder="Any notes about this asset...">{{ old('notes', $asset->notes) }}</textarea>
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
    <a href="{{ $asset->isStagedForDecommission() ? route('assets.disposed.show', array_merge(request()->query(), ['asset' => $asset->id])) : route('assets.show', array_merge(request()->query(), ['asset' => $asset->id])) }}" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-circle me-2"></i>Save Changes</button>
</div>
</form>

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
/* ── Cascading Category → Type → Brand ─────────────────────────────── */
const assetCatConfig = @json(config('asset-categories'));

function updateTypeDropdown(categorySelect, typeSelect, preselected) {
    const cat = categorySelect.value;
    const types = assetCatConfig.types[cat] || {};
    let html = '<option value="">Select type...</option>';
    Object.entries(types).forEach(([k, v]) => {
        const sel = (k === preselected) ? ' selected' : '';
        html += `<option value="${k}"${sel}>${v}</option>`;
    });
    typeSelect.innerHTML = html;
}

/**
 * Refresh the <datalist> of model suggestions for the given type+brand.
 * Free-text entry is still allowed — this is a hint, not a constraint.
 */
function updateModelSuggestions(typeValue, brandValue, datalistId, hintId) {
    const dl   = document.getElementById(datalistId);
    const hint = hintId ? document.getElementById(hintId) : null;
    if (!dl) return;
    const models = (assetCatConfig.models?.[typeValue]?.[brandValue]) || [];
    let html = '';
    models.forEach(m => {
        const safe = String(m).replace(/"/g, '&quot;');
        html += `<option value="${safe}"></option>`;
    });
    dl.innerHTML = html;
    if (hint) hint.style.display = models.length ? '' : 'none';
}

function updateBrandField(typeValue, brandSelect, brandText, preselected) {
    const brands = assetCatConfig.brands[typeValue];

    if (!typeValue) {
        brandSelect.innerHTML = '<option value="">Select type first...</option>';
        brandSelect.style.display = '';
        brandSelect.disabled = false;
        brandSelect.required = true;
        brandText.style.display = 'none';
        brandText.disabled = true;
        brandText.required = false;
        brandText.value = '';
        return;
    }

    if (!brands) {
        brandSelect.style.display = 'none';
        brandSelect.disabled = true;
        brandSelect.required = false;
        brandText.style.display = '';
        brandText.disabled = false;
        brandText.required = true;
        if (preselected && !brandText.value) brandText.value = preselected;
        return;
    }

    brandSelect.style.display = '';
    brandSelect.disabled = false;
    brandSelect.required = true;
    brandText.style.display = 'none';
    brandText.disabled = true;
    brandText.required = false;
    brandText.value = '';

    let html = '<option value="">Select brand...</option>';
    brands.forEach(b => {
        const sel = (b === preselected) ? ' selected' : '';
        html += `<option value="${b}"${sel}>${b}</option>`;
    });
    brandSelect.innerHTML = html;
}

// ── Edit-Asset page — cascading init ──
(function () {
    const catSelect   = document.getElementById('editAssetCategory');
    const typeSelect  = document.getElementById('editAssetType');
    const brandSelect = document.getElementById('editBrandSelect');
    const brandText   = document.getElementById('editBrandText');
    if (!catSelect || !typeSelect || !brandSelect) return;

    const savedType  = @json(old('asset_type', $asset->asset_type ?? ''));
    const savedBrand = @json(old('brand', $asset->brand ?? ''));

    function currentBrand() {
        if (brandSelect.style.display !== 'none' && !brandSelect.disabled) return brandSelect.value || '';
        if (brandText.style.display   !== 'none' && !brandText.disabled)   return brandText.value   || '';
        return '';
    }

    function refreshModels() {
        if (typeof updateModelSuggestions === 'function') {
            updateModelSuggestions(typeSelect.value, currentBrand(), 'editModelSuggestions', 'editModelHint');
        }
    }

    catSelect.addEventListener('change', function () {
        updateTypeDropdown(catSelect, typeSelect, null);
        updateBrandField('', brandSelect, brandText, null);
        refreshModels();
    });

    typeSelect.addEventListener('change', function () {
        updateBrandField(typeSelect.value, brandSelect, brandText, null);
        refreshModels();
    });

    brandSelect.addEventListener('change', refreshModels);
    brandText.addEventListener('input',   refreshModels);

    // Initialise on load with saved values
    if (catSelect.value) {
        updateTypeDropdown(catSelect, typeSelect, savedType);
        if (typeSelect.value) {
            updateBrandField(typeSelect.value, brandSelect, brandText, savedBrand);
        }
    }

    // If the saved brand isn't in the dropdown list (legacy data), show text input
    if (brandSelect.style.display !== 'none' && savedBrand && !brandSelect.querySelector(`option[value="${savedBrand}"]`)) {
        brandSelect.style.display = 'none';
        brandSelect.disabled = true;
        brandSelect.required = false;
        brandText.style.display = '';
        brandText.disabled = false;
        brandText.required = true;
        brandText.value = savedBrand;
    }

    refreshModels();
})();

// ── Ownership toggle (Edit form) ─────────────────────────────────────
(function () {
    function toggleOwnership(value) {
        var rentalFields  = document.getElementById('rentalFields');
        var companyFields = document.getElementById('companyFields');
        if (rentalFields)  rentalFields.style.display  = value === 'rental'  ? '' : 'none';
        if (companyFields) companyFields.style.display = value === 'company' ? '' : 'none';
        var companyInvoice = document.getElementById('editCompanyInvoiceInput');
        var rentalInvoice  = document.getElementById('editRentalInvoiceInput');
        if (companyInvoice) companyInvoice.disabled = (value !== 'company');
        if (rentalInvoice)  rentalInvoice.disabled  = (value !== 'rental');
    }
    document.querySelectorAll('.edit-ownership-radio').forEach(function (radio) {
        radio.addEventListener('change', function () { toggleOwnership(this.value); });
    });
})();

/**
 * When condition changes, auto-set the Section A status field:
 *   Good              → Available
 *   Under Maintenance → Unavailable
 *   Not Good          → Unavailable (will be disposed on save)
 * Also show/hide the Maintenance Status dropdown.
 */
function onEmployeeChange(employeeId) {
    const dateField    = document.getElementById('assignedDate');
    const statusSelect = document.getElementById('assetStatus');
    const condSelect   = document.getElementById('assetCondition');

    if (employeeId) {
        // Employee assigned → auto-fill date + set unavailable
        if (dateField && !dateField.value) {
            const today = new Date();
            const yyyy = today.getFullYear();
            const mm   = String(today.getMonth() + 1).padStart(2, '0');
            const dd   = String(today.getDate()).padStart(2, '0');
            dateField.value = `${yyyy}-${mm}-${dd}`;
        }
        if (statusSelect) statusSelect.value = 'unavailable';
    } else {
        // Employee cleared → revert date + set status by condition
        if (dateField) {
            const originalDate = '{{ $asset->asset_assigned_date?->format('Y-m-d') ?? '' }}';
            if (!originalDate) dateField.value = '';
        }
        if (statusSelect && condSelect) {
            statusSelect.value = (condSelect.value === 'good') ? 'available' : 'unavailable';
        }
    }
}

function syncStatusFromCondition(condition) {
    const statusSelect  = document.getElementById('assetStatus');
    const empHidden     = document.getElementById('editAssignedEmployeeId');
    const maintWrap     = document.getElementById('maintenanceStatusWrap');
    const maintSelect   = document.getElementById('maintenanceStatus');
    const reasonWrap    = document.getElementById('decommissionReasonWrap');
    const reasonInput   = document.getElementById('decommissionReason');

    if (statusSelect) {
        // If employee is assigned, always unavailable; otherwise condition-driven
        if (empHidden && empHidden.value !== '') {
            statusSelect.value = 'unavailable';
        } else {
            statusSelect.value = (condition === 'good') ? 'available' : 'unavailable';
        }
    }

    if (maintWrap) {
        maintWrap.style.display = condition === 'under_maintenance' ? '' : 'none';
        if (condition !== 'under_maintenance' && maintSelect) {
            maintSelect.value = 'pending';
        }
    }

    if (reasonWrap) {
        // Shown for both decommissioning conditions, but only Not Good demands a reason —
        // a vendor return is usually just "end of lease".
        const isDecommissioning = (condition === 'not_good' || condition === 'returned');
        reasonWrap.style.display = isDecommissioning ? '' : 'none';
        if (reasonInput) {
            reasonInput.required = condition === 'not_good';
        }
    }
}

// Run on page load + bind change listener
document.addEventListener('DOMContentLoaded', function () {
    const condEl = document.getElementById('assetCondition');
    if (condEl) {
        syncStatusFromCondition(condEl.value);
        condEl.addEventListener('change', function() { syncStatusFromCondition(this.value); });
    }
});

// ── Asset Name auto-fill (same as Add form) ───────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    const nameInput = document.getElementById('editAssetNameInput');
    const tagInput  = document.getElementById('editAssetTagInput');

    if (nameInput && tagInput) {
        // Auto-fill Asset Name from Asset Tag (unless user has manually edited it)
        tagInput.addEventListener('input', function () {
            if (!nameInput.dataset.manuallyEdited) {
                nameInput.value = this.value;
            }
        });

        // Track manual edits to Asset Name
        nameInput.addEventListener('input', function () {
            if (this.value !== tagInput.value) {
                this.dataset.manuallyEdited = '1';
            } else {
                delete this.dataset.manuallyEdited;
            }
        });

        // Pre-mark as manually edited if a name already exists that differs from tag
        if (nameInput.value && nameInput.value !== tagInput.value) {
            nameInput.dataset.manuallyEdited = '1';
        }
    }
});

// ── Assigned Employee searchable dropdown ────────────────────────────────
(function () {
    const searchInput = document.getElementById('editEmpSearchInput');
    const empList     = document.getElementById('editEmpList');
    const hiddenInput = document.getElementById('editAssignedEmployeeId');
    if (!searchInput || !empList) return;

    function showList()  { empList.style.display = ''; }
    function hideList()  { empList.style.display = 'none'; }

    function filterList(query) {
        const q = query.toLowerCase().trim();
        empList.querySelectorAll('li').forEach(li => {
            const btn  = li.querySelector('button');
            const name = btn?.dataset.empname ?? '';
            li.style.display = (!q || name.includes(q)) ? '' : 'none';
        });
        showList();
    }

    function selectEmp(id, label) {
        if (hiddenInput) hiddenInput.value = id;
        searchInput.value = id ? label : '';
        hideList();
        onEmployeeChange(id);
    }

    searchInput.addEventListener('input', function () { filterList(this.value); });
    searchInput.addEventListener('focus', showList);
    searchInput.addEventListener('blur',  function () { setTimeout(hideList, 200); });

    empList.querySelectorAll('button[data-emplabel]').forEach(btn => {
        btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            selectEmp(this.dataset.empid, this.dataset.emplabel);
        });
    });

    window.editSelectEmp = selectEmp;
})();

// ── Existing photo remove (event delegation) ────────────────────────────
(function () {
    var list = document.getElementById('photoExistingList');
    if (list) {
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.photo-remove-btn');
            if (!btn) return;
            var item = btn.closest('.photo-keep-item');
            var keepInput = item.querySelector('.photo-keep-input');
            if (keepInput) keepInput.disabled = true;
            item.style.opacity = '0.4';
            item.style.pointerEvents = 'none';
            btn.disabled = true;
        });
    }
})();

// ── Remove existing Procurement documents (invoices / contract docs) ───────
(function () {
    ['invoiceExistingList', 'contractExistingList'].forEach(function (listId) {
        var list = document.getElementById(listId);
        if (!list) return;
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.doc-remove-btn');
            if (!btn) return;
            var item = btn.closest('.doc-keep-item');
            var keepInput = item.querySelector('.doc-keep-input');
            if (keepInput) keepInput.disabled = true;   // dropped from submitted keep[] → deleted on save
            item.style.opacity = '0.4';
            item.style.pointerEvents = 'none';
            btn.disabled = true;
        });
    });
})();

// ── Image compression utility ─────────────────────────────────────────────
function compressImage(file, maxWidth = 1920, maxHeight = 1920, quality = 0.8) {
    return new Promise((resolve) => {
        if (!file.type.startsWith('image/')) { resolve(file); return; }
        const reader = new FileReader();
        reader.onload = (e) => {
            const img = new Image();
            img.onload = () => {
                let w = img.width, h = img.height;
                if (w > maxWidth || h > maxHeight) {
                    const ratio = Math.min(maxWidth / w, maxHeight / h);
                    w = Math.round(w * ratio);
                    h = Math.round(h * ratio);
                }
                const canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                canvas.toBlob((blob) => {
                    const compressed = new File([blob], file.name, { type: 'image/jpeg', lastModified: Date.now() });
                    resolve(compressed.size < file.size ? compressed : file);
                }, 'image/jpeg', quality);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}

// ── New photo add/remove ──────────────────────────────────────────────────
let photoNewFiles = [];
document.getElementById('photoNewFileInput').addEventListener('change', async function() {
    const files = Array.from(this.files);
    if (!files.length) return;
    const keepCount = document.querySelectorAll('.photo-keep-input:not([disabled])').length;
    const remaining = 15 - keepCount - photoNewFiles.length;
    if (remaining <= 0) { alert('Maximum 15 photos allowed.'); this.value = ''; return; }
    const toAdd = files.slice(0, remaining);
    if (files.length > remaining) alert(`Only ${remaining} more photo(s) can be added. Extra files were skipped.`);
    const status = document.getElementById('photoCompressStatus');
    status.style.display = '';
    status.textContent = `Compressing ${toAdd.length} photo(s)...`;
    for (let i = 0; i < toAdd.length; i++) {
        status.textContent = `Compressing photo ${i + 1} of ${toAdd.length}...`;
        const compressed = await compressImage(toAdd[i]);
        photoNewFiles.push(compressed);
    }
    status.style.display = 'none';
    renderPhotoNewList();
    this.value = '';
});
function renderPhotoNewList() {
    const list   = document.getElementById('photoNewList');
    const hidden = document.getElementById('photoNewHidden');
    list.innerHTML = '';
    photoNewFiles.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        const sizeKB = (f.size / 1024).toFixed(0);
        list.innerHTML += `<div class="d-flex flex-column align-items-center gap-1" style="width:80px;">
            <img src="${url}" style="width:80px;height:70px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">
            <span class="text-muted" style="font-size:10px;">${sizeKB} KB</span>
            <button type="button" class="btn btn-outline-danger btn-sm w-100 py-0 photo-new-remove-btn"
                    style="font-size:11px;" data-index="${i}">
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
// Event delegation for dynamically-rendered new photo Remove buttons
document.getElementById('photoNewList').addEventListener('click', function (e) {
    var btn = e.target.closest('.photo-new-remove-btn');
    if (!btn) return;
    var idx = parseInt(btn.dataset.index, 10);
    photoNewFiles.splice(idx, 1);
    renderPhotoNewList();
});
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