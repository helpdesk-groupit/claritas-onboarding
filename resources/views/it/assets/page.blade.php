@extends('layouts.app')
@section('title', 'Asset Listing')
@section('page-title', 'Asset Listing')

@section('content')

@include('partials.asset-overview-widget')
{{-- Backs the ewx- classes used throughout the "Company Asset Decommissioning" tab (gradient
     card heads, chips, the archive table) — this page never included it before, so that whole
     tab rendered with none of its intended styling. --}}
@include('partials.decommission-ui-style')

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
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $activeTab === 'company-decom' ? 'active' : '' }}" id="tab-company-decom"
                data-bs-toggle="tab" data-bs-target="#pane-company-decom" type="button" role="tab">
            <i class="bi bi-building-gear me-1 text-warning"></i>Company Asset Decommissioning
            <span class="badge bg-warning text-dark ms-1" style="font-size:10px;">{{ $readyForSweep->count() + $openBatches->count() }}</span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $activeTab === 'reports' ? 'active' : '' }}" id="tab-reports"
                data-bs-toggle="tab" data-bs-target="#pane-reports" type="button" role="tab">
            <i class="bi bi-file-earmark-text me-1 text-success"></i>Reports
            <span class="badge bg-success ms-1" style="font-size:10px;">{{ $reportsCount }}</span>
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
                       placeholder="Tag, brand, model, serial, notes..." value="{{ request('search') }}">
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

    {{-- Create Collection Batch stays here — it raises RETURN AARFs (rental), not e-waste
         cycles, so it belongs beside the queue it selects rows from, not the e-waste tab. The
         "Run e-waste sweep now" button moved to the Company Asset Decommissioning tab so there
         is one, not two. --}}
    @if($canDecommission)
    <div class="card-body border-bottom d-flex flex-wrap gap-2 align-items-center">
        <button type="button" class="btn btn-sm btn-primary" id="createBatchBtn" data-bs-toggle="modal" data-bs-target="#createBatchModal" disabled>
            <i class="bi bi-box-arrow-up me-1"></i>Create Collection Batch
            <span class="badge bg-light text-dark ms-1" id="batchSelCount">0</span>
        </button>
    </div>
    @endif

    {{-- Open batches / cycles / unsigned return forms.
         An unsigned return form is open work in the same sense a running e-waste cycle is:
         assets are committed to a document nobody has signed, so they are still ours. --}}
    @if($openBatches->isNotEmpty() || $openReturnForms->isNotEmpty())
    <div class="card-body border-bottom py-2">
        <div class="fw-semibold small text-muted mb-2"><i class="bi bi-collection me-1"></i>Open cycles &amp; unsigned return forms</div>
        <div class="d-flex flex-wrap gap-2">
            @foreach($openReturnForms as $rf)
            <a href="{{ route('vendors.aarf.show', [$rf->vendor_id, $rf]) }}" class="text-decoration-none">
                <span class="badge bg-light text-dark border">
                    {{ $rf->reference }} · Return · {{ $rf->vendor?->name ?? '—' }} ·
                    {{ $rf->items_count }} asset{{ $rf->items_count === 1 ? '' : 's' }} ·
                    <span class="text-secondary">Awaiting signature</span>
                </span>
            </a>
            @endforeach
            @foreach($openBatches as $b)
            @php [$bClass,$bLabel] = $b->statusBadge(); @endphp
            <a href="{{ route('decommission.show', $b) }}" class="text-decoration-none">
                <span class="badge bg-light text-dark border">{{ $b->batch_number }} · {{ $b->typeLabel() }} · <span class="text-{{ $bClass }}">{{ $bLabel }}</span></span>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Decommissioning Filters --}}
    <div class="card-body border-bottom pb-3">
        <form action="{{ route('assets.index') }}" method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="damaged">
            <div class="col-md-3">
                <input type="text" name="d_search" class="form-control form-control-sm"
                       placeholder="Tag, brand, model..." value="{{ request('d_search') }}">
            </div>
            <div class="col-md-2">
                <select name="d_decotype" class="form-select form-select-sm">
                    <option value="">All Conditions</option>
                    <option value="vendor_return" {{ request('d_decotype')==='vendor_return'?'selected':'' }}>Returned</option>
                    <option value="e_waste" {{ request('d_decotype')==='e_waste'?'selected':'' }}>Not Good</option>
                </select>
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
                @if(request()->hasAny(['d_search','d_type','d_decotype','d_ownership','d_vendor']))
                    <a href="{{ route('assets.index', ['tab'=>'damaged']) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="px-3 pt-3 pb-2">
            <p class="text-muted small mb-0">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Not Good</strong> assets are staged for <strong>e-waste</strong>; <strong>Returned</strong> assets are staged for <strong>vendor return</strong>.
                {{-- The call to action only makes sense for someone who has the button. A viewer
                     (it_intern, HR) reads this list but cannot act on it. --}}
                @if($canDecommission)Tick the returned assets below and click <em>Create Collection Batch</em> — the vendor is read off each asset, and one Asset Acceptance &amp; Return Form (AARF) is raised per vendor and company rented to.@else This list is read-only for your role.@endif
            </p>
            @if($canDecommission && $awaitingInspection > 0)
                {{-- The gate is absolute: one unfinished row postpones the whole quarter, so
                     the count has to be on the page where it is fixed, not only in an email. --}}
                <p class="text-danger small mb-0 mt-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>{{ $awaitingInspection }}</strong> e-waste asset{{ $awaitingInspection === 1 ? '' : 's' }}
                    still awaiting inspection. The quarterly collection cycle will not run until every one is inspected and its owning company confirmed.
                </p>
            @endif
        </div>
        @if($disposed->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-check-circle" style="font-size:40px;color:#16a34a;"></i>
                <p class="mt-2">No assets awaiting decommissioning.</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        @if($canDecommission)<th class="ps-3" style="width:34px;"></th>@endif
                        <th class="{{ $canDecommission ? '' : 'ps-3' }}">Asset Tag</th>
                        <th>Type</th>
                        <th>Brand / Model</th>
                        <th>Serial Number</th>
                        <th>Condition</th>
                        <th>Inspection</th>
                        <th>Return To / Batch</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($disposed as $d)
                    @php
                        $isReturn = $d->decommission_type === 'vendor_return';
                        // The form an asset already sits on. Selection is gated on its ABSENCE,
                        // not on decommission_batch_id — a return no longer creates a batch, so
                        // that column stays null for the whole of its life and would have left
                        // every returned asset selectable forever, including ones already signed
                        // for on somebody else's form.
                        $onForm = $isReturn ? ($returnForms[$d->asset_inventory_id] ?? null) : null;
                        // Only a RENTAL asset's vendor is a return destination. On a
                        // company-owned asset the same FK means "purchased from" (since
                        // 2026-08-06), so reading it here would print the supplier we BOUGHT
                        // the laptop from as the party it is going back to — and would hide
                        // the "Not a rental" warning that is the actual problem with the row.
                        $isRental = $d->asset?->ownership_type === 'rental';
                        $rtVendor = $isReturn && $isRental ? $d->asset?->vendor : null;
                        // A return with no resolvable vendor (not a rental, or a rental with
                        // no linked vendor record) can never produce a form — planReturns()
                        // skips it server-side regardless. Ticking it just to have it silently
                        // dropped from the batch reads as broken, so it gets no checkbox at
                        // all, same as a row already on a form. It stays visible in the queue
                        // (the "No vendor linked" / "Not a rental" badge says why) — this only
                        // blocks selecting it for a batch, it never touches the asset itself.
                        $selectable = $canDecommission && $isReturn && ! $onForm && (bool) $rtVendor;
                    @endphp
                    <tr>
                        @if($canDecommission)
                        <td class="ps-3">
                            @if($selectable)
                                <input type="checkbox" class="form-check-input js-batch-check"
                                       value="{{ $d->id }}"
                                       data-tag="{{ $d->asset_tag }}"
                                       data-label="{{ trim(($d->brand ?? '').' '.($d->model ?? '')) }}"
                                       data-vendor="{{ $rtVendor?->name ?? '' }}"
                                       data-company="{{ $d->asset?->company_supplied_to ?? '' }}"
                                       data-rental="{{ $d->asset?->ownership_type === 'rental' ? '1' : '0' }}">
                            @endif
                        </td>
                        @endif
                        <td class="{{ $canDecommission ? '' : 'ps-3' }}"><code>{{ $d->asset_tag }}</code></td>
                        <td>{{ ucfirst(str_replace('_',' ', $d->asset_type)) }}</td>
                        <td>{{ $d->brand }} {{ $d->model }}</td>
                        <td class="text-muted">{{ $d->serial_number ?? '—' }}</td>
                        <td>
                            @if($d->decommission_type === 'vendor_return')
                                <span class="badge bg-info text-dark">Returned</span>
                            @else
                                <span class="badge bg-danger">Not Good</span>
                            @endif
                        </td>
                        {{-- Inspection. E-waste only: a returned rental asset is examined by the
                             collector when they sign its return form, not here. The quarterly
                             cycle refuses to run while any row in this column is unfinished, so
                             it states BOTH halves — the verdict and the confirmed owner. --}}
                        <td style="max-width:190px;white-space:normal;">
                            @if($isReturn)
                                <span class="text-muted small" title="Examined by the collector on the return form.">n/a</span>
                            @else
                                @php $insp = $d->inspectionBadge(); @endphp
                                <span class="badge bg-{{ $insp['color'] }}">{{ $insp['label'] }}</span>
                                @if($d->isInspected())
                                    @if($d->isIncomplete() && $d->ewaste_parts_removed)
                                        <div class="text-muted" style="font-size:11px;">Removed: {{ $d->ewaste_parts_removed }}</div>
                                    @endif
                                    <div class="text-muted" style="font-size:11px;">
                                        {{ fmt_date($d->inspected_at) }}@if($d->inspector) · {{ $d->inspector->name }}@endif
                                    </div>
                                @endif
                                {{-- The owner is half of "ready": without it nobody is authorised
                                     to approve this asset's disposal, so an inspected-but-
                                     unresolved row must not read as finished. --}}
                                @if($d->company)
                                    <div class="text-muted" style="font-size:11px;"><i class="bi bi-building me-1"></i>{{ $d->company }}</div>
                                @elseif($d->isInspected())
                                    <div class="text-danger" style="font-size:11px;">Owner not confirmed</div>
                                @endif
                            @endif
                        </td>
                        {{-- Return To / Batch. For a return this is the vendor the asset goes
                             back to (read off the FK) plus the form it is on, if any; an asset
                             with no linked vendor is called out here, because that is the one
                             thing that stops a form being raised for it. --}}
                        <td style="max-width:200px;white-space:normal;">
                            @if($isReturn)
                                @if(! $isRental)
                                    <span class="badge bg-warning text-dark" title="Company-owned assets have no rental vendor to go back to — send this one to e-waste instead.">Not a rental</span>
                                @elseif($rtVendor)
                                    <div class="small fw-semibold">{{ $rtVendor->name }}</div>
                                    @if($d->asset?->company_supplied_to)
                                        <div class="text-muted" style="font-size:11px;">for {{ $d->asset->company_supplied_to }}</div>
                                    @endif
                                @else
                                    <span class="badge bg-warning text-dark" title="Link the rental vendor on the asset record before a return form can be raised.">No vendor linked</span>
                                @endif
                                @if($onForm)
                                    <div class="mt-1">
                                        <a href="{{ route('vendors.aarf.show', [$onForm->vendor_id, $onForm]) }}" class="text-decoration-none small">
                                            {{ $onForm->reference }}
                                        </a>
                                        <span class="badge bg-{{ $onForm->statusBadge()['color'] }} ms-1">{{ $onForm->statusBadge()['label'] }}</span>
                                    </div>
                                @endif
                            @elseif($d->batch)
                                <a href="{{ route('decommission.show', $d->batch) }}" class="text-decoration-none">{{ $d->batch->batch_number }}</a>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td style="max-width:180px;white-space:normal;">{{ $d->reason ?? '—' }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                {{-- Inspect. Offered while the asset is still e-waste and not yet
                                     swept into a cycle — after that the vendor has quoted against
                                     what was recorded. Re-inspecting before then is deliberate:
                                     correcting a verdict costs nothing until the RFQ goes out. --}}
                                @if($canDecommission && ! $isReturn && ! $d->decommission_batch_id)
                                    <button type="button"
                                            class="btn btn-sm {{ $d->isReadyForCycle() ? 'btn-outline-success' : 'btn-warning' }} js-inspect-btn"
                                            title="{{ $d->isInspected() ? 'Re-inspect this asset' : 'Record the inspection' }}"
                                            data-id="{{ $d->id }}"
                                            data-tag="{{ $d->asset_tag }}"
                                            data-label="{{ trim(($d->brand ?? '').' '.($d->model ?? '')) }}"
                                            data-completeness="{{ $d->ewaste_completeness ?? '' }}"
                                            data-company="{{ $d->company ?? $d->asset?->company_name ?? '' }}"
                                            data-reason="{{ $d->reason ?? '' }}"
                                            data-needs-reason="{{ blank($d->reason) ? '1' : '0' }}">
                                        <i class="bi bi-clipboard-check"></i>
                                    </button>
                                @endif
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

{{-- ══════════════ TAB 3: COMPANY ASSET DECOMMISSIONING ══════════════ --}}
<div class="tab-pane fade {{ $activeTab === 'company-decom' ? 'show active' : '' }}" id="pane-company-decom" role="tabpanel">

{{-- ══════════════ Operations ══════════════
     IT's own working queue: what the next sweep will gather, and the button that runs it.
     Placed first — this is IT's own tab, and the queue/sweep button is what an IT operator
     is here to act on; the Finance/management review surface below is largely read-only for
     them. Ready-for-sweep sits ABOVE the sweep button so the operator sees what is about to
     be gathered before pressing it, not after. The full cycle history (including these same
     in-flight cycles) follows immediately below as the archive table — one continuous zone,
     not two competing lists. --}}
<div class="section-header mb-3">
    <h6 class="mb-0"><i class="bi bi-gear-wide-connected me-2 text-primary"></i>Operations</h6>
</div>
<div class="card ewx-card mb-4">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-blue"><i class="bi bi-clipboard-check"></i></span>
        <div class="me-2">
            <span class="ewx-title">Ready for the next sweep</span>
            <span class="ewx-sub">Inspected assets waiting to be gathered into a cycle.</span>
        </div>
        <span class="ewx-count">{{ $readyForSweep->count() }}</span>
    </div>
    <div class="card-body border-bottom">
        @if($readyForSweep->isEmpty())
            <p class="text-muted small mb-0">No inspected assets waiting — everything ready has already been swept into a cycle.</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr><th>Asset Tag</th><th>Type</th><th>Brand / Model</th><th>Company</th><th>Completeness</th><th>Inspected</th></tr>
                </thead>
                <tbody>
                @foreach($readyForSweep as $r)
                    <tr>
                        <td>{{ $r->asset_tag }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $r->asset_type ?? '—')) }}</td>
                        <td>{{ trim(($r->brand ?? '').' '.($r->model ?? '')) ?: '—' }}</td>
                        <td>{{ $r->company }}</td>
                        <td>
                            @php $badge = $r->inspectionBadge(); @endphp
                            <span class="badge bg-{{ $badge['color'] }}">{{ $badge['label'] }}</span>
                        </td>
                        <td class="text-muted">{{ fmt_datetime($r->inspected_at) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    @if($canDecommission)
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <form action="{{ route('ewaste.sweep') }}" method="POST" class="js-confirm" data-confirm="Run the e-waste sweep now? This gathers all inspected Not-Good assets into a new quarterly cycle and requests a quotation from every e-waste vendor, then reports to Finance and management." data-confirm-title="Run e-waste sweep" data-confirm-ok="Run sweep" data-confirm-variant="success">@csrf
            <button class="btn btn-sm btn-success"><i class="bi bi-recycle me-1"></i>Run e-waste sweep now</button>
        </form>
        <span class="text-muted small">
            RFQ goes to
            @if($ewasteRfqVendorCount)<strong>{{ $ewasteRfqVendorCount }}</strong> vendor{{ $ewasteRfqVendorCount === 1 ? '' : 's' }}@else<span class="text-danger">no vendor</span> — <a href="{{ route('vendors.index') }}">configure</a>@endif
        </span>
    </div>
    @endif
</div>

{{-- The Finance/management review surface (stat strip, filter, cycles-in-review decide panel).
     $awaiting is normally empty for an ordinary IT/HR viewer (they aren't Finance/management);
     it only shows a decision to a superadmin who is also a named approver. --}}
@include('it.assets._decommission-review-summary', [
    'decomStats' => $decomStats, 'cdFilters' => $cdFilters, 'companyOptions' => $companyOptions, 'statusOptions' => $statusOptions,
    'awaiting' => $awaiting, 'canFinance' => $canFinance, 'ewasteVendors' => $ewasteVendors,
])

{{-- Every cycle still in flight, grouped by company — completed cycles are the Reports tab's
     job now (see AssetController::ewasteCycleReportsFor()). --}}
@include('it.assets._decommission-review-by-company', ['activeByCompany' => $activeByCompany, 'cdFilters' => $cdFilters])
</div>{{-- /pane-company-decom --}}

{{-- ══════════════ TAB 4: REPORTS ══════════════
     Completed e-waste cycles only, nested Year → Month → Company, archived here. Reuses the
     SAME batches, the SAME PDF and the SAME view/download routes as the Finance/management-only
     decommission-review.blade.php; this is another access point onto the same records, not a
     second report-generation path. --}}
<div class="tab-pane fade {{ $activeTab === 'reports' ? 'show active' : '' }}" id="pane-reports" role="tabpanel">
@include('it.assets._decommission-reports-pane', [
    'reportGroups' => $reportGroups, 'reportsCount' => $reportsCount, 'reportFilteredCount' => $reportFilteredCount,
    'reportCompanyOptions' => $reportCompanyOptions, 'reportVendorOptions' => $reportVendorOptions, 'rpFilters' => $rpFilters,
])
</div>{{-- /pane-reports --}}

{{-- Inspection modal (Phase 2). ONE modal for every row, populated from the clicked button's
     data-* attributes — a modal per row would multiply with the queue, and `old()` is global,
     so a validation bounce would repopulate all of them with one row's rejected input. --}}
@if($canDecommission)
<div class="modal fade" id="inspectModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h6 class="modal-title text-white fw-bold"><i class="bi bi-clipboard-check me-2"></i>Inspect Asset</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="inspectForm" action="">@csrf
                <input type="hidden" name="_form" value="inspect">
                <div class="modal-body">
                    <p class="mb-3">
                        <code id="inspectTag"></code>
                        <span class="text-muted small" id="inspectLabel"></span>
                    </p>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Completeness <span class="text-danger">*</span></label>
                        <select name="ewaste_completeness" id="inspectCompleteness" class="form-select" required>
                            <option value="">— select —</option>
                            @foreach(\App\Models\DisposedAsset::COMPLETENESS as $val => $label)
                                <option value="{{ $val }}">{{ $label }}{{ $val === 'complete' ? ' — all parts intact' : ' — parts removed' }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">The e-waste vendor prices against this.</div>
                    </div>

                    {{-- The company is read off the asset's own record — IT no longer picks it by
                         hand in the common case. The select stays as a FALLBACK, shown only when
                         the asset's own company does not cleanly match a registered one, matching
                         what the server independently re-derives (it never trusts this field's
                         value when it can resolve one itself). --}}
                    <div class="mb-3" id="inspectCompanyConfirmed" style="display:none;">
                        <label class="form-label fw-semibold">Owning Company</label>
                        <div class="form-control-plaintext py-1"><i class="bi bi-building me-1 text-muted"></i><strong id="inspectCompanyName"></strong></div>
                        <div class="form-text">Read from the asset's own record — decides which company's management approves this disposal.</div>
                    </div>
                    <div class="mb-3" id="inspectCompanyPicker" style="display:none;">
                        <label class="form-label fw-semibold">Owning Company <span class="text-danger">*</span></label>
                        <select name="company" id="inspectCompany" class="form-select">
                            <option value="">— select —</option>
                            @foreach($registeredCompanies as $co)
                                <option value="{{ $co->name }}">{{ $co->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">This asset's own company could not be matched to a registered one — confirm which company owns it.</div>
                    </div>

                    <div class="mb-1" id="inspectReasonWrap" style="display:none;">
                        <label class="form-label fw-semibold">Write-off Reason <span class="text-danger">*</span></label>
                        <input type="text" name="reason" id="inspectReason" class="form-control" maxlength="500"
                               placeholder="e.g. Motherboard failure, beyond economical repair">
                        <div class="form-text">This asset was queued before a reason was required.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Record Inspection</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- Create Collection Batch modal — raises the return AARFs.

     There is no vendor picker any more, and that is the point. It used to ask IT to choose
     ONE vendor for the whole selection, so ticking two vendors' assets together filed them
     all under one of them and mailed the signed copy to the wrong PIC. The vendor is now
     read off each asset, and the modal shows the resulting split BEFORE the submit, on the
     page where the mistake would otherwise be made. --}}
@if($canDecommission)
<div class="modal fade" id="createBatchModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h6 class="modal-title text-white fw-bold"><i class="bi bi-box-arrow-up me-2"></i>Create Collection Batch</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('decommission.returns.generate') }}" method="POST">@csrf
                <div class="modal-body">
                    <div id="batchIdsContainer"></div>
                    <p class="small text-muted">
                        An <strong>Asset Acceptance &amp; Return Form (AARF)</strong> is raised for each
                        vendor and company rented to below. The collector then verifies the list and
                        signs on this device.
                    </p>

                    <div id="batchGroups"></div>

                    {{-- Assets that cannot become a form are named here rather than dropped, so
                         nobody submits believing they were included. --}}
                    <div id="batchUnresolved" class="alert alert-warning py-2 px-3 small d-none">
                        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>These will NOT be included:</div>
                        <div id="batchUnresolvedList"></div>
                        <div class="mt-1">Link the rental vendor on the asset record first, or send a company-owned asset to e-waste instead.</div>
                    </div>

                    <div class="small text-muted">Selected assets: <span id="batchSummary">none</span></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="batchSubmitBtn"><i class="bi bi-file-earmark-plus me-1"></i>Generate AARF</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

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
                        {{-- Both ownership panels carry a select called vendor_id; only the
                             visible one may submit, so the hidden panel's is disabled (same
                             device the invoice inputs already use). --}}
                        <select name="vendor_id" id="companyVendorSelect" class="form-select js-vendor-picker"
                                data-detail="companyVendorDetail" data-contact=""
                                {{ old('ownership_type', 'company') === 'company' ? '' : 'disabled' }}>
                            <option value="">— Not registered / type below —</option>
                            @foreach(($vendorOptions['purchase'] ?? []) as $vp)
                                <option value="{{ $vp->id }}"
                                        data-pic="{{ $vp->pic_name }}"
                                        data-email="{{ $vp->pic_email }}"
                                        data-phone="{{ $vp->pic_phone }}"
                                        data-tel="{{ $vp->contact_number }}"
                                        data-reg="{{ $vp->company_registration_no }}"
                                        data-sst="{{ $vp->sst_number }}"
                                        data-address="{{ $vp->address }}"
                                        {{ (string) old('vendor_id') === (string) $vp->id ? 'selected' : '' }}>{{ $vp->name }}</option>
                            @endforeach
                        </select>
                        <div id="companyVendorDetail" class="form-text text-muted small mt-1"></div>
                        <input type="text" name="purchase_vendor" class="form-control mt-2" value="{{ old('purchase_vendor') }}"
                               placeholder="Or type an unregistered supplier">
                        <div class="form-text text-muted small">
                            Registered suppliers come from <a href="{{ route('vendors.index') }}" target="_blank">Vendor Management</a>. Picking one overrides the free text.
                        </div></div>
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
                    <div class="col-md-4"><label class="form-label fw-semibold">Registered Rental Vendor</label>
                        <select name="vendor_id" id="rentalVendorSelect" class="form-select js-vendor-picker"
                                data-detail="rentalVendorDetail" data-contact="addRentalVendorContact"
                                data-pic-field="addRentalVendorPic"
                                {{ old('ownership_type') === 'rental' ? '' : 'disabled' }}>
                            <option value="">— Not registered / type below —</option>
                            @foreach(($vendorOptions['rental'] ?? []) as $vr)
                                <option value="{{ $vr->id }}"
                                        data-pic="{{ $vr->pic_name }}"
                                        data-email="{{ $vr->pic_email }}"
                                        data-phone="{{ $vr->pic_phone }}"
                                        data-tel="{{ $vr->contact_number }}"
                                        data-reg="{{ $vr->company_registration_no }}"
                                        data-sst="{{ $vr->sst_number }}"
                                        data-address="{{ $vr->address }}"
                                        {{ (string) old('vendor_id') === (string) $vr->id ? 'selected' : '' }}>{{ $vr->name }}</option>
                            @endforeach
                        </select>
                        <div id="rentalVendorDetail" class="form-text text-muted small mt-1"></div>
                        <div class="form-text text-muted small">
                            Registered vendors come from <a href="{{ route('vendors.index') }}" target="_blank">Vendor Management</a>.
                        </div></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Rental Vendor</label>
                        <input type="text" name="rental_vendor" id="addRentalVendorPic" class="form-control" value="{{ old('rental_vendor') }}"
                               placeholder="Person we deal with">
                        <div class="form-text text-muted small">Auto-filled with the picked vendor's PIC name. If the vendor isn't registered above, type their name here.</div></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Vendor Contact</label>
                        <input type="text" name="rental_vendor_contact" id="addRentalVendorContact" class="form-control" value="{{ old('rental_vendor_contact') }}" placeholder="Phone or email">
                        <div class="form-text text-muted small">Auto-filled with the picked vendor's PIC contact number.</div></div>
                    <div class="col-md-4"><label class="form-label fw-semibold">Monthly Cost (RM)</label>
                        <input type="number" name="rental_cost_per_month" class="form-control" value="{{ old('rental_cost_per_month') }}" step="0.01" placeholder="0.00"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Rental Start Date</label>
                        <input type="date" name="rental_start_date" class="form-control" value="{{ old('rental_start_date') }}"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Rental End Date</label>
                        <input type="date" name="rental_end_date" class="form-control" value="{{ old('rental_end_date') }}"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold">Contract Reference</label>
                        <input type="text" name="rental_contract_reference" class="form-control" value="{{ old('rental_contract_reference') }}" placeholder="Contract / PO number">
                        <div class="form-text text-muted small">Free text. Groups the assets when no invoice is picked.</div></div>
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
                    $addPreselectedOnbId = old('assigned_onboarding_id', '');
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
                    } elseif ($addPreselectedOnbId) {
                        foreach ($pendingOnboardings as $ob) {
                            if ($ob->id == $addPreselectedOnbId) {
                                $addPreselectedLabel = asset_onboarding_option_label($ob);
                                break;
                            }
                        }
                    }
                @endphp
                <div class="row g-3 mb-4">
                    <div class="col-md-3"><label class="form-label fw-semibold">Assigned To</label>
                        <div class="position-relative">
                            <input type="text" id="addEmpSearchInput" class="form-control"
                                   placeholder="Search employee or new hire..."
                                   autocomplete="off"
                                   value="{{ $addPreselectedLabel }}">
                            <ul id="addEmpList"
                                class="list-unstyled border rounded bg-white shadow-sm position-absolute mb-0"
                                style="z-index:1060;max-height:200px;overflow-y:auto;display:none;top:100%;left:0;min-width:100%;width:max-content;max-width:480px;">
                                <li>
                                    <button type="button" class="dropdown-item"
                                            data-empid="" data-onbid="" data-emplabel="— Not Assigned —">
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
                                            data-empid="{{ $emp->id }}" data-onbid="" data-emplabel="{{ $empLabel }}"
                                            data-empname="{{ strtolower($empLabel) }}"
                                            style="white-space:normal;word-break:break-word;">
                                        {{ $empLabel }}
                                    </button>
                                </li>
                                @endforeach
                                {{-- New hires: no employees row exists until their start date, so they are
                                     posted as assigned_onboarding_id. Handing over kit early is the point. --}}
                                @if($pendingOnboardings->isNotEmpty())
                                <li class="px-3 py-1 small text-muted bg-light border-top border-bottom">
                                    New hires — not started yet
                                </li>
                                @foreach($pendingOnboardings as $ob)
                                @php $obLabel = asset_onboarding_option_label($ob); @endphp
                                <li>
                                    <button type="button" class="dropdown-item"
                                            data-empid="" data-onbid="{{ $ob->id }}" data-emplabel="{{ $obLabel }}"
                                            data-empname="{{ strtolower($obLabel) }}"
                                            style="white-space:normal;word-break:break-word;">
                                        {{ $obLabel }}
                                    </button>
                                </li>
                                @endforeach
                                @endif
                            </ul>
                            <input type="hidden" name="assigned_employee_id" id="addAssignedEmployeeId"
                                   value="{{ old('assigned_employee_id', '') }}">
                            <input type="hidden" name="assigned_onboarding_id" id="addAssignedOnboardingId"
                                   value="{{ old('assigned_onboarding_id', '') }}">
                        </div>
                        <div class="form-text text-muted small">Employees and new hires who haven't started. The AARF is emailed on save.</div>
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
                    {{-- Completeness / parts-removed are recorded by an INSPECTION from the
                         Decommissioning tab, not here — see the note in the edit form. --}}
                    <div class="col-md-6" id="addEwasteInspectNote" style="display:none;">
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Completeness and removed parts are recorded when the asset is <strong>inspected</strong>, from the Decommissioning tab.
                        </div>
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

@push('styles')
<style>
    /* Decommissioning batch-selection checkboxes — high-contrast so they don't
       blend into the white row. Bootstrap's default 1px light border is too faint. */
    .js-batch-check {
        width: 1.25rem;
        height: 1.25rem;
        border: 2px solid #64748b;
        background-color: #fff;
        cursor: pointer;
        vertical-align: middle;
    }
    .js-batch-check:hover { border-color: #2563eb; box-shadow: 0 0 0 .15rem rgba(37,99,235,.25); }
    .js-batch-check:checked { background-color: #2563eb; border-color: #2563eb; }
</style>
@endpush

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
// Both panels carry inputs with the SAME name (invoice_documents[], vendor_id), so the
// hidden panel's must be disabled or the browser submits two values for one field.
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
        var companyVendor = document.getElementById('companyVendorSelect');
        var rentalVendor  = document.getElementById('rentalVendorSelect');
        if (companyVendor) companyVendor.disabled = (value !== 'company');
        if (rentalVendor)  rentalVendor.disabled  = (value !== 'rental');
    }
    document.querySelectorAll('.add-ownership-radio').forEach(function (radio) {
        radio.addEventListener('change', function () { toggleOwnership(this.value); });
    });
})();

// ── Vendor picker auto-fill (Add form + Edit form) ────────────────────
// Reads the selected <option>'s data-* attributes — no AJAX, so the page can never show
// details for a vendor that has since been deactivated or renamed. CSP-safe:
// addEventListener only, and every value goes in via textContent, never innerHTML.
(function () {
    function fill(select, picked) {
        var opt = select.options[select.selectedIndex];
        var detail = document.getElementById(select.dataset.detail || '');
        var contact = select.dataset.contact ? document.getElementById(select.dataset.contact) : null;
        var picField = select.dataset.picField ? document.getElementById(select.dataset.picField) : null;

        if (detail) { detail.textContent = ''; }
        // Clearing the picker back to "not registered" leaves the fields alone — the
        // operator is about to type an unregistered vendor's details there.
        if (!opt || !opt.value) { return; }

        var pic   = opt.dataset.pic || '';
        var email = opt.dataset.email || '';
        var phone = opt.dataset.phone || '';
        var tel   = opt.dataset.tel || '';
        var reg   = opt.dataset.reg || '';
        var sst   = opt.dataset.sst || '';
        var addr  = opt.dataset.address || '';

        // `picked` = the operator just chose a vendor, which is an explicit act: both
        // fields are refreshed, because the previous vendor's PIC and number are stale
        // the moment the vendor changes (and leaving them would attribute the wrong
        // person to the new vendor). On the initial reflect it is false, so a value
        // restored by old() after a failed submit is never clobbered.
        if (picField && (picked || !picField.value)) {
            picField.value = pic;
        }
        if (contact && (picked || !contact.value)) {
            contact.value = phone || email || tel || '';
        }

        if (detail) {
            var bits = [];
            if (pic)  { bits.push('PIC: ' + pic + (phone ? ' (' + phone + ')' : '')); }
            if (email) { bits.push(email); }
            if (tel)  { bits.push('Tel: ' + tel); }
            if (reg)  { bits.push('Reg: ' + reg); }
            if (sst)  { bits.push('SST: ' + sst); }
            if (addr) { bits.push(addr); }
            detail.textContent = bits.join(' · ');
        }
    }

    document.querySelectorAll('.js-vendor-picker').forEach(function (select) {
        select.addEventListener('change', function () { fill(this, true); });
        // Reflect a pre-selected vendor (old() after a failed submit) without touching
        // the PIC/contact fields, which already hold their submitted values.
        if (select.value) { fill(select, false); }
    });
})();

// An asset can be assigned to an employee OR to a new hire who has no employees row yet,
// so "is it assigned?" must read BOTH hidden fields — testing only the employee one would
// leave a new hire's laptop showing as Available.
function addHasAssignee() {
    const emp = document.getElementById('addAssignedEmployeeId');
    const onb = document.getElementById('addAssignedOnboardingId');
    return (emp && emp.value !== '') || (onb && onb.value !== '');
}

function syncAssignmentStatus() {
    const statusSelect = document.getElementById('assetStatus');
    const condSelect   = document.getElementById('addAssetCondition');
    if (!statusSelect) return;

    if (addHasAssignee()) {
        // Held by someone → unavailable (locked to assignee)
        statusSelect.value = 'unavailable';
    } else {
        // Nobody holds it → status driven by condition
        const condition = condSelect ? condSelect.value : 'good';
        statusSelect.value = (condition === 'good') ? 'available' : 'unavailable';
    }
}

// ── Assigned To searchable dropdown (Add form) — employees + pre-start new hires ──
(function () {
    const searchInput = document.getElementById('addEmpSearchInput');
    const empList     = document.getElementById('addEmpList');
    const hiddenInput = document.getElementById('addAssignedEmployeeId');
    const onbInput    = document.getElementById('addAssignedOnboardingId');
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

    // Exactly one of the two ids may be set: the other is always cleared, so a switch from
    // an employee to a new hire (or back) can never submit both and file the asset twice.
    function selectEmp(empId, onbId, label) {
        if (hiddenInput) hiddenInput.value = empId || '';
        if (onbInput)    onbInput.value    = onbId || '';
        searchInput.value = (empId || onbId) ? label : '';
        hideList();
        syncAssignmentStatus();
    }

    searchInput.addEventListener('input', function () { filterList(this.value); });
    searchInput.addEventListener('focus', showList);
    searchInput.addEventListener('blur',  function () { setTimeout(hideList, 200); });

    empList.querySelectorAll('button[data-emplabel]').forEach(btn => {
        btn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            selectEmp(this.dataset.empid, this.dataset.onbid, this.dataset.emplabel);
        });
    });

    // Expose for external callers (syncAssignmentStatus, etc.)
    window.addSelectEmp = selectEmp;
})();

function syncStatusFromConditionAdd(condition) {
    const statusSelect  = document.getElementById('assetStatus');
    const maintWrap     = document.getElementById('addMaintenanceWrap');
    const reasonWrap    = document.getElementById('addDecommissionReasonWrap');
    const reasonInput   = document.getElementById('addDecommissionReason');
    if (statusSelect) {
        // Held by anyone (employee or pre-start new hire) → always unavailable; else condition-driven
        if (addHasAssignee()) {
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
    // Completeness / parts-removed are set by an inspection, not here — just say so.
    const inspectNote = document.getElementById('addEwasteInspectNote');
    if (inspectNote) {
        inspectNote.style.display = condition === 'not_good' ? '' : 'none';
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

// ── Decommissioning: batch selection + Create Collection Batch modal ──────
//
// The modal previews the SPLIT the server will make: one AARF per (vendor, company rented
// to). Doing it here is the whole reason the mixed-vendor mistake stops being silent — the
// operator sees three forms about to be raised before pressing the button, on the same page
// where they ticked the boxes.
//
// Everything dynamic goes in via textContent. No innerHTML with values in it, per the
// project-wide rule; the only innerHTML here clears a container.
(function () {
    const checks     = document.querySelectorAll('.js-batch-check');
    const createBtn  = document.getElementById('createBatchBtn');
    const countBadge = document.getElementById('batchSelCount');
    const idsBox     = document.getElementById('batchIdsContainer');
    const summary    = document.getElementById('batchSummary');
    const groupsBox  = document.getElementById('batchGroups');
    const unresBox   = document.getElementById('batchUnresolved');
    const unresList  = document.getElementById('batchUnresolvedList');
    const submitBtn  = document.getElementById('batchSubmitBtn');
    if (!checks.length || !createBtn) return;

    function selected() {
        return Array.from(checks).filter(c => c.checked);
    }
    function refresh() {
        const sel = selected();
        if (countBadge) countBadge.textContent = sel.length;
        createBtn.disabled = sel.length === 0;
    }
    checks.forEach(c => c.addEventListener('change', refresh));
    refresh();

    function el(tag, cls, text) {
        const n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    // On modal open, inject the checked ids as hidden inputs + render the preview.
    const modal = document.getElementById('createBatchModal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function () {
        const sel = selected();
        idsBox.innerHTML = '';
        if (groupsBox) groupsBox.innerHTML = '';
        if (unresList) unresList.innerHTML = '';

        const labels = [];
        const groups = new Map();   // JSON [vendor, company] => {vendor, company, tags:[]}
        const unresolved = [];

        sel.forEach(c => {
            const input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'dispose_ids[]';
            input.value = c.value;
            idsBox.appendChild(input);

            const tag = c.dataset.tag || ('#' + c.value);
            labels.push(tag + (c.dataset.label ? ' (' + c.dataset.label + ')' : ''));

            // Mirrors RentalAssetAcknowledgement::planReturns(). The server decides for real
            // — this only has to agree with it, never to be trusted instead of it.
            if (c.dataset.rental !== '1') {
                unresolved.push(tag + ' — not a rental asset');
                return;
            }
            const vendor = (c.dataset.vendor || '').trim();
            if (!vendor) {
                unresolved.push(tag + ' — no rental vendor linked');
                return;
            }
            const company = (c.dataset.company || '').trim();
            // JSON, not string concatenation: "Acme Ltd" + "Kuala Lumpur" and "Acme" +
            // "Ltd Kuala Lumpur" join to the same key, which would show two forms as one
            // here while the server (grouping on vendor_id) correctly made two.
            const key = JSON.stringify([vendor, company]);
            if (!groups.has(key)) groups.set(key, { vendor: vendor, company: company, tags: [] });
            groups.get(key).tags.push(tag);
        });

        if (summary) summary.textContent = labels.length ? labels.join(', ') : 'none';

        if (groupsBox) {
            if (groups.size) {
                const head = el('div', 'fw-semibold small mb-2',
                    groups.size + (groups.size === 1 ? ' form will be created:' : ' forms will be created:'));
                groupsBox.appendChild(head);
            }
            groups.forEach(g => {
                const row = el('div', 'border rounded p-2 mb-2');
                row.appendChild(el('div', 'fw-semibold small', g.vendor));
                row.appendChild(el('div', 'text-muted', 'Company rented to: ' + (g.company || 'not specified')));
                row.appendChild(el('div', 'small mt-1', g.tags.length + ' asset' + (g.tags.length === 1 ? ': ' : 's: ') + g.tags.join(', ')));
                groupsBox.appendChild(row);
            });
        }

        if (unresBox && unresList) {
            unresBox.classList.toggle('d-none', unresolved.length === 0);
            unresolved.forEach(u => unresList.appendChild(el('div', null, u)));
        }

        // Nothing resolvable means nothing to submit. Leaving the button live would post a
        // request whose only possible outcome is the error the modal is already showing.
        if (submitBtn) submitBtn.disabled = groups.size === 0;
    });
})();

// ── Inspection modal (Phase 2) ────────────────────────────────────────────
// One modal serves every row. Bound with addEventListener via delegation — CSP blocks every
// inline handler, and these buttons are rendered per row, so a delegated listener also
// survives the table being re-rendered by a filter.
(function () {
    const modalEl = document.getElementById('inspectModal');
    const form    = document.getElementById('inspectForm');
    if (!modalEl || !form) return;

    const compSel    = document.getElementById('inspectCompleteness');
    const reasonWrap = document.getElementById('inspectReasonWrap');
    const reasonIn   = document.getElementById('inspectReason');

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.js-inspect-btn');
        if (!btn) return;

        form.setAttribute('action', "{{ url('/assets/decommission/inspect') }}/" + btn.dataset.id);

        const tagEl = document.getElementById('inspectTag');
        const lblEl = document.getElementById('inspectLabel');
        if (tagEl) tagEl.textContent = btn.dataset.tag || '';
        if (lblEl) lblEl.textContent = btn.dataset.label ? ' — ' + btn.dataset.label : '';

        if (compSel) compSel.value = btn.dataset.completeness || '';

        // Match the asset's own company against the registered list case/whitespace-
        // insensitively — the SAME test AssetDecommissionController::inspect() runs
        // server-side, so what is shown here can never disagree with what actually gets
        // saved. A clean match is shown read-only and the picker is not even rendered into
        // the submit; only when nothing matches does IT need to confirm one by hand, since a
        // guess at an unregistered name is worse than no answer.
        const coSel     = document.getElementById('inspectCompany');
        const coConfirm = document.getElementById('inspectCompanyConfirmed');
        const coPicker  = document.getElementById('inspectCompanyPicker');
        const coName    = document.getElementById('inspectCompanyName');
        const normalise = s => (s || '').trim().toLowerCase().replace(/\s+/g, ' ');
        let matched = null;
        if (coSel) {
            const want = normalise(btn.dataset.company);
            coSel.value = '';
            if (want) {
                for (const opt of coSel.options) {
                    if (opt.value && normalise(opt.value) === want) { matched = opt.value; break; }
                }
            }
        }
        if (matched) {
            if (coName) coName.textContent = matched;
            if (coConfirm) coConfirm.style.display = '';
            if (coPicker) coPicker.style.display = 'none';
            if (coSel) { coSel.required = false; coSel.value = matched; }
        } else {
            if (coConfirm) coConfirm.style.display = 'none';
            if (coPicker) coPicker.style.display = '';
            if (coSel) coSel.required = true;
        }

        const needsReason = btn.dataset.needsReason === '1';
        if (reasonWrap) reasonWrap.style.display = needsReason ? '' : 'none';
        if (reasonIn) {
            reasonIn.required = needsReason;
            reasonIn.value = needsReason ? '' : (btn.dataset.reason || '');
        }

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
})();

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

{{-- In-app confirmation dialog (replaces native confirm()) for decommission actions (e.g. Run sweep). --}}
@include('partials.confirm-modal')
@endsection