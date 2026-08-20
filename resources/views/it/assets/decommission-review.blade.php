@extends('layouts.app')
@section('title', 'Company Asset Decommissioning')
@section('page-title', 'Company Asset Decommissioning')

@section('content')
@include('partials.decommission-ui-style')

{{-- The full IT-facing Asset Listing page (add/edit assets, inventory table, decommissioning
     queue) is deliberately NOT reused here — this viewer has no canViewAssets() access, and
     that page renders every tab-pane's data into the DOM regardless of which tab is active. This
     standalone page carries only the two tabs this audience may see, styled identically to
     Asset Listing's own tab bar (same classes, same icons) so it reads as the same page rather
     than a separate one — see AssetController::index(). --}}
<div class="card mb-4" style="border:none;background:linear-gradient(135deg,#0f172a,#1e3a5f);">
    <div class="card-body py-3 d-flex align-items-center gap-3">
        <span class="ewx-chip ewx-chip-slate"><i class="bi bi-building-gear"></i></span>
        <div>
            <h5 class="text-white mb-0 fw-bold">Company Asset Decommissioning</h5>
            <small class="text-white-50">E-waste disposal cycles awaiting review, and the finished archive.</small>
        </div>
    </div>
</div>

{{-- ─── TABS ─── Same markup/classes as it/assets/page.blade.php's tab bar, minus the two
     tabs (Asset Listing, Decommissioning Assets) that require canViewAssets(). --}}
<ul class="nav nav-tabs mb-0" id="assetTabs" role="tablist" style="border-bottom:none;">
    <li class="nav-item" role="presentation">
        <button class="nav-link {{ $activeTab === 'company-decom' ? 'active' : '' }}" id="tab-company-decom"
                data-bs-toggle="tab" data-bs-target="#pane-company-decom" type="button" role="tab">
            <i class="bi bi-building-gear me-1 text-warning"></i>Company Asset Decommissioning
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

<div class="tab-pane fade {{ $activeTab === 'company-decom' ? 'show active' : '' }}" id="pane-company-decom" role="tabpanel">
@include('it.assets._decommission-review-summary', [
    'decomStats' => $decomStats, 'cdFilters' => $cdFilters, 'companyOptions' => $companyOptions, 'statusOptions' => $statusOptions,
    'awaiting' => $awaiting, 'canFinance' => $canFinance, 'ewasteVendors' => $ewasteVendors,
])
@include('it.assets._decommission-review-by-company', ['activeByCompany' => $activeByCompany, 'cdFilters' => $cdFilters])
</div>{{-- /pane-company-decom --}}

<div class="tab-pane fade {{ $activeTab === 'reports' ? 'show active' : '' }}" id="pane-reports" role="tabpanel">
@include('it.assets._decommission-reports-pane', [
    'reportGroups' => $reportGroups, 'reportsCount' => $reportsCount, 'reportFilteredCount' => $reportFilteredCount,
    'reportCompanyOptions' => $reportCompanyOptions, 'reportVendorOptions' => $reportVendorOptions, 'rpFilters' => $rpFilters,
])
</div>{{-- /pane-reports --}}

</div>
@endsection
