@extends('layouts.app')
@section('title', 'Asset Listing')
@section('page-title', 'Asset Listing')

@section('content')

@include('partials.asset-overview-widget')

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
                @php $catCfgFilter = config('asset-categories.categories', []); @endphp
                <select name="category" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    @foreach($catCfgFilter as $k => $label)
                        <option value="{{ $k }}" {{ request('category')==$k?'selected':'' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach(['laptop','monitor','converter','phone','sim_card','access_card','petty_cash','accessories','furniture','equipment','other'] as $t)
                        <option value="{{ $t }}" {{ request('type')==$t?'selected':'' }}>
                            {{ ucfirst(str_replace('_',' ',$t)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="brand" class="form-select form-select-sm">
                    <option value="">All Brands</option>
                    @foreach($filterBrands as $b)
                        <option value="{{ $b }}" {{ request('brand')==$b?'selected':'' }}>{{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="ownership" class="form-select form-select-sm" id="ownershipFilter">
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
            <div class="col-md-2">
                <select name="company_name" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    @foreach($registeredCompanies as $rc)
                        <option value="{{ $rc->name }}" {{ request('company_name')==$rc->name?'selected':'' }}>{{ $rc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['search','status','category','type','brand','ownership','vendor','company_name']))
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
                                <a href="{{ route('assets.show', array_merge(request()->query(), ['asset' => $a->id])) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                @if(Auth::user()->canEditAsset())
                                    <a href="{{ route('assets.edit', array_merge(request()->query(), ['asset' => $a->id])) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>
                                    @if($a->status === 'assigned')
                                    <button type="button"
                                            class="btn btn-sm btn-danger release-asset-btn"
                                            title="Release asset from employee"
                                            data-asset-id="{{ $a->id }}"
                                            data-asset-tag="{{ $a->asset_tag }}"
                                            data-employee-name="{{ $a->resolvedAssigneeName() }}">
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
                <select name="d_ownership" class="form-select form-select-sm" id="dOwnershipFilter">
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
                Assets marked as <strong>Not Good</strong> or <strong>Returned</strong> are removed from the active listing and tracked here.
                <strong>Not Good</strong> assets are decommissioned as e-waste; <strong>Returned</strong> assets go back to the rental vendor.
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
                        <th>Status</th>
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
                        <td>
                            {{-- The staging row's own snapshot, not the live asset's current condition. --}}
                            <span class="badge bg-{{ $d->isVendorReturn() ? 'warning text-dark' : 'danger' }}">
                                {{ \App\Models\AssetInventory::CONDITIONS[$d->asset_condition] ?? ($d->isVendorReturn() ? 'Returned' : 'Not Good') }}
                            </span>
                        </td>
                        <td>
                            @if($d->isVendorReturn())
                                <span class="badge bg-primary"><i class="bi bi-arrow-return-left me-1"></i>Return</span>
                            @else
                                <span class="badge bg-danger"><i class="bi bi-recycle me-1"></i>E-waste</span>
                            @endif
                        </td>
                        <td style="max-width:180px;white-space:normal;">{{ $d->reason ?? '—' }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                @if($d->asset)
                                    <a href="{{ route('assets.disposed.show', array_merge(request()->query(), ['asset' => $d->asset->id])) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    @if(Auth::user()->canEditAsset())
                                    <a href="{{ route('assets.edit', array_merge(request()->query(), ['asset' => $d->asset->id])) }}"
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
                @php $catCfg = config('asset-categories'); @endphp
                <div class="section-header mb-3">
                    <h6 class="mb-0"><i class="bi bi-tag me-2 text-primary"></i>Section A — Asset Identification</h6>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Asset ID / Tag <span class="text-danger">*</span></label>
                        <input type="text" name="asset_tag" id="assetTagInput"
                               class="form-control @error('asset_tag') is-invalid @enderror"
                               value="{{ old('asset_tag') }}" placeholder="e.g. LPT-003"
                               required>
                        @error('asset_tag')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                        <label class="form-label fw-semibold">Asset Category <span class="text-danger">*</span></label>
                        <select name="asset_category" id="addAssetCategory" class="form-select @error('asset_category') is-invalid @enderror" required>
                            <option value="">Select category...</option>
                            @foreach($catCfg['categories'] as $k => $label)
                                <option value="{{ $k }}" {{ old('asset_category')==$k?'selected':'' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('asset_category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Asset Type <span class="text-danger">*</span></label>
                        <select name="asset_type" id="addAssetType" class="form-select @error('asset_type') is-invalid @enderror" required>
                            <option value="">Select category first...</option>
                        </select>
                        @error('asset_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3" id="addBrandContainer">
                        <label class="form-label fw-semibold">Brand <span class="text-danger">*</span></label>
                        <select name="brand" id="addBrandSelect" class="form-select @error('brand') is-invalid @enderror" required>
                            <option value="">Select type first...</option>
                        </select>
                        <input type="text" name="brand" id="addBrandText" class="form-control @error('brand') is-invalid @enderror"
                               placeholder="Enter brand" style="display:none" disabled>
                        @error('brand')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Model <span class="text-danger">*</span></label>
                        <input type="text" name="model" id="addModelInput"
                               class="form-control @error('model') is-invalid @enderror"
                               value="{{ old('model') }}"
                               list="addModelSuggestions"
                               autocomplete="off"
                               placeholder="Pick from list or type your own" required>
                        <datalist id="addModelSuggestions"></datalist>
                        <div class="form-text text-muted small" id="addModelHint" style="display:none;">
                            <i class="bi bi-lightbulb me-1"></i>Suggestions are filled from common market models. You can also type any other model.
                        </div>
                        @error('model')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                                <input class="form-check-input add-ownership-radio" type="radio" name="ownership_type" id="own_company" value="company"
                                    {{ old('ownership_type','company') === 'company' ? 'checked' : '' }}>
                                <label class="form-check-label fw-semibold" for="own_company"><i class="bi bi-building me-1 text-primary"></i>Company Owned</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input add-ownership-radio" type="radio" name="ownership_type" id="own_rental" value="rental"
                                    {{ old('ownership_type') === 'rental' ? 'checked' : '' }}>
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
                    <div class="col-md-4"><label class="form-label fw-semibold">Invoice(s)</label>
                        <input type="file" name="invoice_documents[]" id="companyInvoiceInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png" multiple>
                        <div class="form-text text-muted small">PDF or images. Multiple files allowed.</div></div>
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
                    <div class="col-md-3"><label class="form-label fw-semibold">Invoice(s)</label>
                        <input type="file" name="invoice_documents[]" id="rentalInvoiceInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png" multiple disabled>
                        <div class="form-text text-muted small">PDF or images. Multiple files allowed.</div></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Contract Document(s)</label>
                        <input type="file" name="rental_contract_documents[]" class="form-control" accept=".pdf,.jpg,.jpeg,.png" multiple>
                        <div class="form-text text-muted small">Upload rental/lease contract. PDF or images.</div></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Supplied To (Company)</label>
                        <select name="company_supplied_to" class="form-select">
                            <option value="">— Select Company —</option>
                            @foreach($registeredCompanies as $rc)
                            <option value="{{ $rc->name }}" {{ old('company_supplied_to') == $rc->name ? 'selected' : '' }}>
                                {{ $rc->name }}
                            </option>
                            @endforeach
                        </select></div>
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
                                   value="{{ $addPreselectedLabel }}">
                            <ul id="addEmpList"
                                class="list-unstyled border rounded bg-white shadow-sm position-absolute mb-0"
                                style="z-index:1060;max-height:200px;overflow-y:auto;display:none;top:100%;left:0;min-width:100%;width:max-content;max-width:480px;">
                                <li>
                                    <button type="button" class="dropdown-item"
                                            data-empid="" data-emplabel="— Not Assigned —">
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
                                            data-empid="{{ $emp->id }}" data-emplabel="{{ $empLabel }}"
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
                        <select name="asset_condition" id="addAssetCondition" class="form-select" required>
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
                        <div class="mb-2">
                            <input type="file" id="addPhotoNewFileInput" class="form-control" accept=".jpg,.jpeg,.png" multiple style="max-width:480px;">
                        </div>
                        <div id="addPhotoNewList" class="d-flex flex-wrap gap-2 mb-1"></div>
                        <div id="addPhotoNewHidden"></div>
                        <div id="addPhotoCompressStatus" class="text-muted small mb-1" style="display:none;"></div>
                        <div class="form-text text-muted">Select one or more photos (max 15). Photos are auto-compressed before upload.</div>
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
<script nonce="{{ $cspNonce ?? '' }}">
@if($errors->any())
document.addEventListener('DOMContentLoaded', function () {
    new bootstrap.Modal(document.getElementById('addAssetModal')).show();
});
@endif

function toggleVendorFilter(value) {
    const wrap = document.getElementById('vendorFilterWrap');
    if (wrap) wrap.style.display = value === 'rental' ? '' : 'none';
}
var ownershipFilter = document.getElementById('ownershipFilter');
if (ownershipFilter) {
    toggleVendorFilter(ownershipFilter.value);
    ownershipFilter.addEventListener('change', function() { toggleVendorFilter(this.value); });
}

function toggleDVendorFilter(value) {
    const wrap = document.getElementById('dVendorFilterWrap');
    if (wrap) wrap.style.display = value === 'rental' ? '' : 'none';
}
var dOwnershipFilter = document.getElementById('dOwnershipFilter');
if (dOwnershipFilter) {
    toggleDVendorFilter(dOwnershipFilter.value);
    dOwnershipFilter.addEventListener('change', function() { toggleDVendorFilter(this.value); });
}

// Condition change listener for add form
var addCondSelect = document.getElementById('addAssetCondition');
if (addCondSelect) {
    syncStatusFromConditionAdd(addCondSelect.value);
    addCondSelect.addEventListener('change', function() { syncStatusFromConditionAdd(this.value); });
}

// CSV import file label
var importFileInput = document.getElementById('assetImportFileInput');
if (importFileInput) {
    importFileInput.addEventListener('change', function() {
        document.getElementById('assetImportFileLabel').textContent = this.files[0]?.name || 'No file chosen';
    });
}

// ── Ownership toggle (Add form) ──────────────────────────────────────
(function () {
    function toggleOwnership(value) {
        var rentalFields  = document.getElementById('rentalFields');
        var companyFields = document.getElementById('companyFields');
        if (rentalFields)  rentalFields.style.display  = value === 'rental'  ? '' : 'none';
        if (companyFields) companyFields.style.display = value === 'company' ? '' : 'none';
        var companyInvoice = document.getElementById('companyInvoiceInput');
        var rentalInvoice  = document.getElementById('rentalInvoiceInput');
        if (companyInvoice) companyInvoice.disabled = (value !== 'company');
        if (rentalInvoice)  rentalInvoice.disabled  = (value !== 'rental');
    }
    document.querySelectorAll('.add-ownership-radio').forEach(function (radio) {
        radio.addEventListener('change', function () { toggleOwnership(this.value); });
    });
})();

function syncAssignmentStatus() {
    const empHidden    = document.getElementById('addAssignedEmployeeId');
    const statusSelect = document.getElementById('assetStatus');
    const condSelect   = document.getElementById('addAssetCondition');
    if (!empHidden || !statusSelect) return;

    if (empHidden.value !== '') {
        // Employee assigned → unavailable (locked to assignee)
        statusSelect.value = 'unavailable';
    } else {
        // No employee → status driven by condition
        const condition = condSelect ? condSelect.value : 'good';
        statusSelect.value = (condition === 'good') ? 'available' : 'unavailable';
    }
}

// ── Assigned Employee searchable dropdown (Add form) ──────────────────────
(function () {
    const searchInput = document.getElementById('addEmpSearchInput');
    const empList     = document.getElementById('addEmpList');
    const hiddenInput = document.getElementById('addAssignedEmployeeId');
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
        syncAssignmentStatus();
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

    // Expose for external callers (syncAssignmentStatus, etc.)
    window.addSelectEmp = selectEmp;
})();

function syncStatusFromConditionAdd(condition) {
    const statusSelect  = document.getElementById('assetStatus');
    const empHidden     = document.getElementById('addAssignedEmployeeId');
    const maintWrap     = document.getElementById('addMaintenanceWrap');
    const reasonWrap    = document.getElementById('addDecommissionReasonWrap');
    const reasonInput   = document.getElementById('addDecommissionReason');
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
    }
    if (reasonWrap) {
        reasonWrap.style.display = condition === 'not_good' ? '' : 'none';
        if (reasonInput) reasonInput.required = condition === 'not_good';
    }
}

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

// ── Add form photo management ─────────────────────────────────────────────
let addFormPhotoFiles = [];
document.getElementById('addPhotoNewFileInput').addEventListener('change', async function() {
    const files = Array.from(this.files);
    if (!files.length) return;
    const remaining = 15 - addFormPhotoFiles.length;
    if (remaining <= 0) { alert('Maximum 15 photos allowed.'); this.value = ''; return; }
    const toAdd = files.slice(0, remaining);
    if (files.length > remaining) alert(`Only ${remaining} more photo(s) can be added. Extra files were skipped.`);
    const status = document.getElementById('addPhotoCompressStatus');
    status.style.display = '';
    status.textContent = `Compressing ${toAdd.length} photo(s)...`;
    for (let i = 0; i < toAdd.length; i++) {
        status.textContent = `Compressing photo ${i + 1} of ${toAdd.length}...`;
        const compressed = await compressImage(toAdd[i]);
        addFormPhotoFiles.push(compressed);
    }
    status.style.display = 'none';
    renderAddFormPhotoList();
    this.value = '';
});
function renderAddFormPhotoList() {
    const list   = document.getElementById('addPhotoNewList');
    const hidden = document.getElementById('addPhotoNewHidden');
    list.innerHTML = '';
    addFormPhotoFiles.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        const sizeKB = (f.size / 1024).toFixed(0);
        list.innerHTML += `<div class="d-flex flex-column align-items-center gap-1" style="width:80px;">
            <img src="${url}" style="width:80px;height:70px;object-fit:cover;border-radius:4px;border:1px solid #dee2e6;">
            <span class="text-muted" style="font-size:10px;">${sizeKB} KB</span>
            <button type="button" class="btn btn-outline-danger btn-sm w-100 py-0 add-photo-remove-btn"
                    style="font-size:11px;" data-index="${i}">
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
// Event delegation for dynamically-rendered add-form photo Remove buttons
document.getElementById('addPhotoNewList').addEventListener('click', function (e) {
    var btn = e.target.closest('.add-photo-remove-btn');
    if (!btn) return;
    var idx = parseInt(btn.dataset.index, 10);
    addFormPhotoFiles.splice(idx, 1);
    renderAddFormPhotoList();
});

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
 * Suggestions come from config('asset-categories.models')[type][brand].
 * Free-text entry is always allowed — this is a hint, not a constraint.
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
        // No predefined brands — use text input
        brandSelect.style.display = 'none';
        brandSelect.disabled = true;
        brandSelect.required = false;
        brandText.style.display = '';
        brandText.disabled = false;
        brandText.required = true;
        if (preselected && !brandText.value) brandText.value = preselected;
        return;
    }

    // Predefined brands — populate dropdown
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

// ── Add-Asset modal — cascading init ──
(function () {
    const catSelect   = document.getElementById('addAssetCategory');
    const typeSelect  = document.getElementById('addAssetType');
    const brandSelect = document.getElementById('addBrandSelect');
    const brandText   = document.getElementById('addBrandText');
    if (!catSelect || !typeSelect || !brandSelect) return;

    const oldType  = @json(old('asset_type', ''));
    const oldBrand = @json(old('brand', ''));

    // Read whichever brand input is currently active (select for known brands, text otherwise)
    function currentBrand() {
        if (brandSelect.style.display !== 'none' && !brandSelect.disabled) return brandSelect.value || '';
        if (brandText.style.display   !== 'none' && !brandText.disabled)   return brandText.value   || '';
        return '';
    }

    function refreshModels() {
        updateModelSuggestions(typeSelect.value, currentBrand(), 'addModelSuggestions', 'addModelHint');
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

    // Initialise on load (handles old() repopulation after validation error)
    if (catSelect.value) {
        updateTypeDropdown(catSelect, typeSelect, oldType);
        if (typeSelect.value) {
            updateBrandField(typeSelect.value, brandSelect, brandText, oldBrand);
        }
    }
    refreshModels();
})();

    // Auto-activate the correct tab based on URL ?tab= param
    document.addEventListener('DOMContentLoaded', function () {
        const nameInput = document.getElementById('assetNameInput');
        const tagInput  = document.getElementById('assetTagInput');

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

            // Initialise on load (handles old() repopulation after validation error)
            if (tagInput.value && !nameInput.value) {
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

// ── Release button (event delegation) ────────────────────────────────
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.release-asset-btn');
    if (!btn) return;
    document.getElementById('releaseAssetTag').textContent = btn.dataset.assetTag;
    document.getElementById('releaseEmployeeName').textContent = btn.dataset.employeeName;
    document.getElementById('releaseForm').action = '/assets/' + btn.dataset.assetId + '/release';
    new bootstrap.Modal(document.getElementById('releaseModal')).show();
});
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
                               id="assetImportFileInput">
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