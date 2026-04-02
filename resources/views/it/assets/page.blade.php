@extends('layouts.app')
@section('title', 'Asset Listing')
@section('page-title', 'Asset Listing')

@section('content')

{{-- ─── PAGE HEADER ─── --}}
<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0 small">Manage all company asset inventory</p>
    @if(Auth::user()->canAddAsset())
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAssetModal">
        <i class="bi bi-plus-circle me-2"></i>Add New Asset
    </button>
    @endif
</div>

{{-- ─── TABS ─── --}}
@php $activeTab = request('tab', 'listing'); @endphp
<ul class="nav nav-tabs mb-0" id="assetTabs" role="tablist" style="border-bottom:none;">
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $activeTab === 'listing' ? 'active' : '' }}" id="tab-listing"
                data-bs-toggle="tab" data-bs-target="#pane-listing" type="button" role="tab">
            <i class="bi bi-laptop me-1"></i>Asset Listing
            <span class="badge bg-secondary ms-1" style="font-size:10px;">{{ $assets->total() }}</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $activeTab === 'damaged' ? 'active' : '' }}" id="tab-damaged"
                data-bs-toggle="tab" data-bs-target="#pane-damaged" type="button" role="tab">
            <i class="bi bi-archive me-1 text-danger"></i>Decommissioning Assets
            <span class="badge bg-danger ms-1" style="font-size:10px;">{{ $disposed->total() }}</span>
        </button>
    </li>
</ul>

<div class="tab-content">

{{-- ══════════════ TAB 1: ASSET LISTING ══════════════ --}}
<div class="tab-pane fade {{ $activeTab === 'listing' ? 'show active' : '' }}" id="pane-listing" role="tabpanel">
<div class="card" style="border-top-left-radius:0;">
    {{-- Filters --}}
    <div class="card-body border-bottom pb-3">
        <form action="{{ route('assets.index') }}" method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Tag, name, brand, serial..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    @foreach(['available'=>'Available','unavailable'=>'Unavailable','assigned'=>'Assigned'] as $s=>$l)
                        <option value="{{ $s }}" {{ request('status')==$s?'selected':'' }}>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach(['laptop','monitor','converter','phone','sim_card','access_card','other'] as $t)
                        <option value="{{ $t }}" {{ request('type')==$t?'selected':'' }}>
                            {{ ucfirst(str_replace('_',' ',$t)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="ownership" class="form-select form-select-sm" id="ownershipFilter"
                        onchange="toggleVendorFilter(this.value)">
                    <option value="">All Ownership</option>
                    <option value="company" {{ request('ownership')==='company'?'selected':'' }}>Company Owned</option>
                    <option value="rental"  {{ request('ownership')==='rental'?'selected':'' }}>Rental / Leased</option>
                </select>
            </div>
            <div class="col-md-2" id="vendorFilterWrap" style="{{ request('ownership')==='rental' ? '' : 'display:none;' }}">
                <select name="vendor" class="form-select form-select-sm">
                    <option value="">All Vendors</option>
                    @foreach($rentalVendors as $v)
                        <option value="{{ $v }}" {{ request('vendor')==$v?'selected':'' }}>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['search','status','type','ownership','vendor']))
                    <a href="{{ route('assets.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
                <a href="{{ route('assets.export', request()->query()) }}"
                   class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
                @if(Auth::user()->canAddAsset())
                <a href="{{ route('assets.import.template') }}" class="btn btn-outline-secondary btn-sm" title="Download CSV Template">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Template
                </a>
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="bi bi-upload me-1"></i>Import CSV
                </button>
                @endif
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <small class="text-muted px-3 pt-2 d-block">{{ $assets->total() }} record(s)</small>
        @if($assets->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox" style="font-size:40px;"></i>
                <p class="mt-2">No assets found</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background:#f8fafc;font-size:13px;">
                    <tr>
                        <th class="ps-3">Tag</th>
                        <th>Asset Name</th>
                        <th>Type</th>
                        <th>Brand/Model</th>
                        <th>Status</th>
                        <th>Condition</th>
                        <th>Assigned To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($assets as $a)
                    @php $sc=['available'=>'success','assigned'=>'primary','unavailable'=>'warning text-dark','under_maintenance'=>'warning text-dark','retired'=>'secondary']; @endphp
                    <tr>
                        <td class="ps-3"><code>{{ $a->asset_tag }}</code></td>
                        <td>
                            <strong>{{ $a->asset_name }}</strong><br>
                            <small class="text-muted">{{ $a->serial_number }}</small>
                        </td>
                        <td>{{ ucfirst(str_replace('_',' ',$a->asset_type)) }}</td>
                        <td>{{ $a->brand }} {{ $a->model }}</td>
                        <td><span class="badge bg-{{ $sc[$a->status]??'secondary' }}">{{ ucfirst(str_replace('_',' ',$a->status)) }}</span></td>
                        <td><span class="badge bg-light text-dark">{{ ucfirst(str_replace('_',' ',$a->asset_condition)) }}</span></td>
                        <td>{{ $a->resolvedAssigneeName() }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="{{ route('assets.show', $a) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                @if(Auth::user()->canEditAsset())
                                    <a href="{{ route('assets.edit', $a) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>
                                    @if($a->status === 'assigned')
                                    <button type="button"
                                            class="btn btn-sm btn-danger"
                                            title="Release asset from employee"
                                            onclick="confirmRelease({{ $a->id }}, '{{ addslashes($a->asset_tag) }}', '{{ addslashes($a->resolvedAssigneeName()) }}')">
                                        <i class="bi bi-person-dash"></i> Release
                                    </button>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $assets->links() }}</div>
        @endif
    </div>
</div>
</div>{{-- /pane-listing --}}

{{-- ══════════════ TAB 2: DAMAGED ASSETS ══════════════ --}}
<div class="tab-pane fade {{ $activeTab === 'damaged' ? 'show active' : '' }}" id="pane-damaged" role="tabpanel">
<div class="card" style="border-top-left-radius:0;border-top-right-radius:0;">

    {{-- Decommissioning Filters --}}
    <div class="card-body border-bottom pb-3">
        <form action="{{ route('assets.index') }}" method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="damaged">
            <div class="col-md-3">
                <input type="text" name="d_search" class="form-control form-control-sm"
                       placeholder="Tag, brand, model..." value="{{ request('d_search') }}">
            </div>
            <div class="col-md-2">
                <select name="d_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach(['laptop','monitor','converter','phone','sim_card','access_card','other'] as $t)
                        <option value="{{ $t }}" {{ request('d_type')==$t?'selected':'' }}>
                            {{ ucfirst(str_replace('_',' ',$t)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="d_ownership" class="form-select form-select-sm" id="dOwnershipFilter"
                        onchange="toggleDVendorFilter(this.value)">
                    <option value="">All Ownership</option>
                    <option value="company" {{ request('d_ownership')==='company'?'selected':'' }}>Company Owned</option>
                    <option value="rental"  {{ request('d_ownership')==='rental'?'selected':'' }}>Rental / Leased</option>
                </select>
            </div>
            <div class="col-md-2" id="dVendorFilterWrap" style="{{ request('d_ownership')==='rental' ? '' : 'display:none;' }}">
                <select name="d_vendor" class="form-select form-select-sm">
                    <option value="">All Vendors</option>
                    @foreach($rentalVendors as $v)
                        <option value="{{ $v }}" {{ request('d_vendor')==$v?'selected':'' }}>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['d_search','d_type','d_ownership','d_vendor']))
                    <a href="{{ route('assets.index', ['tab'=>'damaged']) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="px-3 pt-3 pb-2">
            <p class="text-muted small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                Assets marked as <strong>Not Good</strong> are removed from the active listing and tracked here for decommissioning.
            </p>
        </div>
        @if($disposed->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-check-circle" style="font-size:40px;color:#16a34a;"></i>
                <p class="mt-2">No decommissioned assets on record.</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Asset Tag</th>
                        <th>Type</th>
                        <th>Brand / Model</th>
                        <th>Serial Number</th>
                        <th>Ownership</th>
                        <th>Condition</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($disposed as $d)
                    <tr>
                        <td class="ps-3"><code>{{ $d->asset_tag }}</code></td>
                        <td>{{ ucfirst(str_replace('_',' ', $d->asset_type)) }}</td>
                        <td>{{ $d->brand }} {{ $d->model }}</td>
                        <td class="text-muted">{{ $d->serial_number ?? '—' }}</td>
                        <td>
                            @if($d->asset)
                                <span class="badge bg-{{ $d->asset->ownership_type === 'rental' ? 'warning text-dark' : 'secondary' }}">
                                    {{ $d->asset->ownership_type === 'rental' ? 'Rental' : 'Company' }}
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td><span class="badge bg-danger">Not Good</span></td>
                        <td style="max-width:180px;white-space:normal;">{{ $d->reason ?? '—' }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                @if($d->asset)
                                    <a href="{{ route('assets.disposed.show', $d->asset) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if(Auth::user()->canEditAsset())
                                    <a href="{{ route('assets.edit', $d->asset) }}"
                                       class="btn btn-sm btn-outline-warning" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    @endif
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $disposed->links(data: ['pageName' => 'disposed_page']) }}</div>
        @endif
    </div>
</div>
</div>{{-- /pane-damaged --}}

</div>{{-- /tab-content --}}

{{-- ═══════════════ ADD NEW ASSET MODAL ═══════════════ --}}
@if(Auth::user()->canAddAsset())
<div class="modal fade" id="addAssetModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h5 class="modal-title text-white fw-bold">
                    <i class="bi bi-plus-circle me-2"></i>Add New Asset
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form action="{{ route('assets.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="modal-body">
                @if($errors->any())
                    <div class="alert alert-danger">
                        <strong><i class="bi bi-exclamation-circle me-1"></i>Please fix the following:</strong>
                        <ul class="mb-0 mt-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                    </div>
                @endif

                {{-- Section A —  Identification --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-tag me-2 text-primary"></i>Section A — Asset Identification</h6>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Asset ID / Tag <span class="text-danger">*</span></label>
                        <input type="text" name="asset_tag" id="assetTagInput"
                               class="form-control @error('asset_tag') is-invalid @enderror"
                               value="{{ old('asset_tag') }}" placeholder="e.g. LPT-003"
                               oninput="syncAssetName(this.value)" required>
                        @error('asset_tag')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Asset Type <span class="text-danger">*</span></label>
                        <select name="asset_type" class="form-select @error('asset_type') is-invalid @enderror" required>
                            <option value="">Select...</option>
                            @foreach(['laptop'=>'Laptop','monitor'=>'Monitor','converter'=>'Converter','phone'=>'Company Phone','sim_card'=>'SIM Card','access_card'=>'Access Card','petty_cash'=>'Petty Cash','accessories'=>'Accessories','furniture'=>'Furniture','equipment'=>'Equipment','other'=>'Other'] as $v=>$l)
                                <option value="{{ $v }}" {{ old('asset_type')==$v?'selected':'' }}>{{ $l }}</option>
                            @endforeach
                        </select>
                        @error('asset_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
                        <select name="brand" class="form-select @error('brand') is-invalid @enderror" required>
                            <option value="">Select...</option>
                            @foreach(['Dell','HP','Lenovo','Apple','Asus','Acer','Maxis','Internal','Anker','Petty Cash','Accessories','Furniture','Equipment','Access Card','Office Keys','Other'] as $b)
                                <option value="{{ $b }}" {{ old('brand')==$b?'selected':'' }}>{{ $b }}</option>
                            @endforeach
                        </select>
                        @error('brand')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Model <span class="text-danger">*</span></label>
                        <input type="text" name="model" class="form-control @error('model') is-invalid @enderror"
                               value="{{ old('model') }}" required>
                        @error('model')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Asset Name</label>
                        <input type="text" name="asset_name" id="assetNameInput"
                               class="form-control @error('asset_name') is-invalid @enderror"
                               value="{{ old('asset_name') }}"
                               placeholder="Auto-filled from Asset Tag">
                        @error('asset_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Serial Number <span class="text-danger">*</span></label>
                        <input type="text" name="serial_number" class="form-control @error('serial_number') is-invalid @enderror"
                               value="{{ old('serial_number') }}" required>
                        @error('serial_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>

                {{-- Section B — Specification --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-cpu me-2 text-primary"></i>Section B — Asset Specification</h6>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4"><label class="form-label fw-semibold">Processor / CPU</label>
                        <input type="text" name="processor" class="form-control" value="{{ old('processor') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">RAM Size</label>
                        <input type="text" name="ram_size" class="form-control" value="{{ old('ram_size') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Storage</label>
                        <input type="text" name="storage" class="form-control" value="{{ old('storage') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Operating System</label>
                        <input type="text" name="operating_system" class="form-control" value="{{ old('operating_system') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Screen Size</label>
                        <input type="text" name="screen_size" class="form-control" value="{{ old('screen_size') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Others</label>
                        <input type="text" name="spec_others" class="form-control" value="{{ old('spec_others') }}"></div>
                </div>

                {{-- Section C — Procurement --}}
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-receipt me-2 text-primary"></i>Section C — Procurement</h6>
                </div>

                {{-- Ownership toggle — always shown first --}}
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Ownership Type <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3 mt-1">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="ownership_type" id="own_company" value="company"
                                    {{ old('ownership_type','company') === 'company' ? 'checked' : '' }}
                                    onchange="toggleOwnership(this.value)">
                                <label class="form-check-label fw-semibold" for="own_company"><i class="bi bi-building me-1 text-primary"></i>Company Owned</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="ownership_type" id="own_rental" value="rental"
                                    {{ old('ownership_type') === 'rental' ? 'checked' : '' }}
                                    onchange="toggleOwnership(this.value)">
                                <label class="form-check-label fw-semibold" for="own_rental"><i class="bi bi-truck me-1 text-warning"></i>Rental / Leased</label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Company Owned fields --}}
                <div id="companyFields" class="row g-3 mb-4" style="{{ old('ownership_type') === 'rental' ? 'display:none;' : '' }}">
                    <div class="col-md-4"><label class="form-label fw-semibold">Company Name</label>
                        <select name="company_name" class="form-select">
                            <option value="">— Select Company —</option>
                            @foreach($registeredCompanies as $rc)
                            <option value="{{ $rc->name }}" {{ old('company_name') == $rc->name ? 'selected' : '' }}>
                                {{ $rc->name }}
                            </option>
                            @endforeach
                        </select></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Vendor / Supplier</label>
                        <input type="text" name="purchase_vendor" class="form-control" value="{{ old('purchase_vendor') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Purchase Cost (RM)</label>
                        <input type="number" name="purchase_cost" class="form-control" value="{{ old('purchase_cost') }}" step="0.01"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Warranty Expiry</label>
                        <input type="date" name="warranty_expiry_date" class="form-control" value="{{ old('warranty_expiry_date') }}"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Invoice (PDF)</label>
                        <input type="file" name="invoice_document" class="form-control" accept=".pdf"></div>
                </div>

                {{-- Rental fields --}}
                <div id="rentalFields" class="row g-3 mb-4" style="{{ old('ownership_type') === 'rental' ? '' : 'display:none;' }}">
                    <div class="col-md-4"><label class="form-label fw-semibold">Rental Vendor <span class="text-danger">*</span></label>
                        <input type="text" name="rental_vendor" class="form-control" value="{{ old('rental_vendor') }}" placeholder="Vendor / leasing company name"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Vendor Contact</label>
                        <input type="text" name="rental_vendor_contact" class="form-control" value="{{ old('rental_vendor_contact') }}" placeholder="Phone or email"></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Monthly Cost (RM)</label>
                        <input type="number" name="rental_cost_per_month" class="form-control" value="{{ old('rental_cost_per_month') }}" step="0.01" placeholder="0.00"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Rental Start Date</label>
                        <input type="date" name="rental_start_date" class="form-control" value="{{ old('rental_start_date') }}"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Rental End Date</label>
                        <input type="date" name="rental_end_date" class="form-control" value="{{ old('rental_end_date') }}"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Contract Reference</label>
                        <input type="text" name="rental_contract_reference" class="form-control" value="{{ old('rental_contract_reference') }}" placeholder="Contract / PO number"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Invoice (PDF)</label>
                        <input type="file" name="invoice_document" class="form-control" accept=".pdf"></div>
                </div>

                {{-- Sections D & E (IT Manager only) --}}
                @if(Auth::user()->canEditAllAssetSections())
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-person-check me-2 text-primary"></i>Section D — Assignment</h6>
                </div>
                @php
                    $addPreselectedId = old('assigned_employee_id', '');
                    $addPreselectedLabel = '';
                    if ($addPreselectedId) {
                        foreach ($employees as $emp) {
                            if ($emp->id == $addPreselectedId) {
                                $en  = $emp->onboarding?->personalDetail?->full_name ?? $emp->full_name ?? 'Employee #'.$emp->id;
                                $ee  = $emp->company_email ?? $emp->personal_email ?? '';
                                $addPreselectedLabel = $ee ? "{$en} — {$ee}" : $en;
                                break;
                            }
                        }
                    }
                @endphp
                <div class="row g-3 mb-4">
                    <div class="col-md-3"><label class="form-label fw-semibold">Assigned Employee</label>
                        <div class="position-relative">
                            <input type="text" id="addEmpSearchInput" class="form-control"
                                   placeholder="Search or select employee..."
                                   autocomplete="off"
                                   value="{{ $addPreselectedLabel }}"
                                   oninput="addFilterEmp(this.value)"
                                   onfocus="addShowEmpList()"
                                   onblur="setTimeout(addHideEmpList, 200)">
                            <ul id="addEmpList"
                                class="list-unstyled border rounded bg-white shadow-sm position-absolute mb-0"
                                style="z-index:1060;max-height:200px;overflow-y:auto;display:none;top:100%;left:0;min-width:100%;width:max-content;max-width:480px;">
                                <li>
                                    <button type="button" class="dropdown-item"
                                            onmousedown="addSelectEmp('', '— Not Assigned —')">
                                        — Not Assigned —
                                    </button>
                                </li>
                                @foreach($employees as $emp)
                                @php
                                    $empName  = $emp->onboarding?->personalDetail?->full_name ?? $emp->full_name ?? 'Employee #'.$emp->id;
                                    $empEmail = $emp->company_email ?? $emp->personal_email ?? '';
                                    $empLabel = $empEmail ? "{$empName} — {$empEmail}" : $empName;
                                @endphp
                                <li>
                                    <button type="button" class="dropdown-item"
                                            onmousedown="addSelectEmp('{{ $emp->id }}', {{ json_encode($empLabel) }})"
                                            data-empname="{{ strtolower($empLabel) }}"
                                            style="white-space:normal;word-break:break-word;">
                                        {{ $empLabel }}
                                    </button>
                                </li>
                                @endforeach
                            </ul>
                            <input type="hidden" name="assigned_employee_id" id="addAssignedEmployeeId"
                                   value="{{ old('assigned_employee_id', '') }}">
                        </div>
                        <div class="form-text text-muted small">Type to search by name or email.</div>
                    </div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Assigned Date</label>
                        <input type="date" name="asset_assigned_date" class="form-control" value="{{ old('asset_assigned_date') }}"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Expected Return</label>
                        <input type="date" name="expected_return_date" class="form-control" value="{{ old('expected_return_date') }}"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                        <select name="status" id="assetStatus" class="form-select" required>
                            <option value="available" selected>Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                        <div class="form-text text-muted small">Auto-set based on Section E condition.</div>
                    </div>
                </div>

                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-clipboard-check me-2 text-primary"></i>Section E — Condition</h6>
                </div>
                <div class="row g-3 mb-2">
                    <div class="col-md-3"><label class="form-label fw-semibold">Condition <span class="text-danger">*</span></label>
                        <select name="asset_condition" id="addAssetCondition" class="form-select" required onchange="syncStatusFromConditionAdd(this.value)">
                            <option value="good"              {{ old('asset_condition','good')=='good'             ?'selected':'' }}>Good</option>
                            <option value="not_good"          {{ old('asset_condition')=='not_good'               ?'selected':'' }}>Not Good</option>
                            <option value="under_maintenance" {{ old('asset_condition')=='under_maintenance'      ?'selected':'' }}>Under Maintenance</option>
                        </select>
                        <div class="form-text">Good → Available &nbsp;|&nbsp; Under Maintenance → Unavailable</div>
                    </div>
                    <div class="col-md-3" id="addMaintenanceWrap" style="display:none;">
                        <label class="form-label fw-semibold">Maintenance Status</label>
                        <select name="maintenance_status" class="form-select">
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="done">Done</option>
                        </select>
                    </div>
                    <div class="col-md-6" id="addDecommissionReasonWrap" style="display:none;">
                        <label class="form-label fw-semibold">Decommission Reason <span class="text-danger">*</span></label>
                        <input type="text" name="decommission_reason" id="addDecommissionReason"
                               class="form-control"
                               value="{{ old('decommission_reason') }}"
                               placeholder="e.g. Screen cracked beyond repair, Water damage, Hardware failure...">
                        <div class="form-text">This reason will be shown in the Decommissioning Assets table.</div>
                    </div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Last Maintenance</label>
                        <input type="date" name="last_maintenance_date" class="form-control" value="{{ old('last_maintenance_date') }}"></div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Asset Photos <span class="text-muted fw-normal">(up to 15 photos, JPG/PNG)</span></label>
                        <div class="d-flex gap-2 mb-1" style="max-width:480px;">
                            <input type="file" id="addPhotoNewFileInput" class="form-control" accept=".jpg,.jpeg,.png" style="max-width:340px;">
                            <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" onclick="addFormPhotoFile()">
                                <i class="bi bi-upload me-1"></i>Add
                            </button>
                        </div>
                        <div id="addPhotoNewList" class="d-flex flex-wrap gap-2 mb-1"></div>
                        <div id="addPhotoNewHidden"></div>
                        <div class="form-text text-muted">Select a photo then click Add. Up to 15 photos.</div>
                        @error('asset_photos')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                        @error('asset_photos.*')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"
                                  maxlength="1500" placeholder="Any notes about this asset...">{{ old('notes') }}</textarea>
                    </div>
                </div>
                @else
                <input type="hidden" name="status" value="available">
                <input type="hidden" name="asset_condition" value="good">
                <input type="hidden" name="maintenance_status" value="pending">
                @endif
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Cancel
                </button>
                <button type="submit" class="btn btn-primary px-4">
                    <i class="bi bi-check-circle me-2"></i>Save Asset
                </button>
            </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
@if($errors->any())
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('addAssetModal')).show();
});
@endif

function toggleVendorFilter(value) {
    const wrap = document.getElementById('vendorFilterWrap');
    if (wrap) wrap.style.display = value === 'rental' ? '' : 'none';
}

function toggleDVendorFilter(value) {
    const wrap = document.getElementById('dVendorFilterWrap');
    if (wrap) wrap.style.display = value === 'rental' ? '' : 'none';
}

function toggleOwnership(value) {
    const rentalFields  = document.getElementById('rentalFields');
    const companyFields = document.getElementById('companyFields');
    if (rentalFields)  rentalFields.style.display  = value === 'rental'  ? '' : 'none';
    if (companyFields) companyFields.style.display = value === 'company' ? '' : 'none';
}

function syncAssignmentStatus() {
    const empHidden    = document.getElementById('addAssignedEmployeeId');
    const statusSelect = document.getElementById('assetStatus');
    if (!empHidden || !statusSelect) return;
    const current = statusSelect.value;
    if (empHidden.value !== '') {
        statusSelect.value = 'assigned';
    } else if (current === 'assigned') {
        statusSelect.value = 'available';
    }
}

// ── Assigned Employee searchable dropdown (Add form) ──────────────────────
function addShowEmpList() {
    const el = document.getElementById('addEmpList');
    if (el) el.style.display = '';
}
function addHideEmpList() {
    const el = document.getElementById('addEmpList');
    if (el) el.style.display = 'none';
}
function addFilterEmp(query) {
    const q = query.toLowerCase().trim();
    document.querySelectorAll('#addEmpList li').forEach(li => {
        const btn = li.querySelector('button');
        const name = btn?.dataset.empname ?? '';
        li.style.display = (!q || name.includes(q)) ? '' : 'none';
    });
    addShowEmpList();
}
function addSelectEmp(id, label) {
    const hidden = document.getElementById('addAssignedEmployeeId');
    const search = document.getElementById('addEmpSearchInput');
    if (hidden) hidden.value = id;
    if (search) search.value = id ? label : '';
    addHideEmpList();
    syncAssignmentStatus();
}

function syncStatusFromConditionAdd(condition) {
    const statusSelect  = document.getElementById('assetStatus');
    const maintWrap     = document.getElementById('addMaintenanceWrap');
    const reasonWrap    = document.getElementById('addDecommissionReasonWrap');
    const reasonInput   = document.getElementById('addDecommissionReason');
    if (statusSelect) {
        statusSelect.value = (condition === 'good') ? 'available' : 'unavailable';
    }
    if (maintWrap) {
        maintWrap.style.display = condition === 'under_maintenance' ? '' : 'none';
    }
    if (reasonWrap) {
        reasonWrap.style.display = condition === 'not_good' ? '' : 'none';
        if (reasonInput) reasonInput.required = condition === 'not_good';
    }
}

// ── Add form photo management ─────────────────────────────────────────────
let addFormPhotoFiles = [];
function addFormPhotoFile() {
    const input = document.getElementById('addPhotoNewFileInput');
    if (!input.files.length) { alert('Please select a photo first.'); return; }
    if (addFormPhotoFiles.length >= 15) { alert('Maximum 15 photos allowed.'); return; }
    addFormPhotoFiles.push(input.files[0]);
    renderAddFormPhotoList();
    input.value = '';
}
function removeAddFormPhoto(i) {
    addFormPhotoFiles.splice(i, 1);
    renderAddFormPhotoList();
}
function renderAddFormPhotoList() {
    const list   = document.getElementById('addPhotoNewList');
    const hidden = document.getElementById('addPhotoNewHidden');
    list.innerHTML = '';
    addFormPhotoFiles.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        list.innerHTML += `<div class="d-flex flex-column align-items-center gap-1" style="width:80px;">
            <img src="${url}" style="width:80px;height:70px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">
            <button type="button" class="btn btn-outline-danger btn-sm w-100 py-0"
                    style="font-size:11px;" onclick="removeAddFormPhoto(${i})">
                <i class="bi bi-x me-1"></i>Remove
            </button>
        </div>`;
    });
    const old = hidden.querySelector('input[data-add-photo]');
    if (old) old.remove();
    if (addFormPhotoFiles.length) {
        const dt = new DataTransfer();
        addFormPhotoFiles.forEach(f => dt.items.add(f));
        const inp = document.createElement('input');
        inp.type = 'file'; inp.name = 'asset_photos[]'; inp.multiple = true;
        inp.setAttribute('data-add-photo', '1'); inp.style.display = 'none';
        inp.files = dt.files;
        hidden.appendChild(inp);
    }
}

function syncAssetName(tagValue) {
    const nameInput = document.getElementById('assetNameInput');
    if (!nameInput) return;
    // Only auto-fill if user hasn't manually changed it
    if (!nameInput.dataset.manuallyEdited) {
        nameInput.value = tagValue;
    }
}

    // Auto-activate the correct tab based on URL ?tab= param
    document.addEventListener('DOMContentLoaded', function () {
        const nameInput = document.getElementById('assetNameInput');
        if (nameInput) {
            nameInput.addEventListener('input', function () {
                const tagInput = document.getElementById('assetTagInput');
                if (tagInput && this.value !== tagInput.value) {
                    this.dataset.manuallyEdited = '1';
                } else {
                    delete this.dataset.manuallyEdited;
                }
            });
            const tagInput = document.getElementById('assetTagInput');
            if (tagInput && tagInput.value && !nameInput.value) {
                nameInput.value = tagInput.value;
            }
        }

        // Activate damaged tab if ?tab=damaged is in URL
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('tab') === 'damaged') {
            const damagedTab = document.getElementById('tab-damaged');
            if (damagedTab) new bootstrap.Tab(damagedTab).show();
        }
    });

function confirmRelease(assetId, assetTag, employeeName) {
    document.getElementById('releaseAssetTag').textContent = assetTag;
    document.getElementById('releaseEmployeeName').textContent = employeeName;
    document.getElementById('releaseForm').action = '/assets/' + assetId + '/release';
    new bootstrap.Modal(document.getElementById('releaseModal')).show();
}
</script>
@endpush

{{-- ── IMPORT ERRORS FLASH ───────────────────────────────────────────────── --}}
@if(session('import_errors') && count(session('import_errors')) > 0)
<div class="alert alert-warning alert-dismissible fade show mx-3 mt-3" role="alert">
    <strong><i class="bi bi-exclamation-triangle me-1"></i>Some rows were skipped during import:</strong>
    <ul class="mb-0 mt-1">
        @foreach(session('import_errors') as $err)
            <li style="font-size:13px;">{{ $err }}</li>
        @endforeach
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── IMPORT MODAL ──────────────────────────────────────────────────────── --}}
@if(Auth::user()->canAddAsset())
<div class="modal fade" id="importModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-upload me-2 text-primary"></i>Import Assets from CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-4">
                    <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i>Before you import:</div>
                    <ol class="mb-0 ps-3" style="font-size:13px;">
                        <li>Download the <a href="{{ route('assets.import.template') }}" class="fw-semibold">CSV Template</a> and fill in your data</li>
                        <li>Required columns: <code>asset_tag</code>, <code>asset_type</code>, <code>brand</code>, <code>model</code>, <code>serial_number</code>, <code>ownership_type</code>, <code>status</code>, <code>asset_condition</code>, <code>maintenance_status</code></li>
                        <li>Valid values — <code>asset_type</code>: laptop, monitor, converter, phone, sim_card, access_card, other</li>
                        <li>Valid values — <code>ownership_type</code>: company, rental &nbsp;|&nbsp; <code>status</code>: available, assigned, under_maintenance, retired</li>
                        <li>Valid values — <code>asset_condition</code>: new, good, fair, damaged &nbsp;|&nbsp; <code>maintenance_status</code>: none, under_maintenance, repair_required</li>
                        <li>Dates in <strong>DD-MM-YYYY</strong> format (e.g. 17-01-2024)</li>
                        <li>Duplicate <code>asset_tag</code> values will be skipped</li>
                        <li>Existing assigned employees <strong>cannot</strong> be set via CSV — use the asset edit page</li>
                    </ol>
                </div>

                <form action="{{ route('assets.import') }}" method="POST" enctype="multipart/form-data" id="assetImportForm">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select CSV File</label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required
                               onchange="document.getElementById('assetImportFileLabel').textContent = this.files[0]?.name || 'No file chosen'">
                        <div id="assetImportFileLabel" class="form-text text-muted mt-1">No file chosen</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <a href="{{ route('assets.import.template') }}" class="btn btn-outline-secondary me-auto">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Download Template
                </a>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="assetImportForm" class="btn btn-primary px-4">
                    <i class="bi bi-upload me-1"></i>Import
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── RELEASE CONFIRMATION MODAL ─────────────────────────────────────────── --}}
@if(Auth::user()->canEditAsset())
<div class="modal fade" id="releaseModal" tabindex="-1" aria-labelledby="releaseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h6 class="modal-title fw-bold" id="releaseModalLabel">
                    <i class="bi bi-person-dash me-2"></i>Release Asset Assignment
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Are you sure you want to release:</p>
                <p class="fw-bold mb-1" id="releaseAssetTag"></p>
                <p class="text-muted small mb-0">from <span id="releaseEmployeeName" class="fw-semibold text-dark"></span>?</p>
                <div class="alert alert-warning mt-3 mb-0 py-2 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    This will remove the asset assignment and notify the employee via email.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="releaseForm" method="POST" style="display:inline;">
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