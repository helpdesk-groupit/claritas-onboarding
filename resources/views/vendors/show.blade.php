@extends('layouts.app')
@section('title', $vendor->name)
@section('page-title', 'Vendor Profile')

@section('content')
@include('partials.dashboard-widgets-style')
@include('partials.decommission-ui-style')
@include('partials.vendor-ui-style')

@php
    // Which tab opens. Set by the ?tab= a controller redirect carries, so an action
    // returns the user to the tab they acted on rather than always to Profile.
    //
    // 'ask' is deliberately NOT one of them any more — the assistant is a floating panel
    // over whichever tab is open, so ?tab=ask leaves the tabs alone and is read by the
    // panel's own auto-open instead (see _ask-js). Every redirect that carried it still
    // lands in the assistant; it just no longer moves the page underneath.
    $activeTab = in_array(request('tab'), ['profile', 'contracts', 'billing', 'assets'], true)
        ? request('tab') : 'profile';
    $rented = $assets->where('ownership_type', 'rental');
    $purchased = $assets->where('ownership_type', 'company');
    $quotations = $vendor->billingDocuments->where('doc_type', 'quotation');
    $invoices = $vendor->billingDocuments->where('doc_type', 'invoice');
@endphp

<div class="container-fluid px-0">
    <div class="mb-3">
        <a href="{{ route('vendors.index') }}" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Vendor Directory
        </a>
    </div>

    {{-- ── Header ──────────────────────────────────────────────────────────── --}}
    <div class="card vnd-hero mb-3">
        <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="vnd-avatar vnd-avatar-lg">{{ strtoupper(mb_substr($vendor->name, 0, 2)) }}</span>
                <div>
                    <h5 class="text-white mb-1 fw-bold">
                        {{ $vendor->name }}
                        @if(! $vendor->is_active)
                            <span class="badge bg-secondary ms-2">Inactive</span>
                        @endif
                        @if($vendor->is_primary_ewaste)
                            <span class="vnd-primary-star ms-2"><i class="bi bi-star-fill me-1"></i>Primary e-waste</span>
                        @endif
                    </h5>
                    <div>
                        @foreach($vendor->vendor_types ?? [] as $t)
                            <span class="vnd-type vnd-type-{{ $t }}">{{ \App\Models\Vendor::TYPES[$t] ?? ucfirst($t) }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            @if($canManage)
            <div class="d-flex gap-2">
                <a href="{{ route('vendors.edit', $vendor) }}" class="btn btn-light btn-sm fw-semibold"><i class="bi bi-pencil me-1"></i>Edit</a>
                <form action="{{ route('vendors.toggle-active', $vendor) }}" method="POST" class="js-confirm"
                      data-confirm="{{ $vendor->is_active ? 'Deactivate' : 'Activate' }} vendor &quot;{{ $vendor->name }}&quot;?"
                      data-confirm-title="{{ $vendor->is_active ? 'Deactivate vendor' : 'Activate vendor' }}"
                      data-confirm-ok="{{ $vendor->is_active ? 'Deactivate' : 'Activate' }}"
                      data-confirm-variant="{{ $vendor->is_active ? 'danger' : 'success' }}">
                    @csrf
                    <button class="btn btn-sm {{ $vendor->is_active ? 'btn-outline-light' : 'btn-success' }}">
                        <i class="bi bi-{{ $vendor->is_active ? 'toggle-on' : 'toggle-off' }} me-1"></i>{{ $vendor->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                </form>

                {{-- Same guarded delete as the directory. Disabled-with-a-reason rather than
                     hidden: this is the page that lists the very records blocking it. --}}
                @php $vndBlockers = $vendor->deletionBlockers(); @endphp
                @if($vndBlockers)
                    <button type="button" class="btn btn-sm btn-outline-light disabled" tabindex="-1" aria-disabled="true"
                            title="Cannot delete — {{ implode(', ', $vndBlockers) }} on record. Deactivate instead.">
                        <i class="bi bi-trash me-1"></i>Delete
                    </button>
                @else
                    <form action="{{ route('vendors.destroy', $vendor) }}" method="POST" class="js-confirm"
                          data-confirm="Permanently delete vendor &quot;{{ $vendor->name }}&quot;? Nothing is filed against them, so nothing else is affected — but this cannot be undone."
                          data-confirm-title="Delete vendor"
                          data-confirm-ok="Delete"
                          data-confirm-variant="danger">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                    </form>
                @endif
            </div>
            @endif
        </div>
    </div>

    {{-- ── SST verdict ─────────────────────────────────────────────────────── --}}
    <div class="vnd-sst vnd-sst-{{ $sstVerdict['state'] }} mb-3">
        <i class="bi bi-{{ in_array($sstVerdict['state'], ['exempt','not_registered'], true) ? 'shield-check' : ($sstVerdict['state'] === 'chargeable' ? 'receipt' : 'question-circle') }} mt-1"></i>
        <div>
            <strong>SST: {{ $sstVerdict['label'] }}</strong>
            <div>{{ $sstVerdict['reason'] }}</div>
        </div>
    </div>

    {{-- ── Summary tiles ───────────────────────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#0ea5e9,#0369a1);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-laptop"></i></div>
                        <div>
                            <div class="widget-number">{{ $summary['rented'] }}</div>
                            <div class="widget-label">Assets rented from them</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#b45309);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-bag-check"></i></div>
                        <div>
                            <div class="widget-number">{{ $summary['purchased'] }}</div>
                            <div class="widget-label">Assets purchased from them</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-file-earmark-text"></i></div>
                        <div>
                            <div class="widget-number">{{ $summary['contracts_active'] }}</div>
                            @php
                                $vndContractLabel = 'Active contracts'
                                    .($summary['contracts_expiring'] ? ' · '.$summary['contracts_expiring'].' expiring' : '');
                            @endphp
                            <div class="widget-label">{{ $vndContractLabel }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dash-widget vnd-kpi">
                <div class="widget-header" style="background:linear-gradient(135deg,#64748b,#334155);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-cash-coin"></i></div>
                        <div>
                            <div class="widget-number">RM {{ number_format($summary['monthly_rental'], 0) }}</div>
                            <div class="widget-label">Monthly rental commitment</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Tabs ────────────────────────────────────────────────────────────── --}}
    {{-- data-vnd-insights-url is the poll target for any row still being read in the
         background. It sits here, not on a tab pane, because a pending row can be on
         either the Contracts or the Billing tab. --}}
    <div class="card ewx-card" data-vnd-insights-url="{{ route('vendors.insights', $vendor) }}">
        <div class="card-header bg-transparent pb-0 pt-2 px-2">
            <ul class="nav nav-tabs vnd-tabs border-0" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeTab === 'profile' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#vndProfile" type="button" role="tab">
                        <i class="bi bi-building me-1"></i>Profile
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeTab === 'contracts' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#vndContracts" type="button" role="tab">
                        <i class="bi bi-file-earmark-text me-1"></i>Contracts
                        <span class="badge rounded-pill bg-secondary ms-1">{{ $vendor->contracts->count() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeTab === 'billing' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#vndBilling" type="button" role="tab">
                        <i class="bi bi-receipt me-1"></i>Billing
                        <span class="badge rounded-pill bg-secondary ms-1">{{ $vendor->billingDocuments->count() }}</span>
                        @if($summary['sst_flags'])
                            <span class="badge rounded-pill bg-warning text-dark ms-1" title="Documents charging SST this vendor may not charge">{{ $summary['sst_flags'] }}</span>
                        @endif
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $activeTab === 'assets' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#vndAssets" type="button" role="tab">
                        <i class="bi bi-laptop me-1"></i>Assets
                        <span class="badge rounded-pill bg-secondary ms-1">{{ $assets->count() }}</span>
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div class="tab-content">
                <div class="tab-pane fade {{ $activeTab === 'profile' ? 'show active' : '' }}" id="vndProfile" role="tabpanel">
                    @include('vendors.partials._profile')
                </div>
                <div class="tab-pane fade {{ $activeTab === 'contracts' ? 'show active' : '' }}" id="vndContracts" role="tabpanel">
                    @include('vendors.partials._contracts')
                </div>
                <div class="tab-pane fade {{ $activeTab === 'billing' ? 'show active' : '' }}" id="vndBilling" role="tabpanel">
                    @include('vendors.partials._billing')
                </div>
                <div class="tab-pane fade {{ $activeTab === 'assets' ? 'show active' : '' }}" id="vndAssets" role="tabpanel">
                    @include('vendors.partials._assets')
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modals live OUTSIDE the tab panes and the tables: a modal inside a hidden pane still
     works, but a <div> inside <tbody> is invalid markup the browser hoists out, which
     silently detaches the form from its fields. --}}
@if($canManage)
    @include('vendors.partials._contract-modals')
    @include('vendors.partials._billing-modals')

    @push('scripts')
    <script nonce="{{ $cspNonce ?? '' }}">
    // Re-open the modal that failed validation, so a rejected submit doesn't look like the
    // form silently vanished. Serves BOTH the contract and billing modals — they share the
    // hidden `_form` marker — which is why it lives here rather than inside either partial.
    // CSP-safe: no inline handlers, Bootstrap's own Modal API.
    (function () {
        var target = @json(old('_form'));
        if (!target) { return; }
        var el = document.getElementById(target);
        if (el && window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(el).show();
        }
    })();
    </script>
    @endpush
@endif

{{-- ── Ask AI ───────────────────────────────────────────────────────────────────
     A floating button rather than a sixth tab. The questions this assistant answers are
     asked WHILE reading a contract row, an invoice or the asset list — as a tab it made
     the operator leave the very row they wanted to ask about, and answered next to a
     document they could no longer see. As an offcanvas the page it is about stays on
     screen behind it, and the panel is reachable from every tab.

     Rendered for a read-only viewer and when nothing is readable too: the panel is where
     the reason a document cannot be asked about is stated, and a button that disappears
     when the AI is off reads as a broken feature rather than a switched-off one. --}}
@php
    $vndAskReadable = $askable->filter->hasAiText()->count();
    // Built here, not glued into the attribute: the count is the badge, and a screen
    // reader gets it in the label rather than as a bare number after the button's name.
    $vndFabLabel = 'Ask AI about this vendor\'s contracts and billing documents'
        .($vndAskReadable ? ' — '.$vndAskReadable.' document'.($vndAskReadable === 1 ? '' : 's').' readable' : '');
@endphp

<button class="vnd-fab" type="button" data-bs-toggle="offcanvas" data-bs-target="#vndAskPanel"
        aria-controls="vndAskPanel" title="{{ $vndFabLabel }}" aria-label="{{ $vndFabLabel }}">
    <i class="bi bi-robot"></i>
    <span class="vnd-fab-text">Ask AI</span>
    @if($vndAskReadable)
        <span class="vnd-fab-badge" aria-hidden="true">{{ $vndAskReadable }}</span>
    @endif
</button>

{{-- Sits outside the tab card, and outside every form on the page: an offcanvas carries
     its own forms (the question, and Start new topic), and a nested <form> is invalid
     markup the browser drops — taking the fields with it. --}}
<div class="offcanvas offcanvas-end vnd-ask-panel" tabindex="-1" id="vndAskPanel" aria-labelledby="vndAskPanelLabel">
    <div class="offcanvas-header">
        <div>
            <h6 class="offcanvas-title fw-bold mb-0" id="vndAskPanelLabel">
                <i class="bi bi-robot me-1"></i>Ask AI
            </h6>
            <div class="vnd-ask-panel-sub">{{ $vendor->name }} &mdash; contracts &amp; billing documents</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        @include('vendors.partials._ask')
    </div>
</div>

{{-- Outside the canManage block on purpose: a read-only viewer may ask questions, and the
     pending-summary poll has to run for them too. The script self-guards when neither the
     assistant nor a pending row is on the page. --}}
@include('vendors.partials._ask-js')

@include('partials.confirm-modal')
@endsection
