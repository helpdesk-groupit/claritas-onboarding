@extends('layouts.app')

@section('title', 'My Tickets')
@section('page-title', 'My Tickets')

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    /* ── Company → Department accordion (mirrors the Ticket Management page) ── */
    .company-section { border:1px solid #e2e8f0; border-radius:10px; margin-bottom:12px; overflow:hidden; background:#fff; }
    .company-header  { display:flex; align-items:center; gap:10px; width:100%; padding:14px 16px; background:#e0f2fe; border:none; text-align:left; cursor:pointer; transition:background .15s; }
    .company-header:hover { background:#bae6fd; }
    .company-header .chev { font-size:14px; transition:transform .2s; flex-shrink:0; color:#075985; }
    .company-header.expanded .chev { transform:rotate(90deg); }
    .company-header .name { font-weight:700; color:#075985; flex:1; }
    .company-header .hint { font-size:11px; color:#0c4a6e; opacity:.7; font-style:italic; }
    .company-header .count { background:#0369a1; color:#fff; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600; }

    .company-body    { display:none; padding:8px 12px 12px; }
    .company-body.show { display:block; }

    .dept-section    { margin:8px 0; border-radius:8px; overflow:hidden; border:1px solid #e2e8f0; }
    .dept-header     { display:flex; align-items:center; gap:10px; width:100%; padding:9px 14px; background:#f1f5f9; border:none; text-align:left; cursor:pointer; transition:background .15s; }
    .dept-header:hover { background:#e2e8f0; }
    .dept-header .chev { font-size:12px; transition:transform .2s; flex-shrink:0; color:#475569; }
    .dept-header.expanded .chev { transform:rotate(90deg); }
    .dept-header .name { font-weight:600; color:#1e293b; flex:1; font-size:14px; }
    .dept-header .hint { font-size:11px; color:#64748b; opacity:.7; font-style:italic; }
    .dept-header .count { background:#64748b; color:#fff; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }

    .dept-body       { display:none; }
    .dept-body.show  { display:block; }

    .dept-body table { margin-bottom:0; font-size:13px; }
    .dept-body th    { background:#fafafa; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }

    .bulk-bar { display:flex; gap:8px; align-items:center; margin-bottom:12px; }

    [data-theme="dark"] .company-section { background:#1e293b; border-color:#334155; }
    [data-theme="dark"] .company-header  { background:#0f172a; }
    [data-theme="dark"] .company-header:hover { background:#1e293b; }
    [data-theme="dark"] .company-header .name,
    [data-theme="dark"] .company-header .chev,
    [data-theme="dark"] .company-header .hint { color:#bae6fd; }
    [data-theme="dark"] .dept-section { border-color:#334155; }
    [data-theme="dark"] .dept-header  { background:#0f172a; }
    [data-theme="dark"] .dept-header:hover { background:#1e293b; }
    [data-theme="dark"] .dept-header .name,
    [data-theme="dark"] .dept-header .hint { color:#cbd5e1; }
    [data-theme="dark"] .dept-body th { background:#0f172a; color:#94a3b8; }

    /* ── Analytics cards (PIC view on the Assigned to Me tab — same look
         as the Ticket Management page so the visual is consistent) ── */
    .analytics-card { border:none; border-radius:14px; overflow:hidden; box-shadow:0 2px 6px rgba(0,0,0,0.08); background:#fff; display:flex; flex-direction:column; }
    .analytics-card .card-fancy-header { padding:18px 20px; color:#fff; position:relative; overflow:hidden; display:flex; align-items:center; gap:14px; }
    .analytics-card .card-fancy-header::before { content:''; position:absolute; right:-30px; top:-50px; width:140px; height:140px; border-radius:50%; background:rgba(255,255,255,0.08); }
    .analytics-card .card-fancy-header::after  { content:''; position:absolute; right:30px; bottom:-50px; width:90px; height:90px; border-radius:50%; background:rgba(255,255,255,0.06); }
    .analytics-card.blue   .card-fancy-header { background:linear-gradient(135deg,#3b82f6,#1e40af); }
    .analytics-card.green  .card-fancy-header { background:linear-gradient(135deg,#10b981,#047857); }
    .analytics-card.orange .card-fancy-header { background:linear-gradient(135deg,#f59e0b,#d97706); }
    .analytics-card .icon-box { width:48px; height:48px; border-radius:12px; background:rgba(255,255,255,0.18); display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; position:relative; z-index:1; }
    .analytics-card .header-meta { flex:1; min-width:0; position:relative; z-index:1; }
    .analytics-card .number { font-size:28px; font-weight:700; line-height:1.05; }
    .analytics-card .subtitle { font-size:12px; opacity:0.9; line-height:1.2; }
    .analytics-card .body-section { padding:14px 18px; flex:1; overflow-y:auto; max-height:320px; }
    .analytics-card .body-label { font-size:10px; text-transform:uppercase; color:#94a3b8; letter-spacing:1.2px; margin-bottom:6px; font-weight:700; }
    .analytics-card .item-row { display:flex; align-items:center; justify-content:space-between; padding:9px 4px; border-bottom:1px solid #f1f5f9; gap:10px; }
    .analytics-card .item-row:last-child { border-bottom:none; }
    .analytics-card .item-row .name { font-size:13px; color:#334155; flex:1; min-width:0; }
    .analytics-card .badge-pill { border-radius:999px; padding:3px 11px; font-size:11px; font-weight:600; color:#fff; min-width:32px; text-align:center; flex-shrink:0; }
    .analytics-card.blue   .badge-pill { background:#3b82f6; }
    .analytics-card.green  .badge-pill { background:#10b981; }
    .analytics-card.orange .badge-pill { background:#f59e0b; }

    .perf-row { display:flex; align-items:center; gap:8px; padding:8px 4px; border-bottom:1px solid #f1f5f9; font-size:12px; }
    .perf-row:last-child { border-bottom:none; }
    .perf-row .perf-name { flex:0 0 35%; min-width:0; color:#334155; line-height:1.3; }
    .perf-row .perf-name .perf-meta { display:block; font-size:10px; color:#94a3b8; }
    .perf-row .perf-bar-wrap { flex:1; min-width:50px; }
    .perf-row .perf-bar { background:#e2e8f0; border-radius:4px; height:8px; overflow:hidden; position:relative; }
    .perf-row .perf-bar-fill { height:100%; border-radius:4px; transition:width .25s ease; }
    .perf-row .perf-bar-fill.tier-good   { background:linear-gradient(90deg,#22c55e,#16a34a); }
    .perf-row .perf-bar-fill.tier-amber  { background:linear-gradient(90deg,#fbbf24,#f59e0b); }
    .perf-row .perf-bar-fill.tier-poor   { background:linear-gradient(90deg,#f87171,#dc2626); }
    .perf-row .perf-bar-fill.tier-nodata { background:#cbd5e1; }
    .perf-row .perf-time { flex:0 0 auto; min-width:55px; text-align:right; font-weight:600; font-size:11px; }
    .perf-row .perf-time.tier-good   { color:#16a34a; }
    .perf-row .perf-time.tier-amber  { color:#d97706; }
    .perf-row .perf-time.tier-poor   { color:#dc2626; }
    .perf-row .perf-time.tier-nodata { color:#94a3b8; }

    .tier-summary { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; padding:8px; background:#fff7ed; border-radius:8px; border:1px solid #fed7aa; }
    .tier-summary-chip { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:3px 8px; border-radius:999px; background:#fff; border:1px solid #e2e8f0; }
    .tier-summary-chip .dot { width:8px; height:8px; border-radius:50%; }
    .tier-summary-chip.tier-good   .dot { background:#16a34a; }
    .tier-summary-chip.tier-amber  .dot { background:#f59e0b; }
    .tier-summary-chip.tier-poor   .dot { background:#dc2626; }
    .tier-summary-chip.tier-nodata .dot { background:#cbd5e1; }

    [data-theme="dark"] .analytics-card { background:#1e293b; }
    [data-theme="dark"] .analytics-card .item-row { border-color:#334155; }
    [data-theme="dark"] .analytics-card .item-row .name { color:#cbd5e1; }
    [data-theme="dark"] .perf-row { border-color:#334155; }
    [data-theme="dark"] .perf-row .perf-bar { background:#475569; }
    [data-theme="dark"] .tier-summary { background:#0f172a; border-color:#475569; }
    [data-theme="dark"] .tier-summary-chip { background:#1e293b; border-color:#475569; color:#cbd5e1; }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="bi bi-ticket-detailed me-1"></i> My Tickets
                </h5>
                <small class="text-muted">
                    @if($scope === 'assigned')
                        {{ $tickets->total() }} assigned to you
                    @else
                        {{ $tickets->total() }} raised by you
                    @endif
                </small>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-info btn-sm"
                        data-bs-toggle="modal" data-bs-target="#userManualTicketsModal">
                    <i class="bi bi-book me-1"></i> User Manual
                </button>
                @if(Auth::user()->canAccessTicketManagement())
                    <a href="{{ route('tickets.manage') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-gear me-1"></i> Ticket Management
                    </a>
                @endif
                <a href="{{ route('tickets.create') }}" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus-lg me-1"></i> New Ticket
                </a>
            </div>
        </div>

        {{-- Tabs: Active / Assigned to Me / Archived --}}
        @php
            $tabs = [
                'active'   => ['label' => 'Active Tickets',  'icon' => 'bi-collection'],
                'assigned' => ['label' => 'Assigned to Me',  'icon' => 'bi-inbox-fill'],
                'archived' => ['label' => 'Archived',        'icon' => 'bi-archive-fill'],
            ];
        @endphp
        <ul class="nav nav-tabs mb-3">
            @foreach($tabs as $key => $tab)
                <li class="nav-item">
                    <a class="nav-link {{ $scope === $key ? 'active fw-semibold' : '' }}"
                       href="{{ request()->fullUrlWithQuery(['scope' => $key, 'page' => null, 'status' => null]) }}">
                        <i class="bi {{ $tab['icon'] }} me-1"></i>
                        {{ $tab['label'] }}
                        <span class="badge rounded-pill ms-1 {{ $scope === $key ? 'bg-primary' : 'bg-secondary' }}">
                            {{ $counts[$key] }}
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        {{-- Status filter (options vary by tab) --}}
        <form method="GET" action="{{ route('tickets.index') }}" class="row g-2 mb-3">
            <input type="hidden" name="scope" value="{{ $scope }}">
            <div class="col-sm-4">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses in this tab</option>
                    @foreach($statusOptions as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-4 d-flex gap-2">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                <a href="{{ route('tickets.index', ['scope' => $scope]) }}" class="btn btn-outline-secondary btn-sm">Reset Filter</a>
            </div>
        </form>

        {{-- PIC analytics: only visible on the "Assigned to Me" tab. Same shape
             as the Ticket Management cards but scoped: own active priorities,
             own resolution performance, own department's health. --}}
        @if($scope === 'assigned' && $analytics)
            <div class="row g-3 mb-3">
                {{-- Card 1: Active Tickets by Priority (mine only) --}}
                <div class="col-lg-4">
                    <div class="analytics-card blue h-100">
                        <div class="card-fancy-header">
                            <div class="icon-box"><i class="bi bi-flag-fill"></i></div>
                            <div class="header-meta">
                                <div class="number">{{ $analytics['totalActive'] }}</div>
                                <div class="subtitle">Active Tickets · Assigned to Me</div>
                            </div>
                        </div>
                        <div class="body-section">
                            <div class="body-label">By Priority</div>
                            @foreach(\App\Models\Ticket::PRIORITIES as $p)
                                <div class="item-row">
                                    <span class="name">{{ $p }}</span>
                                    <span class="badge-pill">{{ $analytics['byPriority'][$p] ?? 0 }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                @include('tickets.partials.analytics-card-2-pic-times')
                @include('tickets.partials.analytics-card-3-dept-health')
            </div>
        @endif

        @if($tickets->isEmpty())
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox" style="font-size:42px;"></i>
                <p class="mt-2 mb-0">
                    @if($scope === 'assigned')
                        No tickets are currently assigned to you.
                    @elseif($scope === 'archived')
                        No archived tickets.
                    @elseif($counts['active'] === 0 && $counts['archived'] === 0)
                        You haven't raised any tickets yet.
                    @else
                        No active tickets matching the filter.
                    @endif
                </p>
                @if($scope !== 'assigned' && $counts['active'] === 0 && $counts['archived'] === 0)
                    <a href="{{ route('tickets.create') }}" class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-plus-lg me-1"></i> Raise your first ticket
                    </a>
                @endif
            </div>
        @else
            {{-- Bulk expand/collapse --}}
            <div class="bulk-bar">
                <button type="button" class="btn btn-sm btn-outline-primary" id="expandAllBtn">
                    <i class="bi bi-arrows-expand me-1"></i> Expand all
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="collapseAllBtn">
                    <i class="bi bi-arrows-collapse me-1"></i> Collapse all
                </button>
                <small class="text-muted ms-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Click a company to see its departments, then click a department to see tickets.
                </small>
            </div>

            {{-- Company → Department → Tickets accordion --}}
            @foreach($grouped as $companyName => $byDept)
                @php
                    $companyTotal = $byDept->reduce(fn($carry, $t) => $carry + count($t), 0);
                @endphp
                <div class="company-section">
                    <button type="button" class="company-header" data-toggle="company">
                        <i class="bi bi-chevron-right chev"></i>
                        <i class="bi bi-building"></i>
                        <span class="name">{{ $companyName }}</span>
                        <span class="hint">Click to view departments</span>
                        <span class="count">{{ $companyTotal }}</span>
                    </button>
                    <div class="company-body">
                        @foreach($byDept as $deptName => $deptTickets)
                            <div class="dept-section">
                                <button type="button" class="dept-header" data-toggle="dept">
                                    <i class="bi bi-chevron-right chev"></i>
                                    <i class="bi bi-tag"></i>
                                    <span class="name">{{ $deptName }}</span>
                                    <span class="hint">Click to view tickets</span>
                                    <span class="count">{{ count($deptTickets) }}</span>
                                </button>
                                <div class="dept-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Ticket #</th>
                                                    <th>Created On</th>
                                                    <th>Subject</th>
                                                    <th>Priority</th>
                                                    <th>Status</th>
                                                    <th>Assigned To</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            @foreach($deptTickets as $t)
                                                <tr>
                                                    <td><span class="fw-semibold text-primary">{{ $t->ticket_number }}</span></td>
                                                    <td class="small text-muted">{{ $t->created_at?->format('d M Y') }}</td>
                                                    <td>{{ \Illuminate\Support\Str::limit($t->subject, 60) }}</td>
                                                    <td><span class="badge bg-{{ $t->priorityColor() }}">{{ $t->priority }}</span></td>
                                                    <td><span class="badge bg-{{ $t->statusColor() }}">{{ $t->status }}</span></td>
                                                    <td class="small">{{ $t->assignee?->name ?? '—' }}</td>
                                                    <td>
                                                        <a href="{{ route('tickets.show', $t) }}" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-chat-dots"></i> Open
                                                        </a>
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach

            <div class="mt-3">{{ $tickets->links() }}</div>
        @endif
    </div>
</div>

@include('partials._user-manual-tickets')
@endsection

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    'use strict';

    document.querySelectorAll('.company-header').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = btn.nextElementSibling;
            if (!body) return;
            var willOpen = !body.classList.contains('show');
            body.classList.toggle('show', willOpen);
            btn.classList.toggle('expanded', willOpen);
            var hint = btn.querySelector('.hint');
            if (hint) hint.textContent = willOpen ? 'Click to collapse' : 'Click to view departments';
        });
    });

    document.querySelectorAll('.dept-header').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var body = btn.nextElementSibling;
            if (!body) return;
            var willOpen = !body.classList.contains('show');
            body.classList.toggle('show', willOpen);
            btn.classList.toggle('expanded', willOpen);
            var hint = btn.querySelector('.hint');
            if (hint) hint.textContent = willOpen ? 'Click to collapse' : 'Click to view tickets';
        });
    });

    var expandAllBtn   = document.getElementById('expandAllBtn');
    var collapseAllBtn = document.getElementById('collapseAllBtn');

    function setAll(open) {
        document.querySelectorAll('.company-body, .dept-body').forEach(function (el) {
            el.classList.toggle('show', open);
        });
        document.querySelectorAll('.company-header, .dept-header').forEach(function (btn) {
            btn.classList.toggle('expanded', open);
            var hint = btn.querySelector('.hint');
            if (!hint) return;
            if (open) {
                hint.textContent = 'Click to collapse';
            } else {
                hint.textContent = btn.classList.contains('company-header')
                    ? 'Click to view departments'
                    : 'Click to view tickets';
            }
        });
    }

    if (expandAllBtn)   expandAllBtn.addEventListener('click', function () { setAll(true); });
    if (collapseAllBtn) collapseAllBtn.addEventListener('click', function () { setAll(false); });
})();
</script>
@endpush
