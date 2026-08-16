@extends('layouts.app')
@section('title', 'Decommissioning Reports')
@section('page-title', 'Decommissioning Reports')

@section('content')
{{-- $hideFilters: this page ships its own Flow + Year bar below, so suppress the shared
     header's generic Year select — otherwise two Year dropdowns stack on top of each other. --}}
@include('reports.partials.report-header', ['hideFilters' => true])
@include('partials.dashboard-widgets-style')
@include('partials.decommission-ui-style')

{{-- Headline figures — computed controller-side over the full filtered set, not this page. --}}
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-4">
        <div class="card dash-widget h-100" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#64748b,#334155);">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-box-seam"></i></div>
                    <div>
                        <div class="widget-number">{{ $stats['batches'] }}</div>
                        <div class="widget-label">E-waste cycles completed</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card dash-widget h-100" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-recycle"></i></div>
                    <div>
                        <div class="widget-number">{{ $stats['assets'] }}</div>
                        <div class="widget-label">Assets disposed</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card dash-widget h-100" style="min-height:auto;">
            <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                <div class="d-flex align-items-center gap-3">
                    <div class="widget-icon"><i class="bi bi-cash-coin"></i></div>
                    <div>
                        <div class="widget-number">RM {{ number_format($stats['recovered'], 2) }}</div>
                        <div class="widget-label">Recovered from e-waste</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filter / action toolbar --}}
<div class="card mb-3">
    <div class="card-body py-2 d-flex align-items-center gap-2 flex-wrap">
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <label class="form-label mb-0 small fw-semibold text-muted">Year</label>
            <select name="year" class="form-select form-select-sm" style="width:110px;">
                <option value="">All</option>
                @for($y = now()->year; $y >= now()->year - 5; $y--)
                    <option value="{{ $y }}" {{ (string) $year === (string) $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
            <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
            @if($year)
            <a href="{{ route('reports.decommission') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            @endif
        </form>
        {{-- No link out to Accounting → Assets any more: quotation review lives on THIS page
             and nowhere else, so a button pointing somewhere else for it would recreate the
             split this page was made to end. --}}
    </div>
</div>

{{-- ══════════════ Cycles in review ══════════════
     The cycles this user can act on — management's authorisation, or Finance's optional
     remarks, or both. Loaded outside the paginated archive below on purpose: an approver has
     to reach a pending cycle whichever page of the list it would fall on, and the full
     comparison is worth rendering only for the handful that need one.

     Nothing is shown to a viewer with no reason to be here (hr_manager reads the archive,
     decides nothing and is not Finance), so this stays absent rather than becoming an empty
     panel. --}}
@if($awaiting->isNotEmpty())
<div class="card ewx-card mb-3">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-warn"><i class="bi bi-hourglass-split"></i></span>
        <div class="me-2">
            <span class="ewx-title">Cycles in review</span>
            <span class="ewx-sub">Every cycle currently awaiting a decision. {{ $canFinance ? 'Management authorise the disposal; your remarks are optional.' : 'Compare the vendors\' offers and decide — the vendor pays us for scrap, so the best offer is normally the highest.' }}</span>
        </div>
        <span class="ewx-count ewx-count-warn">{{ $awaiting->count() }}</span>
    </div>
    <div class="card-body">
        @foreach($awaiting as $pending)
            <div class="mb-4 pb-2 {{ ! $loop->last ? 'border-bottom' : '' }}">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <div>
                        <a href="{{ route('decommission.show', $pending) }}" class="ewx-code">{{ $pending->batch_number }}</a>
                        <span class="text-muted small ms-2">
                            {{ $pending->issuingCompany() }} &middot;
                            {{ $pending->items_count }} asset{{ $pending->items_count === 1 ? '' : 's' }} &middot;
                            submitted {{ fmt_date($pending->submitted_for_approval_at) }}
                        </span>
                    </div>
                    <a href="{{ route('reports.decommission.view', $pending) }}" target="_blank" rel="noopener"
                       class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-file-earmark-pdf me-1"></i>Asset list
                    </a>
                </div>

                @include('it.decommission._quotation-comparison', [
                    'batch' => $pending,
                    // IT's upload and submit controls stay on the cycle page — this surface is
                    // for deciding, and offering to file a new offer mid-review would change
                    // the comparison under the person reviewing it.
                    'canManage' => false,
                    'canDecide' => Auth::user()->canApproveEwasteAsManagement($pending->company),
                    'canFinance' => $canFinance,
                    'ewasteVendors' => $ewasteVendors,
                ])
            </div>
        @endforeach
    </div>
</div>
@endif

<div class="card ewx-card">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-slate"><i class="bi bi-archive"></i></span>
        <div class="me-2">
            <span class="ewx-title">E-waste Cycles</span>
            <span class="ewx-sub">Every disposal cycle, in-flight ones included. Rental returns are acknowledged on an AARF and archived on the vendor&rsquo;s profile.</span>
        </div>
        @if($batches->total())
        <span class="ewx-count">{{ $batches->total() }}</span>
        @endif
    </div>
    <div class="card-body p-0">
        @if($batches->isEmpty())
            <div class="ewx-empty">
                <i class="bi bi-inbox"></i>
                No e-waste cycles found{{ $year ? ' for this year' : '' }}.
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover ewx-table">
                <thead>
                    <tr>
                        <th class="ps-3">Cycle</th>
                        <th class="text-center">Qty</th>
                        <th>Vendor</th>
                        <th class="text-end">Amount (RM)</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Report</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($batches as $batch)
                    @php
                        // ewasteStageBadge(), not statusBadge(): this page is read by Finance
                        // and management, and the two readings diverge exactly where it
                        // matters — an approved cycle is "done" to IT but still open here
                        // until the vendor has collected and paid.
                        [$badgeClass, $badgeLabel] = $batch->ewasteStageBadge();
                        $amt = $batch->reportAmount();
                        $vndDone = $batch->isFinalized() || $batch->status === 'completed';
                    @endphp
                    <tr>
                        <td class="ps-3">
                            <a href="{{ route('decommission.show', $batch) }}" class="ewx-code">{{ $batch->batch_number }}</a>
                        </td>
                        <td class="text-center">{{ $batch->items_count }}</td>
                        <td>{{ $batch->vendor?->name ?? '—' }}</td>
                        {{-- An offer on an unfinished cycle is money nobody has been paid, so it
                             prints as a muted "offer" rather than a +credit. Same guard as the
                             headline tile, which counts completed cycles only. --}}
                        <td class="text-end {{ $amt !== null && $vndDone ? 'ewx-amt' : 'text-muted' }}">
                            @if($amt === null)
                                —
                            @elseif($vndDone)
                                +{{ number_format($amt, 2) }}
                            @else
                                {{ number_format($amt, 2) }} offer
                            @endif
                        </td>
                        <td>{{ fmt_date($batch->finalized_at ?? $batch->created_at) }}</td>
                        <td><span class="badge rounded-pill bg-{{ $badgeClass }}">{{ $badgeLabel }}</span></td>
                        <td class="text-end pe-3">
                            <div class="btn-group btn-group-sm" role="group">
                                <a href="{{ route('reports.decommission.view', $batch) }}" class="btn btn-outline-secondary" target="_blank" rel="noopener" title="View report">
                                    <i class="bi bi-eye me-1"></i>View
                                </a>
                                <a href="{{ route('reports.decommission.pdf', $batch) }}" class="btn btn-outline-primary" title="Download PDF">
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
@if($batches->hasPages())
<div class="mt-3">{{ $batches->links() }}</div>
@endif
@endsection
