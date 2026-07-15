@extends('layouts.app')
@section('title', 'User – Company Setting')
@section('page-title', 'User – Company Setting')

@section('content')
<div class="container-fluid" style="max-width:1100px;">

    <div class="d-flex align-items-center gap-3 mb-3">
        <div class="ucs-hero-icon"><i class="bi bi-building-gear"></i></div>
        <div>
            <h5 class="mb-0 fw-bold">User – Company Setting</h5>
            <div class="text-muted small">Bulk-move employees between companies. Each change is recorded on the employee's company timeline (Employee listing &amp; profile). User records are maintained according to company timeline.</div>
        </div>
    </div>

    {{-- success / error flash is rendered globally by the layout. When the effective
         date is on/before someone's current-company start, the bulk-assign controller
         flashes `rewrite_confirm` and the detailed confirmation modal (bottom) opens. --}}

    {{-- Step hint --}}
    <div class="ucs-step small text-muted mb-2">
        <span class="ucs-step-num">1</span> Select the employees to move
        <i class="bi bi-arrow-right mx-2 opacity-50"></i>
        <span class="ucs-step-num">2</span> Pick the target company &amp; effective date in the bar below
    </div>

    <form method="POST" action="{{ route('superadmin.user-company-settings.bulk-assign') }}" id="ucsForm">
        @csrf

        {{-- ── Company-first employee listing (collapsible) ── --}}
        @forelse($grouped as $companyName => $emps)
            <div class="ucs-group mb-2">
                <div class="ucs-head">
                    <button type="button" class="ucs-toggle"
                            data-bs-toggle="collapse" data-bs-target="#grpwrap-{{ $loop->index }}" aria-expanded="false" aria-controls="grpwrap-{{ $loop->index }}">
                        <i class="bi bi-chevron-down acc-chev"></i>
                        <span class="acc-chip"><i class="bi bi-building"></i></span>
                        <span class="acc-titles">
                            <span class="acc-title">{{ $companyName }}</span>
                            <span class="acc-sub">{{ $emps->count() }} {{ \Illuminate\Support\Str::plural('employee', $emps->count()) }}</span>
                        </span>
                        <span class="acc-count ms-auto">{{ $emps->count() }}</span>
                        <span class="ucs-grp-sel d-none" data-group-badge="{{ $loop->index }}">0 selected</span>
                    </button>
                    <label class="ucs-selectall">
                        <input class="form-check-input ucs-select-all m-0" type="checkbox" id="sa-{{ $loop->index }}" data-group="grp-{{ $loop->index }}">
                        <span>Select all</span>
                    </label>
                </div>
                <div class="collapse" id="grpwrap-{{ $loop->index }}">
                    <div class="ucs-body" id="grp-{{ $loop->index }}">
                        <div class="row g-1">
                            @foreach($emps as $e)
                                @php $since = optional($e->companyHistories->first())->started_on ?? $e->start_date; @endphp
                                <div class="col-md-6">
                                    <label class="d-flex align-items-center gap-2 p-2 rounded ucs-row">
                                        <input class="form-check-input ucs-emp m-0" type="checkbox" name="employee_ids[]" value="{{ $e->id }}"
                                               @checked(in_array((string) $e->id, array_map('strval', (array) old('employee_ids', []))))
                                               data-company="{{ $companyName }}" data-group-index="{{ $loop->parent->index }}"
                                               data-current-start="{{ optional($since)->toDateString() }}">
                                        <span class="ucs-avatar">{{ strtoupper(mb_substr($e->preferred_name ?: $e->full_name, 0, 1)) }}</span>
                                        <span class="small lh-sm">
                                            <span class="fw-semibold">{{ $e->full_name }}</span>
                                            @if($e->department)<span class="text-muted">· {{ $e->department }}</span>@endif
                                            <span class="text-muted d-block" style="font-size:.72rem;">Here since {{ fmt_date($since) }}</span>
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="alert alert-info">No active employees found.</div>
        @endforelse

        {{-- ── Sticky action bar (appears once employees are selected) ── --}}
        <div class="ucs-bar shadow-lg" id="ucsBar">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="ucs-bar-from">
                    <div class="small text-muted mb-1"><span class="badge rounded-pill bg-primary" id="ucsCount">0</span> selected &mdash; moving from</div>
                    <div id="ucsFromChips" class="d-flex gap-1 flex-wrap"></div>
                </div>
                <i class="bi bi-arrow-right-circle-fill fs-4 text-primary d-none d-lg-inline ucs-bar-arrow"></i>
                <div class="d-flex align-items-end gap-2 ms-auto flex-wrap">
                    <div>
                        <label class="form-label small fw-semibold mb-1">Move to company</label>
                        <select name="company" id="ucsCompany" class="form-select form-select-sm" style="min-width:230px;" required>
                            <option value="">Select company…</option>
                            @foreach($companies as $co)
                                <option value="{{ $co->name }}" @selected(old('company') === $co->name)>{{ $co->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="form-label small fw-semibold mb-1">Effective from</label>
                        <input type="date" name="effective_date" id="ucsDate" class="form-control form-control-sm" max="{{ now()->toDateString() }}" value="{{ old('effective_date') }}" required>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm px-3" id="ucsApply" disabled>
                        <i class="bi bi-arrow-left-right me-1"></i>Apply
                    </button>
                </div>
            </div>
            <div class="form-text small mt-1"><i class="bi bi-info-circle me-1"></i>Office Location follows the selected company. If the effective date is on/before someone's current-company start, you'll be asked to confirm — it rewrites (removes) that part of their timeline.</div>
        </div>
    </form>
</div>

{{-- Confirm modal --}}
<div class="modal fade" id="ucsConfirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-semibold"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Confirm company change</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Move <strong id="ucsCfCount">0</strong> employee(s), effective <strong id="ucsCfDate">—</strong>:</p>
                <div class="d-flex align-items-center gap-2 flex-wrap p-2 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;">
                    <div class="small">
                        <div class="text-muted mb-1">From</div>
                        <ul class="mb-0 ps-3" id="ucsCfFrom"></ul>
                    </div>
                    <i class="bi bi-arrow-right fs-5 text-primary mx-2"></i>
                    <div class="small">
                        <div class="text-muted mb-1">To</div>
                        <div class="fw-semibold" id="ucsCfTo">—</div>
                    </div>
                </div>
                <p class="text-muted small mt-2 mb-0">Anyone whose current company started after that date will be skipped and listed afterwards.</p>
            </div>
            <div class="modal-footer border-0 pt-1">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="ucsConfirmBtn"><i class="bi bi-check-lg me-1"></i>Confirm change</button>
            </div>
        </div>
    </div>
</div>

{{-- Rewrite confirmation — shown by the server when the effective date is on/before
     someone's current-company start (the "undo an accidental move" case). Lists the
     exact timeline entries that will be removed, per employee. --}}
@if(session('rewrite_confirm'))
@php $rc = session('rewrite_confirm'); @endphp
<div class="modal fade" id="ucsRewriteConfirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-semibold"><i class="bi bi-exclamation-triangle me-2 text-warning"></i>This will remove timeline history</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">The effective date (<strong>{{ $rc['effective'] }}</strong>) is on or before the current-company start for
                    <strong>{{ count($rc['employees']) }}</strong> of the {{ $rc['total'] }} selected employee(s). Proceeding will
                    <strong class="text-danger">remove</strong> the timeline entries below and set them to <strong>{{ $rc['company'] }}</strong> from that date:</p>
                <ul class="list-unstyled mb-2">
                    @foreach($rc['employees'] as $row)
                        <li class="mb-2 p-2 rounded" style="background:#fff7ed;border:1px solid #fed7aa;">
                            <div class="fw-semibold small">{{ $row['name'] }}</div>
                            <ul class="mb-0 small text-muted ps-3">
                                @foreach($row['removes'] as $r)
                                    <li><i class="bi bi-dash-circle me-1 text-danger"></i>Remove: {{ $r }}</li>
                                @endforeach
                            </ul>
                        </li>
                    @endforeach
                </ul>
                <p class="text-muted small mb-0">Their claims, leave &amp; tickets in that period are re-attributed to {{ $rc['company'] }}. Any other selected employees move normally.</p>
            </div>
            <div class="modal-footer border-0 pt-1">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('superadmin.user-company-settings.bulk-assign') }}" class="d-inline">
                    @csrf
                    @foreach((array) old('employee_ids', []) as $eid)
                        <input type="hidden" name="employee_ids[]" value="{{ $eid }}">
                    @endforeach
                    <input type="hidden" name="company" value="{{ old('company') }}">
                    <input type="hidden" name="effective_date" value="{{ old('effective_date') }}">
                    <input type="hidden" name="confirmed" value="1">
                    <button type="submit" class="btn btn-warning"><i class="bi bi-check-lg me-1"></i>Proceed &amp; rewrite</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endif
@endsection

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    .ucs-hero-icon { width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,#3b82f6,#2563eb); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; box-shadow:0 4px 10px rgba(37,99,235,.3); }
    .ucs-step-num { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; background:#2563eb; color:#fff; font-size:.7rem; font-weight:700; }
    /* Company accordion — modern chip-header look (matches the Claims / Ticket modules). */
    .ucs-group { border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; background:#fff; box-shadow:0 1px 4px rgba(15,23,42,.06); transition:box-shadow .15s ease; }
    .ucs-group:hover { box-shadow:0 4px 14px rgba(15,23,42,.09); }
    .ucs-head { display:flex; align-items:center; gap:.5rem; padding-right:1rem; background:linear-gradient(135deg,#eef2ff,#f8faff); }
    .ucs-toggle { flex:1; display:flex; align-items:center; gap:.75rem; border:0; background:transparent; text-align:left; padding:.7rem 1rem; cursor:pointer; min-width:0; }
    .ucs-toggle:hover .acc-title { text-decoration:underline; }
    .acc-chip { width:38px; height:38px; border-radius:11px; background:linear-gradient(135deg,#3b82f6,#1d4ed8); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:1.05rem; box-shadow:0 3px 7px rgba(37,99,235,.3); flex-shrink:0; }
    .acc-titles { display:flex; flex-direction:column; min-width:0; }
    .acc-title { font-weight:700; font-size:.98rem; color:#1e293b; line-height:1.15; }
    .acc-sub { font-size:.72rem; color:#64748b; }
    .acc-count { background:#fff; border:1px solid #cbd5e1; color:#334155; border-radius:999px; padding:.05rem .6rem; font-size:.72rem; font-weight:700; flex-shrink:0; }
    .ucs-grp-sel { background:#2563eb; color:#fff; border-radius:999px; padding:.05rem .6rem; font-size:.68rem; font-weight:700; flex-shrink:0; }
    .acc-chev { color:#64748b; transition:transform .2s ease; flex-shrink:0; }
    .ucs-toggle[aria-expanded="false"] .acc-chev { transform:rotate(-90deg); }
    .ucs-selectall { display:flex; align-items:center; gap:.35rem; font-size:.78rem; color:#475569; margin:0; cursor:pointer; white-space:nowrap; flex-shrink:0; }
    .ucs-body { padding:.5rem .75rem .75rem; border-top:1px solid #eef2f7; }
    .ucs-row { cursor:pointer; transition:background .12s ease; }
    .ucs-row:hover { background:#f1f5f9; }
    .ucs-row.selected { background:#eef2ff; }
    .ucs-avatar { width:26px; height:26px; border-radius:50%; background:#e2e8f0; color:#475569; font-weight:700; font-size:.72rem; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
    .ucs-row.selected .ucs-avatar { background:#c7d2fe; color:#3730a3; }
    .ucs-from-chip { background:#eef2ff; color:#3730a3; border-radius:999px; padding:.12rem .6rem; font-size:.72rem; font-weight:600; white-space:nowrap; }

    /* Sticky action bar — sits within the content column, appears on selection. */
    .ucs-bar {
        position: sticky; bottom: 12px; z-index: 20;
        background:#fff; border:1px solid #e2e8f0; border-radius:14px;
        padding: .85rem 1.1rem; margin-top: 1rem;
        display: none;
    }
    .ucs-bar.show { display: block; animation: ucsSlide .2s ease; }
    @keyframes ucsSlide { from { transform: translateY(8px); opacity:.4; } to { transform: translateY(0); opacity:1; } }
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    const form = document.getElementById('ucsForm');
    const bar = document.getElementById('ucsBar');
    const applyBtn = document.getElementById('ucsApply');
    const countEl = document.getElementById('ucsCount');
    const fromChips = document.getElementById('ucsFromChips');
    const companySel = document.getElementById('ucsCompany');
    const dateEl = document.getElementById('ucsDate');

    const emps = () => Array.from(document.querySelectorAll('.ucs-emp'));
    const selected = () => emps().filter(c => c.checked);

    function fromCounts() {
        const map = new Map();
        selected().forEach(c => {
            const co = c.dataset.company || '— No company —';
            map.set(co, (map.get(co) || 0) + 1);
        });
        return map;
    }

    function renderChips(target, kind) {
        target.textContent = '';
        fromCounts().forEach((ct, co) => {
            if (kind === 'chip') {
                const s = document.createElement('span');
                s.className = 'ucs-from-chip';
                s.textContent = co + ' ×' + ct;
                target.appendChild(s);
            } else {
                const li = document.createElement('li');
                li.textContent = co;
                const b = document.createElement('span');
                b.className = 'text-muted';
                b.textContent = ' ×' + ct;
                li.appendChild(b);
                target.appendChild(li);
            }
        });
    }

    function updateGroupBadges() {
        document.querySelectorAll('[data-group-badge]').forEach(badge => {
            const idx = badge.getAttribute('data-group-badge');
            const n = emps().filter(c => c.dataset.groupIndex === idx && c.checked).length;
            badge.textContent = n + ' selected';
            badge.classList.toggle('d-none', n === 0);
        });
    }

    function refresh() {
        const n = selected().length;
        countEl.textContent = n;
        renderChips(fromChips, 'chip');
        updateGroupBadges();
        applyBtn.disabled = !(n > 0 && companySel.value && dateEl.value);
        bar.classList.toggle('show', n > 0);
    }

    emps().forEach(c => c.addEventListener('change', function () {
        const row = c.closest('.ucs-row');
        if (row) row.classList.toggle('selected', c.checked);
        refresh();
    }));
    companySel.addEventListener('change', refresh);
    dateEl.addEventListener('change', refresh);

    document.querySelectorAll('.ucs-select-all').forEach(sa => sa.addEventListener('change', function () {
        const group = document.getElementById(sa.dataset.group);
        if (!group) return;
        group.querySelectorAll('.ucs-emp').forEach(c => {
            c.checked = sa.checked;
            const row = c.closest('.ucs-row');
            if (row) row.classList.toggle('selected', c.checked);
        });
        refresh();
    }));

    applyBtn.addEventListener('click', function () {
        if (applyBtn.disabled) return;
        // If any selected employee's CURRENT company started on/after the effective
        // date, this is a timeline rewrite — skip the generic confirm and submit
        // straight to the server, which returns the detailed "these entries will be
        // removed" warning. Avoids a redundant double confirmation.
        const eff = dateEl.value;
        const isRewrite = selected().some(function (c) {
            const cs = c.dataset.currentStart;
            return cs && cs >= eff; // ISO date strings compare lexicographically
        });
        if (isRewrite) {
            form.submit();
            return;
        }
        document.getElementById('ucsCfCount').textContent = selected().length;
        document.getElementById('ucsCfTo').textContent = companySel.value;
        document.getElementById('ucsCfDate').textContent = dateEl.value;
        renderChips(document.getElementById('ucsCfFrom'), 'list');
        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById('ucsConfirm')).show();
    });
    document.getElementById('ucsConfirmBtn').addEventListener('click', function () { form.submit(); });

    refresh();
})();
</script>
@if(session('rewrite_confirm'))
<script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        var el = document.getElementById('ucsRewriteConfirm');
        if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
    })();
</script>
@endif
@endpush
