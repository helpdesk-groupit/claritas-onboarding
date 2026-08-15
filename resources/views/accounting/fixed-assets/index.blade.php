@extends('layouts.app')
@section('title', 'Fixed Assets')
@section('page-title', 'Fixed Assets')

@section('content')
@include('accounting.partials.nav')
@include('partials.dashboard-widgets-style')

@include('partials.decommission-ui-style')

{{-- Filter / action toolbar --}}
<div class="card mb-3">
    <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
        <a href="{{ route('accounting.asset-categories.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-folder me-1"></i>Categories</a>
        {{-- CSP: no inline onchange= — the policy ships 'unsafe-hashes' but lists NO script
             hashes, so inline handlers are blocked outright and the filter silently never
             submitted (the select changed, the page never reloaded). Bound below instead. --}}
        <form class="d-flex align-items-center gap-2" id="assetStatusFilter">
            <label class="form-label mb-0 small fw-semibold text-muted">Status</label>
            <select name="status" id="assetStatusSelect" class="form-select form-select-sm" style="width:160px;">
                <option value="">All Status</option>
                @foreach(['active','disposed','fully_depreciated'] as $s)<option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucwords(str_replace('_',' ',$s)) }}</option>@endforeach
            </select>
            <noscript><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button></noscript>
        </form>
        @if(request('status'))
        <a href="{{ route('accounting.fixed-assets.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
        @endif
        @if(Auth::user()->canManageAccounting())
        <div class="d-flex gap-2 ms-auto">
            @if(Auth::user()->canApproveTransactions())
            <form method="POST" action="{{ route('accounting.fixed-assets.run-depreciation') }}" class="d-flex gap-1">
                @csrf
                <input type="month" name="run_month" class="form-control form-control-sm" value="{{ now()->format('Y-m') }}" style="width:150px;">
                <input type="hidden" name="company" value="{{ request('company') }}">
                <button class="btn btn-outline-warning btn-sm"><i class="bi bi-calculator me-1"></i>Run Depreciation</button>
            </form>
            @endif
            <a href="{{ route('accounting.fixed-assets.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New Asset</a>
        </div>
        @endif
    </div>
</div>


{{-- The accounting fixed-asset register (acc_fixed_assets), and nothing else.

     The e-waste quotation-approval and cycle-report cards sat above this until 2026-08-14
     and now live on Management → Decommissioning, the one surface where Finance and
     management review a disposal. They were always a different ledger from this one —
     asset_inventories / dispose_assets rows, with nothing posted between the two. --}}
<div class="card ewx-card">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-blue"><i class="bi bi-building-gear"></i></span>
        <div class="me-2">
            <span class="ewx-title">Fixed Asset Register</span>
            <span class="ewx-sub">Capitalised assets with cost &amp; depreciation.</span>
        </div>
        @if(($assets ?? null) && $assets->total())
        <span class="ewx-count">{{ $assets->total() }}</span>
        @endif
    </div>
    <div class="card-body p-0">
        @if(($assets ?? collect())->isEmpty())
            <div class="ewx-empty"><i class="bi bi-inbox"></i>No fixed assets yet.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover ewx-table">
                <thead>
                    <tr>
                        <th class="ps-3">Code</th><th>Name</th><th>Category</th><th>Purchase Date</th>
                        <th class="text-end">Cost</th><th class="text-end">Current Value</th>
                        <th>Status</th><th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($assets as $a)
                    <tr>
                        <td class="ps-3 ewx-code">{{ $a->asset_code }}</td>
                        <td>{{ $a->name }}</td>
                        <td>{{ $a->category->name ?? '—' }}</td>
                        <td>{{ fmt_date($a->purchase_date) }}</td>
                        <td class="text-end">{{ number_format($a->purchase_cost, 2) }}</td>
                        <td class="text-end">{{ number_format($a->current_value, 2) }}</td>
                        <td><span class="badge rounded-pill bg-{{ $a->status === 'active' ? 'success' : ($a->status === 'disposed' ? 'danger' : 'secondary') }}">{{ ucwords(str_replace('_',' ',$a->status)) }}</span></td>
                        <td class="text-end pe-3">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="{{ route('accounting.fixed-assets.depreciation', $a) }}" class="btn btn-outline-secondary" title="Depreciation schedule"><i class="bi bi-calendar3"></i></a>
                                @if(Auth::user()->canManageAccounting())
                                <a href="{{ route('accounting.fixed-assets.edit', $a) }}" class="btn btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@if(method_exists($assets ?? collect(), 'links'))
<div class="mt-3">{{ $assets->withQueryString()->links() }}</div>
@endif
@endsection

@push('scripts')
{{-- Status filter auto-submit. MUST be addEventListener in a nonce-protected block: the CSP
     ships 'unsafe-hashes' with no hash list, so an inline onchange= is blocked and the filter
     never fires (see CLAUDE.md — most common bug class in this codebase). --}}
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var sel = document.getElementById('assetStatusSelect');
    if (sel) {
        sel.addEventListener('change', function () {
            document.getElementById('assetStatusFilter').submit();
        });
    }
})();
</script>
@endpush
