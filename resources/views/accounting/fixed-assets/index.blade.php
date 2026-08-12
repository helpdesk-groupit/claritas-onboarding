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

{{-- ─────────────────────────────────────────────────────────────────────────────
     Status = Disposed is the single home for e-waste: quotations awaiting a Finance
     decision (approved/rejected right here) followed by the finished cycles' reports.
     One row per CYCLE, never per asset; vendor returns are excluded — a rental going
     back to its owner is not a disposal. Separate ledger from the register below.
     ───────────────────────────────────────────────────────────────────────────── --}}
@if(isset($decommissionBatches))
@php
    // The list carries in-flight cycles as well as finished ones, so the money tile MUST be
    // restricted to finished ones: reportAmount() falls back to the quotation, and counting an
    // approved-but-not-yet-collected offer as "Recovered" would report income we have not
    // received. Assets follow the same split so each tile's number matches its own label.
    $ewxDone       = $decommissionBatches->filter(fn ($b) => $b->isFinalized() || $b->status === 'completed');
    $ewxInProgress = $decommissionBatches->reject(fn ($b) => $b->isFinalized() || $b->status === 'completed');
    $ewxRecovered  = $ewxDone->sum(fn ($b) => $b->reportAmount() ?? 0);
    $ewxAssets     = $ewxDone->sum('items_count');
@endphp
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card dash-widget h-100" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div>
                        <div class="widget-number">{{ isset($pendingQuotations) ? $pendingQuotations->count() : 0 }}</div>
                        <div class="widget-label">Quotations awaiting approval</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- Approved but not yet collected/paid. Without this tile an approved cycle showed
         nowhere at all, which read as "the approval did nothing". --}}
    <div class="col-6 col-lg-3">
        <div class="card dash-widget h-100" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#6366f1,#4338ca);">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-truck"></i></div>
                    <div>
                        <div class="widget-number">{{ $ewxInProgress->count() }}</div>
                        <div class="widget-label">Cycles in progress &middot; {{ $ewxInProgress->sum('items_count') }} asset(s)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-widget h-100" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-recycle"></i></div>
                    <div>
                        <div class="widget-number">{{ $ewxDone->count() }}</div>
                        <div class="widget-label">Completed cycles &middot; {{ $ewxAssets }} asset(s)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card dash-widget h-100" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-cash-coin"></i></div>
                    <div>
                        <div class="widget-number">RM {{ number_format($ewxRecovered, 2) }}</div>
                        <div class="widget-label">Received from completed cycles</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

@if(isset($pendingQuotations))
<div class="card ewx-card mb-3">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-warn"><i class="bi bi-cash-coin"></i></span>
        <div class="me-2">
            <span class="ewx-title">E-waste Quotations Awaiting Approval</span>
            <span class="ewx-sub">The vendor pays us &mdash; the offer is income.</span>
        </div>
        @if($pendingQuotations->isNotEmpty())
        <span class="ewx-count ewx-count-warn">{{ $pendingQuotations->count() }}</span>
        @endif
    </div>
    <div class="card-body p-0">
        @if($pendingQuotations->isEmpty())
            <div class="ewx-empty"><i class="bi bi-check2-circle"></i>No quotations awaiting approval.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover ewx-table">
                <thead>
                    <tr>
                        <th class="ps-3">Cycle</th>
                        <th>Vendor</th>
                        <th class="text-center">Assets</th>
                        <th>Quotation</th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($pendingQuotations as $q)
                    <tr>
                        <td class="ps-3 ewx-code">{{ $q->batch_number }}</td>
                        <td>{{ $q->vendor?->name ?? '—' }}</td>
                        <td class="text-center">{{ $q->items_count }}</td>
                        <td>
                            @if($q->quotation_path)
                                <a href="{{ secure_file_url($q->quotation_path) }}" class="ewx-quote-link" target="_blank" rel="noopener">
                                    <i class="bi bi-file-earmark-text me-1"></i>View quote
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                            {{-- A second quote arrives because the first was refused. Say which
                                 revision this is and what was asked for, so the reviewer isn't
                                 approving a revised offer blind to their own earlier reason. --}}
                            @php $rejected = $q->lastRejectedQuotation(); @endphp
                            @if($rejected)
                            <div class="small text-danger mt-1">
                                <i class="bi bi-arrow-repeat me-1"></i>Revision {{ $q->quotationRevisionCount() }} &mdash;
                                you rejected revision {{ $rejected->revision }} on {{ fmt_date($rejected->finance_reviewed_at) }}@if($rejected->finance_remarks): {{ \Illuminate\Support\Str::limit($rejected->finance_remarks, 140) }}@endif
                            </div>
                            @endif
                        </td>
                        <td class="text-end pe-3 text-nowrap">
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $q->id }}">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </button>
                            <form action="{{ route('finance.ewaste.approve', $q) }}" method="POST" class="d-inline js-confirm ms-1"
                                  data-confirm="Approve the vendor's offer for {{ $q->batch_number }}? Open the quote first — the offer amount is stated in the document."
                                  data-confirm-title="Approve quotation" data-confirm-ok="Approve" data-confirm-variant="success">@csrf
                                <button class="btn btn-sm ewx-btn-approve"><i class="bi bi-check-lg me-1"></i>Approve</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- Reject modals (reason required) --}}
@foreach($pendingQuotations as $q)
<div class="modal fade" id="rejectModal{{ $q->id }}" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:linear-gradient(135deg,#ef4444,#b91c1c);">
                <h6 class="modal-title text-white fw-bold"><i class="bi bi-x-circle me-2"></i>Reject Quotation &mdash; {{ $q->batch_number }}</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('finance.ewaste.reject', $q) }}" method="POST">@csrf
                <div class="modal-body">
                    <p class="small text-muted">The offer from <strong>{{ $q->vendor?->name ?? 'the vendor' }}</strong> will be rejected. IT will be notified to re-quote or cancel.</p>
                    <label class="form-label fw-semibold small">Reason <span class="text-danger">*</span></label>
                    <textarea name="remarks" rows="3" class="form-control" required maxlength="1000" placeholder="Why is this quotation being rejected?"></textarea>
                </div>
                <div class="modal-footer border-0 pt-1">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Reject Quotation</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- In-app confirmation dialog (replaces native confirm()) for the Approve action. --}}
@include('partials.confirm-modal')
@endif

@if(isset($decommissionBatches))
<div class="card ewx-card mb-3">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-green"><i class="bi bi-recycle"></i></span>
        <div class="me-2">
            <span class="ewx-title">E-waste Decommissioning Reports</span>
            <span class="ewx-sub">Every disposal cycle and where it stands &mdash; in progress and completed. Rental returns are not disposals and are excluded.</span>
        </div>
        @if($decommissionBatches->isNotEmpty())
        <span class="ewx-count">{{ $decommissionBatches->count() }}</span>
        @endif
    </div>
    <div class="card-body p-0">
        @if($decommissionBatches->isEmpty())
            <div class="ewx-empty"><i class="bi bi-inbox"></i>No decommissioning reports yet.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover ewx-table">
                <thead>
                    <tr>
                        <th class="ps-3">Cycle</th>
                        <th>Vendor</th>
                        <th class="text-center">Assets</th>
                        <th class="text-end">Amount (RM)</th>
                        <th>Raised</th>
                        <th>Completed</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Report</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($decommissionBatches as $b)
                    {{-- ewasteStageBadge(), not statusBadge(): an approved cycle is still open
                         work to Finance ("Awaiting collection & payment"), not "Finance Approved". --}}
                    @php
                        [$badgeClass, $badgeLabel] = $b->ewasteStageBadge();
                        $amount = $b->reportAmount();
                        $ewxDoneRow = $b->isFinalized() || $b->status === 'completed';
                    @endphp
                    <tr>
                        <td class="ps-3 ewx-code">{{ $b->batch_number }}</td>
                        <td>{{ $b->vendor?->name ?? '—' }}</td>
                        <td class="text-center">{{ $b->items_count }}</td>
                        {{-- On an unfinished cycle any figure is the vendor's OFFER, not money
                             received — never render it with the same "+" income styling. --}}
                        <td class="text-end {{ $amount !== null && $ewxDoneRow ? 'ewx-amt' : 'text-muted' }}">
                            @if($amount === null)—@elseif($ewxDoneRow)+{{ number_format($amount, 2) }}@else{{ number_format($amount, 2) }} <span class="small">offer</span>@endif
                        </td>
                        <td>{{ fmt_date($b->created_at) }}</td>
                        <td>{{ $ewxDoneRow ? fmt_date($b->finalized_at) : '—' }}</td>
                        <td><span class="badge rounded-pill bg-{{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                        <td class="text-end pe-3">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="{{ route('reports.decommission.view', $b) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener" title="View report">
                                    <i class="bi bi-eye me-1"></i>View
                                </a>
                                <a href="{{ route('reports.decommission.pdf', $b) }}" class="btn btn-outline-primary" title="Download PDF">
                                    <i class="bi bi-download me-1"></i>PDF
                                </a>
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
@endif

{{-- The accounting fixed-asset register (acc_fixed_assets) — a different ledger from the
     e-waste cards above. Headed explicitly so the two are never read as one list. --}}
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
