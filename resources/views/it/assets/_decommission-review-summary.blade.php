{{-- Company Asset Decommissioning — review surface, part 1 of 2 (summary).
     Split from the by-company archive so an IT-only "Operations" block (Run sweep / Ready for
     the next sweep, in page.blade.php — this partial has none of that data) can be sandwiched
     between this and the by-company list without duplicating either.
     Sibling: `_decommission-review-by-company.blade.php`. Included by TWO hosts:
       - it/assets/page.blade.php  — inside the tab-pane, for canViewAssets() holders (IT/HR/
         superadmin). $awaiting is normally empty for them (they aren't Finance/management),
         so this renders as just the stat strip; it only shows a decision to a superadmin who
         is also a named approver.
       - it/assets/decommission-review.blade.php — the whole page, for Finance + named
         management approvers who do NOT have canViewAssets() at all.

     The filter panel (year/month/company/status) lives at the BOTTOM of this partial, under
     "Cycles in review" — it narrows the "Cycles by Company" list that
     `_decommission-review-by-company.blade.php` renders immediately after this include, not the
     stat strip or "Needs Your Decision" above it (see AssetController::buildDecommissionReview()
     for why those two stay unfiltered).

     Expects: $decomStats, $awaiting, $canFinance, $ewasteVendors, $cdFilters, $companyOptions,
     $statusOptions — built by AssetController::buildDecommissionReview(). --}}

{{-- Overview strip — computed controller-side over the full filtered set, not this page.
     One slim row rather than three dashboard cards: this is a working page, not an executive
     dashboard, and the cards were the single biggest use of vertical space before any actual
     content appeared. --}}
<div class="card ewx-card mb-3">
    <div class="ewx-stat-strip">
        <div class="ewx-stat-item">
            <span class="ewx-stat-icon ewx-stat-icon-slate"><i class="bi bi-box-seam"></i></span>
            <div>
                <div class="ewx-stat-number">{{ $decomStats['batches'] }}</div>
                <div class="ewx-stat-label">E-waste cycles completed</div>
            </div>
        </div>
        <div class="ewx-stat-item">
            <span class="ewx-stat-icon ewx-stat-icon-green"><i class="bi bi-recycle"></i></span>
            <div>
                <div class="ewx-stat-number">{{ $decomStats['assets'] }}</div>
                <div class="ewx-stat-label">Assets disposed</div>
            </div>
        </div>
        <div class="ewx-stat-item">
            <span class="ewx-stat-icon ewx-stat-icon-blue"><i class="bi bi-cash-coin"></i></span>
            <div>
                <div class="ewx-stat-number">RM {{ number_format($decomStats['recovered'], 2) }}</div>
                <div class="ewx-stat-label">Recovered from e-waste</div>
            </div>
        </div>
    </div>
</div>

{{-- ══════════════ Needs Your Decision ══════════════
     The cycles THIS user's own decision is still outstanding on — Finance and management each
     cast a real, mandatory, independent approve/reject. Loaded outside the paginated archive
     below on purpose: an approver has to reach a pending cycle whichever page of the list it
     would fall on, and the full comparison is worth rendering only for the handful that need
     one.

     Nothing is shown to a viewer with no reason to be here (hr_manager reads the archive,
     decides nothing and is not Finance), so this stays absent rather than becoming an empty
     panel. --}}
@if($awaiting->isNotEmpty())
<div class="section-header mb-3 mt-4">
    <h6 class="mb-0"><i class="bi bi-hourglass-split me-2 text-warning"></i>Needs Your Decision</h6>
</div>
<div class="card ewx-card mb-4">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-warn"><i class="bi bi-hourglass-split"></i></span>
        <div class="me-2">
            <span class="ewx-title">Cycles in review</span>
            <span class="ewx-sub">Every cycle awaiting YOUR decision. {{ $canFinance ? 'Approve or reject the offer — your decision is independent of management\'s, and the disposal needs both.' : 'Compare the vendors\' offers and decide — the vendor pays us for scrap, so the best offer is normally the highest.' }}</span>
        </div>
        <span class="ewx-count ewx-count-warn">{{ $awaiting->count() }}</span>
    </div>
    <div class="card-body p-0">
        @foreach($awaiting as $pending)
            @php
                $vndBest = $pending->bestOffer();
                $vndCollapseId = 'reviewCycle'.$pending->id;
            @endphp
            <div class="ewx-review-item">
                {{-- Compact summary — the full vendor comparison + decision forms only render
                     open when "Review & Decide" is pressed, via Bootstrap's own collapse data
                     attributes (no custom JS needed for this part). --}}
                <div class="ewx-review-summary">
                    <div class="ewx-review-main">
                        <a href="{{ route('decommission.show', $pending) }}" class="ewx-code">{{ $pending->batch_number }}</a>
                        <span class="text-muted small">
                            {{ $pending->issuingCompany() }} &middot;
                            {{ $pending->items_count }} asset{{ $pending->items_count === 1 ? '' : 's' }} &middot;
                            submitted {{ fmt_date($pending->submitted_for_approval_at) }}
                        </span>
                    </div>
                    <div class="ewx-review-meta">
                        @if($vndBest && $vndBest->amount !== null)
                            <span class="small text-muted">Best offer: <strong class="text-body">RM {{ number_format((float) $vndBest->amount, 2) }}</strong> ({{ $vndBest->vendorName() }})</span>
                        @endif
                        <span class="badge bg-{{ $pending->financeDecisionBadge()[0] }}">{{ $pending->financeDecisionBadge()[1] }}</span>
                        <span class="badge bg-{{ $pending->managementDecisionBadge()[0] }}">{{ $pending->managementDecisionBadge()[1] }}</span>
                        <button type="button" class="btn btn-sm btn-outline-primary ewx-review-toggle collapsed"
                                data-bs-toggle="collapse" data-bs-target="#{{ $vndCollapseId }}"
                                aria-expanded="false" aria-controls="{{ $vndCollapseId }}">
                            Review &amp; Decide <i class="bi bi-chevron-down ewx-chevron ms-1"></i>
                        </button>
                    </div>
                </div>

                <div class="collapse" id="{{ $vndCollapseId }}">
                    <div class="ewx-review-body">
                        <div class="ewx-review-body-head">
                            <span class="text-muted small fw-semibold text-uppercase">Reviewing {{ $pending->batch_number }}</span>
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="{{ route('reports.decommission.view', $pending) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                                    <i class="bi bi-eye me-1"></i>View PDF
                                </a>
                                <a href="{{ route('reports.decommission.pdf', $pending) }}" class="btn btn-outline-secondary">
                                    <i class="bi bi-download me-1"></i>Download PDF
                                </a>
                            </div>
                        </div>

                        {{-- The vendor comparison + both decision forms (Finance's and
                             management's). The status badges above already summarise where
                             each party stands, and the report itself (View/Download PDF above)
                             is where the full quotation documents live — so both are hidden
                             here to keep this panel focused on the decision. --}}
                        @include('it.decommission._quotation-comparison', [
                            'batch' => $pending,
                            // IT's upload and submit controls stay on the cycle page — this
                            // surface is for deciding, and offering to file a new offer mid-
                            // review would change the comparison under the person reviewing it.
                            'canManage' => false,
                            'canDecide' => Auth::user()->canApproveEwasteAsManagement($pending->company),
                            'canFinance' => $canFinance,
                            'ewasteVendors' => $ewasteVendors,
                            // The summary row above already shows these — repeating them right
                            // above the same table they gate reads as noise once expanded.
                            'hideStatusBadges' => true,
                        ])
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

{{-- Filter toolbar — narrows "Cycles by Company" immediately below (see the docblock note
     above on why the stat strip and "Needs Your Decision" are deliberately unaffected). --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex align-items-end gap-2 flex-wrap">
            <input type="hidden" name="tab" value="company-decom">
            <div>
                <label class="form-label mb-1 small fw-semibold text-muted">Year</label>
                <select name="cd_year" class="form-select form-select-sm" style="width:110px;">
                    <option value="">All</option>
                    @for($y = now()->year; $y >= now()->year - 5; $y--)
                        <option value="{{ $y }}" {{ (string) $cdFilters['year'] === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="form-label mb-1 small fw-semibold text-muted">Month</label>
                <select name="cd_month" class="form-select form-select-sm" style="width:130px;">
                    <option value="">All</option>
                    @for($m = 1; $m <= 12; $m++)
                        <option value="{{ $m }}" {{ (string) $cdFilters['month'] === (string) $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create()->month($m)->format('F') }}</option>
                    @endfor
                </select>
            </div>
            <div>
                <label class="form-label mb-1 small fw-semibold text-muted">Company</label>
                <select name="cd_company" class="form-select form-select-sm" style="width:200px;">
                    <option value="">All</option>
                    @foreach($companyOptions as $co)
                        <option value="{{ $co }}" {{ $cdFilters['company'] === $co ? 'selected' : '' }}>{{ $co }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label mb-1 small fw-semibold text-muted">Status</label>
                <select name="cd_status" class="form-select form-select-sm" style="width:200px;">
                    <option value="">All</option>
                    @foreach($statusOptions as $value => $label)
                        <option value="{{ $value }}" {{ $cdFilters['status'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
            @if(array_filter($cdFilters))
            <a href="{{ route('assets.index', ['tab' => 'company-decom']) }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            @endif
        </form>
    </div>
</div>
