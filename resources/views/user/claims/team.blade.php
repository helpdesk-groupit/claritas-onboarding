@extends('layouts.app')
@section('title', 'Team Claims')

@section('content')
@include('partials.dashboard-widgets-style')
<div class="container-fluid py-4">

    {{-- ── Page Header ── --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-people-fill me-2"></i>Team Expense Claims</h3>
            <p class="text-muted mb-0">Review and approve your team members' claims</p>
        </div>
        <a href="{{ route('user.claims.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-receipt-cutoff me-1"></i>My Claims
        </a>
    </div>

    {{-- success/error flash is rendered globally by layouts/app.blade.php --}}

    {{-- ── Manager-perspective stat cards ── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number">{{ $cardCounts['pending'] }}</div>
                            <div class="widget-label">Pending Manager Approval</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-check-circle-fill"></i></div>
                        <div>
                            <div class="widget-number">{{ $cardCounts['approved'] }}</div>
                            <div class="widget-label">Manager Approved</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#ef4444,#b91c1c);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-x-octagon-fill"></i></div>
                        <div>
                            <div class="widget-number">{{ $cardCounts['rejected'] }}</div>
                            <div class="widget-label">Manager Rejected</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#9333ea,#6b21a8);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-exclamation-octagon-fill"></i></div>
                        <div>
                            <div class="widget-number">{{ $cardCounts['hr_rejected'] }}</div>
                            <div class="widget-label">Manager Approved &amp; HR Rejected</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @php
        // Manager-perspective status group + badge for each claim.
        $mgrGroup = fn ($s) => match ($s) {
            'submitted' => 'pending',
            'manager_approved', 'hr_approved', 'paid' => 'approved',
            'manager_rejected' => 'rejected',
            'hr_rejected' => 'hr_rejected',
            default => 'other',
        };
        $countIn = fn ($g) => $myClaims->filter(fn ($c) => $mgrGroup($c->status) === $g)->count();
        $byYear = $myClaims->groupBy('year')->sortKeysDesc();
        $currentYear = now()->year;
    @endphp

    @if($myClaims->isNotEmpty())
    {{-- ── Filter + search panel ── --}}
    <div class="claim-filter-panel mb-4">
        <div class="claim-filter-label"><i class="bi bi-funnel-fill me-2"></i>Filter &amp; search</div>
        <div class="claim-filters d-flex flex-wrap gap-2 justify-content-center mb-3">
            <button type="button" class="claim-filter-btn active" data-filter="all">All <span class="cf-count">{{ $myClaims->count() }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="pending">Pending Manager <span class="cf-count">{{ $countIn('pending') }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="approved">Manager Approved <span class="cf-count">{{ $countIn('approved') }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="rejected">Manager Rejected <span class="cf-count">{{ $countIn('rejected') }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="hr_rejected">HR Rejected <span class="cf-count">{{ $countIn('hr_rejected') }}</span></button>
        </div>
        <div class="row g-2 justify-content-center">
            <div class="col-12 col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input type="text" id="teamSearch" class="form-control" placeholder="Search employee name or event…">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                    <input type="month" id="teamMonth" class="form-control" title="Filter by claim month">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="bi bi-calendar-date"></i></span>
                    <input type="date" id="teamDate" class="form-control" title="Filter by submitted date">
                </div>
            </div>
            <div class="col-12 col-md-1 d-grid">
                <button type="button" id="teamReset" class="btn btn-outline-secondary btn-sm" title="Clear filters"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>
        <div class="text-center small text-muted mt-2 d-none" id="teamNoMatch"><i class="bi bi-info-circle me-1"></i>No claims match your filters.</div>
    </div>

    {{-- ── Listing: Year → Month → Employee → Claims ── --}}
    @foreach($byYear as $yr => $yearClaims)
    @php
        $yrOpen = (int) $yr === (int) $currentYear;
        $byMonth = $yearClaims->groupBy('month')->sortKeysDesc();
    @endphp
    <div class="acc-group year-grp mb-3" data-default-open="{{ $yrOpen ? '1' : '0' }}">
        <button type="button" class="acc-head year-head" data-bs-toggle="collapse" data-bs-target="#ty-{{ $yr }}" aria-expanded="{{ $yrOpen ? 'true' : 'false' }}">
            <span class="acc-head-left">
                <span class="acc-chip"><i class="bi bi-calendar3"></i></span>
                <span><span class="acc-title">{{ $yr }}</span><span class="acc-sub">{{ $yearClaims->count() }} claim{{ $yearClaims->count() === 1 ? '' : 's' }}</span><span class="acc-hint"></span></span>
            </span>
            <span class="acc-head-right">
                <span class="acc-total" title="Total of HR-approved claims">RM {{ number_format($yearClaims->whereIn('status', ['hr_approved', 'paid'])->sum('total_with_gst'), 2) }}</span>
                <i class="bi bi-chevron-down acc-chev"></i>
            </span>
        </button>
        <div class="collapse {{ $yrOpen ? 'show' : '' }}" id="ty-{{ $yr }}">
            <div class="acc-body year-body">
                @foreach($byMonth as $mo => $monthClaims)
                @php
                    $moOpen = $yrOpen && $loop->first;
                    $monthLabel = \Carbon\Carbon::createFromDate($yr, $mo, 1)->format('F Y');
                    $byEmp = $monthClaims->groupBy('employee_id')->sortBy(fn ($g) => $g->first()->employee->full_name ?? '');
                @endphp
                <div class="acc-group month-grp" data-default-open="{{ $moOpen ? '1' : '0' }}">
                    <button type="button" class="acc-head month-head" data-bs-toggle="collapse" data-bs-target="#tm-{{ $yr }}-{{ $mo }}" aria-expanded="{{ $moOpen ? 'true' : 'false' }}">
                        <span class="acc-head-left">
                            <span class="acc-chip"><i class="bi bi-calendar3"></i></span>
                            <span><span class="acc-title">{{ $monthLabel }}</span><span class="acc-sub">{{ $byEmp->count() }} staff · {{ $monthClaims->count() }} claim{{ $monthClaims->count() === 1 ? '' : 's' }}</span><span class="acc-hint"></span></span>
                        </span>
                        <span class="acc-head-right">
                            <span class="acc-total" title="Total of HR-approved claims">RM {{ number_format($monthClaims->whereIn('status', ['hr_approved', 'paid'])->sum('total_with_gst'), 2) }}</span>
                            <i class="bi bi-chevron-down acc-chev"></i>
                        </span>
                    </button>
                    <div class="collapse {{ $moOpen ? 'show' : '' }}" id="tm-{{ $yr }}-{{ $mo }}">
                        <div class="acc-body month-body">
                            @foreach($byEmp as $empId => $empClaims)
                            @php $person = $empClaims->first()->employee; @endphp
                            <div class="acc-group emp-grp" data-default-open="1">
                                <button type="button" class="acc-head emp-head" data-bs-toggle="collapse" data-bs-target="#te-{{ $yr }}-{{ $mo }}-{{ $empId }}" aria-expanded="true">
                                    <span class="acc-head-left">
                                        <span class="acc-chip"><i class="bi bi-person-fill"></i></span>
                                        <span><span class="acc-title">{{ $person->full_name ?? 'Unknown' }}</span><span class="acc-sub">{{ $person->department ?? '—' }} · {{ $empClaims->count() }} claim{{ $empClaims->count() === 1 ? '' : 's' }}</span><span class="acc-hint"></span></span>
                                    </span>
                                    <span class="acc-head-right">
                                        <span class="acc-total" title="Total of HR-approved claims">RM {{ number_format($empClaims->whereIn('status', ['hr_approved', 'paid'])->sum('total_with_gst'), 2) }}</span>
                                        <i class="bi bi-chevron-down acc-chev"></i>
                                    </span>
                                </button>
                                <div class="collapse show" id="te-{{ $yr }}-{{ $mo }}-{{ $empId }}">
                                    <div class="acc-body emp-body">
                                        @foreach($empClaims as $claim)
                                        @php
                                            $isPending = $claim->status === 'submitted';
                                            $myItems = $employee ? $claim->items->where('approver_id', $employee->id)->values() : collect();
                                        @endphp
                                        <div class="claim-card-wrap"
                                             data-status-group="{{ $mgrGroup($claim->status) }}"
                                             data-employee="{{ strtolower($person->full_name ?? '') }}"
                                             data-event="{{ strtolower($claim->event ?: '') }}"
                                             data-month="{{ sprintf('%04d-%02d', $claim->year, $claim->month) }}"
                                             data-date="{{ $claim->submitted_at?->format('Y-m-d') ?? '' }}">
                                            <div class="claim-row">
                                                <div>
                                                    <div class="ev-title">{{ $claim->event ?: 'Untitled event' }}</div>
                                                    <div class="ev-sub">{{ $claim->claim_number }} · {{ $claim->item_count }} item{{ $claim->item_count === 1 ? '' : 's' }} · Submitted {{ $claim->submitted_at?->format('d M Y') ?? '—' }}</div>
                                                    <div class="mt-1 d-inline-flex flex-wrap gap-1">
                                                        @if($claim->correction_of_id)
                                                        <span class="badge bg-info text-dark" title="Resubmission of a rejected claim"><i class="bi bi-arrow-repeat me-1"></i>Resubmitted</span>
                                                        @endif
                                                        @foreach($claim->stageBadges() as $sb)
                                                        <span class="badge bg-{{ $sb['class'] }} {{ $sb['class'] === 'warning' ? 'text-dark' : '' }}">{{ $sb['label'] }}</span>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                <div class="claim-row-meta">
                                                    <span class="ev-amount">RM {{ number_format($claim->total_with_gst, 2) }}</span>
                                                    <a href="{{ route('claims.review', $claim) }}" class="btn btn-sm {{ $isPending ? 'btn-primary' : 'btn-outline-secondary' }}">
                                                        <i class="bi bi-eye me-1"></i>{{ $isPending ? 'Review' : 'View report' }}
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endforeach
    @else
    <div class="card shadow-sm border-0">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox text-secondary" style="font-size:2.5rem;"></i>
            <p class="mt-2 mb-0">No team claims yet — claims routed to you for approval will appear here.</p>
        </div>
    </div>
    @endif

</div>

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    /* Make the gradient header fill the whole stat card (so equal-height cards stay full-colour). */
    .dash-widget { display: flex; flex-direction: column; }
    .dash-widget .widget-header { flex: 1 1 auto; }

    /* ── Filter panel (matches My Claims) ── */
    .claim-filter-panel { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:1rem 1.1rem; box-shadow:0 2px 10px rgba(15,23,42,.06); }
    .claim-filter-label { text-align:center; font-size:.72rem; font-weight:700; letter-spacing:.09em; text-transform:uppercase; color:#64748b; margin-bottom:.75rem; }
    .claim-filter-btn { display:inline-flex; align-items:center; gap:.5rem; border:2px solid #cbd5e1; background:#fff; color:#1e293b; border-radius:999px; font-weight:600; font-size:.9rem; padding:.45rem 1.15rem; transition:all .15s ease; box-shadow:0 1px 3px rgba(15,23,42,.08); }
    .claim-filter-btn:hover { background:#eef2ff; border-color:#6366f1; box-shadow:0 3px 8px rgba(99,102,241,.2); transform:translateY(-1px); }
    .claim-filter-btn .cf-count { background:#e2e8f0; color:#334155; border-radius:999px; font-size:.76rem; font-weight:700; padding:.08rem .5rem; min-width:1.6em; text-align:center; }
    .claim-filter-btn.active { background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; border-color:transparent; box-shadow:0 3px 8px rgba(37,99,235,.3); }
    .claim-filter-btn.active .cf-count { background:rgba(255,255,255,.25); color:#fff; }

    /* ── Year → Month → Employee accordion (4 distinct shades) ── */
    .acc-group { border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; background:#fff; box-shadow:0 1px 4px rgba(15,23,42,.06); }
    .acc-head { width:100%; border:0; text-align:left; cursor:pointer; display:flex; justify-content:space-between; align-items:center; padding:.8rem 1.1rem; border-bottom:1px solid transparent; transition:filter .15s ease; }
    .acc-head-left, .acc-head-right { display:flex; align-items:center; gap:.75rem; }
    .acc-chip { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:1.05rem; }
    .acc-title { display:block; font-weight:700; font-size:1rem; line-height:1.2; }
    .acc-sub { font-size:.74rem; }
    .acc-total { font-weight:700; white-space:nowrap; }
    .acc-chev { transition:transform .2s ease; }
    .acc-head[aria-expanded="false"] .acc-chev { transform:rotate(-90deg); }
    .acc-hint { font-size:.72rem; font-style:italic; opacity:.8; white-space:nowrap; }
    .acc-hint::before { content:'·'; margin:0 .35rem 0 .15rem; opacity:.7; }
    @media (max-width: 575.98px) { .acc-hint { display:none; } }

    /* Year = dark slate */
    .year-head { background:linear-gradient(135deg,#1e293b,#334155); }
    .year-head:hover { filter:brightness(1.12); }
    .year-head .acc-title, .year-head .acc-total { color:#fff; }
    .year-head .acc-sub, .year-head .acc-chev, .year-head .acc-hint { color:#cbd5e1; }
    .year-head .acc-chip { background:rgba(255,255,255,.16); color:#fff; }
    .year-head .acc-hint::after { content:'Click to view months'; }
    .year-head[aria-expanded="true"] .acc-hint::after { content:'Click to collapse'; }

    /* Month = indigo */
    .month-head { background:linear-gradient(135deg,#c7d2fe,#dbe4ff); }
    .month-head:hover { filter:brightness(.98); }
    .month-head[aria-expanded="true"] { border-bottom-color:#aab8f0; }
    .month-head .acc-title { color:#1e293b; } .month-head .acc-sub, .month-head .acc-chev, .month-head .acc-hint { color:#475569; } .month-head .acc-total { color:#1d4ed8; }
    .month-head .acc-chip { background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; box-shadow:0 3px 7px rgba(37,99,235,.3); }
    .month-head .acc-hint::after { content:'Click to view staff'; }
    .month-head[aria-expanded="true"] .acc-hint::after { content:'Click to collapse'; }

    /* Employee = light */
    .emp-head { background:#eef2f7; }
    .emp-head:hover { filter:brightness(.98); }
    .emp-head[aria-expanded="true"] { border-bottom-color:#dbe2ee; }
    .emp-head .acc-title { color:#1e293b; } .emp-head .acc-sub, .emp-head .acc-chev, .emp-head .acc-hint { color:#64748b; } .emp-head .acc-total { color:#1d4ed8; }
    .emp-head .acc-chip { background:linear-gradient(135deg,#8b5cf6,#6d28d9); color:#fff; box-shadow:0 3px 7px rgba(139,92,246,.3); }
    .emp-head .acc-hint::after { content:'Click to view claims'; }
    .emp-head[aria-expanded="true"] .acc-hint::after { content:'Click to collapse'; }

    /* Bodies + nesting */
    .acc-body { padding:.55rem; display:flex; flex-direction:column; gap:.55rem; }
    .year-body { background:#f1f5f9; }
    .month-body { background:#fbfcff; }
    .emp-body { background:#fff; }

    /* Claim rows (white) */
    .claim-row { display:flex; justify-content:space-between; align-items:center; gap:.75rem; flex-wrap:wrap; border:1px solid #e8edf5; border-radius:12px; padding:.85rem 1rem; background:#fff; transition:all .15s ease; }
    .claim-row:hover { border-color:#c7d7ec; background:#f8fafc; }
    .ev-title { font-weight:600; color:#1e293b; }
    .ev-sub { font-size:.8rem; color:#64748b; }
    .claim-row-meta { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; }
    .ev-amount { font-weight:700; color:#1d4ed8; white-space:nowrap; }

</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
// ── Filter + search (status pills, employee/event text, month, submitted date) ──
(function () {
    const pills = document.querySelectorAll('.claim-filter-btn');
    const search = document.getElementById('teamSearch');
    const monthEl = document.getElementById('teamMonth');
    const dateEl = document.getElementById('teamDate');
    const resetBtn = document.getElementById('teamReset');
    const noMatch = document.getElementById('teamNoMatch');
    if (!pills.length) return;
    let activeFilter = 'all';

    function setOpen(collapseEl, open) {
        if (!collapseEl) return;
        collapseEl.classList.toggle('show', open);
        const btn = document.querySelector('[data-bs-target="#' + collapseEl.id + '"]');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function restoreDefault() {
        document.querySelectorAll('.acc-group').forEach(function (g) {
            setOpen(g.querySelector(':scope > .collapse'), g.dataset.defaultOpen === '1');
        });
    }
    function apply() {
        const text = (search.value || '').toLowerCase().trim();
        const month = monthEl.value || '';
        const date = dateEl.value || '';
        const filtering = activeFilter !== 'all' || text || month || date;
        let anyVisible = false;

        document.querySelectorAll('.claim-card-wrap').forEach(function (row) {
            const okStatus = activeFilter === 'all' || row.dataset.statusGroup === activeFilter;
            const okText = !text || (row.dataset.employee || '').indexOf(text) !== -1 || (row.dataset.event || '').indexOf(text) !== -1;
            const okMonth = !month || row.dataset.month === month;
            const okDate = !date || row.dataset.date === date;
            const show = okStatus && okText && okMonth && okDate;
            row.style.display = show ? '' : 'none';
            if (show) anyVisible = true;
        });
        // Hide empty groups (employee → month → year); expand matches while filtering.
        document.querySelectorAll('.emp-grp').forEach(function (g) {
            const any = Array.from(g.querySelectorAll('.claim-card-wrap')).some(r => r.style.display !== 'none');
            g.style.display = any ? '' : 'none';
            if (filtering) setOpen(g.querySelector(':scope > .collapse'), any);
        });
        document.querySelectorAll('.month-grp').forEach(function (g) {
            const any = Array.from(g.querySelectorAll('.emp-grp')).some(r => r.style.display !== 'none');
            g.style.display = any ? '' : 'none';
            if (filtering) setOpen(g.querySelector(':scope > .collapse'), any);
        });
        document.querySelectorAll('.year-grp').forEach(function (g) {
            const any = Array.from(g.querySelectorAll('.month-grp')).some(r => r.style.display !== 'none');
            g.style.display = any ? '' : 'none';
            if (filtering) setOpen(g.querySelector(':scope > .collapse'), any);
        });
        if (!filtering) restoreDefault();
        if (noMatch) noMatch.classList.toggle('d-none', anyVisible);
    }

    pills.forEach(function (btn) {
        btn.addEventListener('click', function () {
            pills.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeFilter = btn.dataset.filter;
            apply();
        });
    });
    [search, monthEl, dateEl].forEach(el => el && el.addEventListener('input', apply));
    if (resetBtn) resetBtn.addEventListener('click', function () {
        search.value = ''; monthEl.value = ''; dateEl.value = '';
        pills.forEach(b => b.classList.toggle('active', b.dataset.filter === 'all'));
        activeFilter = 'all'; apply();
    });
})();
</script>
@endpush
@endsection
