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
        {{-- Quotation approval lives on Accounting → Assets → status "Disposed" and nowhere
             else; only link out for users who can actually open that page (it_manager and
             hr_manager can approve nothing and have no accounting access). --}}
        @if(Auth::user()->canApproveEwasteQuotation() && Auth::user()->canViewAccounting())
        <a href="{{ route('accounting.fixed-assets.index', ['status' => 'disposed']) }}" class="btn btn-sm btn-outline-warning ms-auto">
            <i class="bi bi-cash-coin me-1"></i>Pending Quotations
        </a>
        @endif
    </div>
</div>

<div class="card ewx-card">
    <div class="ewx-head">
        <span class="ewx-chip ewx-chip-slate"><i class="bi bi-archive"></i></span>
        <div class="me-2">
            <span class="ewx-title">Decommissioning Archive</span>
            <span class="ewx-sub">Every completed e-waste disposal cycle. Rental returns are acknowledged on an AARF and archived on the vendor&rsquo;s profile.</span>
        </div>
        @if($batches->total())
        <span class="ewx-count">{{ $batches->total() }}</span>
        @endif
    </div>
    <div class="card-body p-0">
        @if($batches->isEmpty())
            <div class="ewx-empty">
                <i class="bi bi-inbox"></i>
                No completed e-waste cycles found{{ $year ? ' for this year' : '' }}.
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
                    @php [$badgeClass, $badgeLabel] = $batch->statusBadge(); $amt = $batch->reportAmount(); @endphp
                    <tr>
                        <td class="ps-3">
                            <a href="{{ route('decommission.show', $batch) }}" class="ewx-code">{{ $batch->batch_number }}</a>
                        </td>
                        <td class="text-center">{{ $batch->items_count }}</td>
                        <td>{{ $batch->vendor?->name ?? '—' }}</td>
                        <td class="text-end {{ $amt !== null ? 'ewx-amt' : 'text-muted' }}">{{ $amt !== null ? '+'.number_format($amt, 2) : '—' }}</td>
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
