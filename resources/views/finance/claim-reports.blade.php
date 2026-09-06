@extends('layouts.app')
@section('title', 'Claim Reports')
@section('page-title', 'Claim Reports')

@section('content')
@php
    $monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
    $rm = fn ($n) => 'RM '.number_format((float) $n, 2);
    $plural = fn ($n, $s) => $n.' '.$s.($n == 1 ? '' : 's');
@endphp

<style>
    /* ── Year → Month → Company → Employee accordion (matches the claim module) ── */
    .acc-group { border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; background:#fff; box-shadow:0 1px 4px rgba(15,23,42,.06); }
    .acc-head { width:100%; border:0; text-align:left; cursor:pointer; display:flex; justify-content:space-between; align-items:center; padding:.8rem 1.1rem; border-bottom:1px solid transparent; transition:filter .15s ease; }
    .acc-head-left, .acc-head-right { display:flex; align-items:center; gap:.75rem; }
    .acc-chip { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:1.05rem; }
    .acc-title { display:block; font-weight:700; font-size:1rem; line-height:1.2; }
    .acc-sub { font-size:.74rem; }
    .acc-total { font-weight:700; white-space:nowrap; }
    .acc-chev { transition:transform .2s ease; }
    .acc-head[aria-expanded="false"] .acc-chev { transform:rotate(-90deg); }
    .acc-hint { font-size:.72rem; font-style:italic; opacity:.85; white-space:nowrap; }
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
    .month-head .acc-hint::after { content:'Click to view companies'; }
    .month-head[aria-expanded="true"] .acc-hint::after { content:'Click to collapse'; }

    /* Company = teal */
    .company-head { background:linear-gradient(135deg,#ccfbf1,#e6fffb); }
    .company-head:hover { filter:brightness(.98); }
    .company-head[aria-expanded="true"] { border-bottom-color:#99f6e4; }
    .company-head .acc-title { color:#134e4a; } .company-head .acc-sub, .company-head .acc-chev, .company-head .acc-hint { color:#0f766e; } .company-head .acc-total { color:#0d9488; }
    .company-head .acc-chip { background:linear-gradient(135deg,#14b8a6,#0d9488); color:#fff; box-shadow:0 3px 7px rgba(13,148,136,.3); }
    .company-head .acc-hint::after { content:'Click to view staff'; }
    .company-head[aria-expanded="true"] .acc-hint::after { content:'Click to collapse'; }

    /* Employee = light / purple */
    .emp-head { background:#eef2f7; }
    .emp-head:hover { filter:brightness(.98); }
    .emp-head[aria-expanded="true"] { border-bottom-color:#dbe2ee; }
    .emp-head .acc-title { color:#1e293b; } .emp-head .acc-sub, .emp-head .acc-chev, .emp-head .acc-hint { color:#64748b; } .emp-head .acc-total { color:#1d4ed8; }
    .emp-head .acc-chip { background:linear-gradient(135deg,#8b5cf6,#6d28d9); color:#fff; box-shadow:0 3px 7px rgba(139,92,246,.3); }
    .emp-head .acc-hint::after { content:'Click to view claim lines'; }
    .emp-head[aria-expanded="true"] .acc-hint::after { content:'Click to collapse'; }

    /* Bodies + nesting */
    .acc-body { padding:.55rem; display:flex; flex-direction:column; gap:.55rem; }
    .year-body { background:#f1f5f9; }
    .month-body { background:#fbfcff; }
    .company-body { background:#f6fefc; }
    .emp-body { background:#fff; padding:.55rem .7rem; }
    .emp-body table { margin:0; font-size:13px; }
    .emp-body table th { background:#f1f5f9; font-weight:600; color:#475569; }
</style>

<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
        <h5 class="mb-0"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary"></i>Claim Reports</h5>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-primary-subtle text-primary-emphasis">Grand total: {{ $rm($grandTotal) }}</span>
            <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#userManualClaimReportsModal">
                <i class="bi bi-book me-1"></i>User Manual
            </button>
        </div>
    </div>
    <p class="text-muted small mb-2">
        <i class="bi bi-shield-check me-1"></i>Only claims <strong>approved by both the Manager/PIC and HR</strong> are shown here — ready for posting into the accounting system.
        <span class="d-none d-sm-inline">Click a row to expand it.</span>
    </p>
    <div class="alert {{ $basisIsCycle ? 'alert-success' : 'alert-warning' }} py-2 px-3 small mb-3">
        @if($basisIsCycle)
            <i class="bi bi-check2-circle me-1"></i><strong>Grouped by approval cycle</strong> — the 21st of the previous month to the 20th of this one, using each company's own cut-off day and the date each claim was fully approved by both Manager and HR.
            These are the same claims, in the same periods, as HR's <strong>Export approved PDFs (ZIP)</strong>, so the two downloads tally line for line. Match a CSV row to its PDF using the <strong>Claim Number</strong> column.
        @else
            <i class="bi bi-exclamation-triangle me-1"></i><strong>Grouped by expense month</strong> — the month the spending belongs to, whenever it was submitted or approved.
            This view <strong>does not tally with HR's approved-PDF ZIP</strong>: a claim for July expenses approved in August is reported here under July but archived in the August cycle. Switch the basis back to <em>Approval cycle</em> to reconcile against the ZIP.
        @endif
    </div>

    @if($unstampedClaims->isNotEmpty())
        <div class="alert alert-danger py-2 px-3 small mb-3">
            <i class="bi bi-exclamation-octagon me-1"></i>
            <strong>{{ $unstampedClaims->count() }} approved claim{{ $unstampedClaims->count() === 1 ? '' : 's' }} cannot be reported by cycle</strong>
            — {{ $unstampedClaims->count() === 1 ? 'it was' : 'they were' }} approved without being marked processed, so
            HR's approved-PDF ZIP cannot archive {{ $unstampedClaims->count() === 1 ? 'it' : 'them' }} either.
            {{ $unstampedClaims->count() === 1 ? 'It is' : 'They are' }} excluded from the totals below. Please report this to IT:
            <span class="d-block mt-1">
                @foreach($unstampedClaims as $stray)
                    <span class="badge text-bg-light border me-1">{{ $stray->claim_number ?: 'Claim #'.$stray->id }} — {{ $stray->employee->full_name ?? 'unknown' }}</span>
                @endforeach
            </span>
        </div>
    @endif

    {{-- ── Filters ── --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('finance.claim-reports') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Report by</label>
                    <select name="basis" class="form-select form-select-sm">
                        <option value="cycle" {{ $basisIsCycle ? 'selected' : '' }}>Approval cycle (21st – 20th) — matches ZIP</option>
                        <option value="expense_month" {{ $basisIsCycle ? '' : 'selected' }}>Expense month</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Year</label>
                    <select name="year" class="form-select form-select-sm">
                        @forelse($availableYears as $y)
                            <option value="{{ $y }}" {{ $selectedYear == $y ? 'selected' : '' }}>{{ $y }}</option>
                        @empty
                            <option value="{{ $selectedYear }}" selected>{{ $selectedYear }}</option>
                        @endforelse
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">{{ $basisIsCycle ? 'Cycle' : 'Month' }}</label>
                    <select name="month" class="form-select form-select-sm">
                        <option value="">{{ $basisIsCycle ? 'All cycles' : 'All months' }}</option>
                        @foreach($monthNames as $num => $name)
                            @php
                                // Built here rather than glued onto the label inline: a directive
                                // pushed up against a word compiles through as literal text.
                                $monthLabel = $basisIsCycle && isset($cycleMonthLabels[$num])
                                    ? $name.' ('.$cycleMonthLabels[$num].')'
                                    : $name;
                            @endphp
                            <option value="{{ $num }}" {{ (string) $filterMonth === (string) $num ? 'selected' : '' }}>{{ $monthLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Company</label>
                    <select name="company" class="form-select form-select-sm">
                        <option value="">All companies</option>
                        @foreach($companies as $co)
                            <option value="{{ $co }}" {{ $filterCompany === $co ? 'selected' : '' }}>{{ $co }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small fw-semibold mb-1">Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">All categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ (string) $filterCategory === (string) $cat->id ? 'selected' : '' }}>{{ $cat->gl_code ? $cat->gl_code.': ' : '' }}{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i>Filter</button>
                    <a href="{{ route('finance.claim-reports') }}" class="btn btn-outline-secondary btn-sm" title="Reset filters"><i class="bi bi-x-lg"></i></a>
                </div>
                <div class="col-12">
                    <a href="{{ route('finance.claim-reports.export', request()->query()) }}" class="btn btn-outline-success btn-sm mt-1">
                        <i class="bi bi-download me-1"></i>Export CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- ── Year > Month > Company > Employee ── --}}
    @if($rows->isEmpty())
        <div class="card"><div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox fs-3 d-block mb-2"></i>No approved claims match these filters.
        </div></div>
    @else
        @foreach($rows->pluck('year')->unique()->sortDesc()->values() as $yi => $year)
            @php $yearRows = $rows->where('year', $year); $yearOpen = $loop->first; @endphp
            <div class="acc-group mb-3">
                <button type="button" class="acc-head year-head" data-bs-toggle="collapse" data-bs-target="#cr-y{{ $yi }}" aria-expanded="{{ $yearOpen ? 'true' : 'false' }}">
                    <span class="acc-head-left">
                        <span class="acc-chip"><i class="bi bi-calendar3"></i></span>
                        <span><span class="acc-title">{{ $year }}</span><span class="acc-sub d-block">{{ $plural($yearRows->count(), 'line') }}</span><span class="acc-hint"></span></span>
                    </span>
                    <span class="acc-head-right"><span class="acc-total">{{ $rm($yearRows->sum('amount')) }}</span><i class="bi bi-chevron-down acc-chev"></i></span>
                </button>
                <div class="collapse {{ $yearOpen ? 'show' : '' }}" id="cr-y{{ $yi }}">
                    <div class="acc-body year-body">

                        @foreach($yearRows->pluck('month')->unique()->sortDesc()->values() as $mi => $month)
                            @php $monthRows = $yearRows->where('month', $month); $monthOpen = $yearOpen && $loop->first; @endphp
                            <div class="acc-group">
                                <button type="button" class="acc-head month-head" data-bs-toggle="collapse" data-bs-target="#cr-y{{ $yi }}-m{{ $mi }}" aria-expanded="{{ $monthOpen ? 'true' : 'false' }}">
                                    <span class="acc-head-left">
                                        <span class="acc-chip"><i class="bi bi-calendar3"></i></span>
                                        @php
                                            $cycleNote = $basisIsCycle && isset($cycleMonthLabels[$month])
                                                ? $cycleMonthLabels[$month].' cycle · '
                                                : '';
                                        @endphp
                                        <span><span class="acc-title">{{ $monthNames[$month] ?? $month }} {{ $year }}</span><span class="acc-sub d-block">{{ $cycleNote }}{{ $plural($monthRows->pluck('company')->unique()->count(), 'company') }} · {{ $plural($monthRows->count(), 'line') }}</span><span class="acc-hint"></span></span>
                                    </span>
                                    <span class="acc-head-right"><span class="acc-total">{{ $rm($monthRows->sum('amount')) }}</span><i class="bi bi-chevron-down acc-chev"></i></span>
                                </button>
                                <div class="collapse {{ $monthOpen ? 'show' : '' }}" id="cr-y{{ $yi }}-m{{ $mi }}">
                                    <div class="acc-body month-body">

                                        @foreach($monthRows->pluck('company')->unique()->sort()->values() as $ci => $company)
                                            @php $companyRows = $monthRows->where('company', $company); @endphp
                                            <div class="acc-group">
                                                <button type="button" class="acc-head company-head" data-bs-toggle="collapse" data-bs-target="#cr-y{{ $yi }}-m{{ $mi }}-c{{ $ci }}" aria-expanded="false">
                                                    <span class="acc-head-left">
                                                        <span class="acc-chip"><i class="bi bi-building"></i></span>
                                                        <span><span class="acc-title">{{ $company }}</span><span class="acc-sub d-block">{{ $plural($companyRows->pluck('employee')->unique()->count(), 'staff') }} · {{ $plural($companyRows->count(), 'line') }}</span><span class="acc-hint"></span></span>
                                                    </span>
                                                    <span class="acc-head-right"><span class="acc-total">{{ $rm($companyRows->sum('amount')) }}</span><i class="bi bi-chevron-down acc-chev"></i></span>
                                                </button>
                                                <div class="collapse" id="cr-y{{ $yi }}-m{{ $mi }}-c{{ $ci }}">
                                                    <div class="acc-body company-body">

                                                        @foreach($companyRows->pluck('employee')->unique()->sort()->values() as $ei => $employee)
                                                            @php $empRows = $companyRows->where('employee', $employee); @endphp
                                                            <div class="acc-group">
                                                                <button type="button" class="acc-head emp-head" data-bs-toggle="collapse" data-bs-target="#cr-y{{ $yi }}-m{{ $mi }}-c{{ $ci }}-e{{ $ei }}" aria-expanded="false">
                                                                    <span class="acc-head-left">
                                                                        <span class="acc-chip"><i class="bi bi-person-fill"></i></span>
                                                                        <span><span class="acc-title">{{ $employee }}</span><span class="acc-sub d-block">{{ $plural($empRows->count(), 'claim line') }}</span><span class="acc-hint"></span></span>
                                                                    </span>
                                                                    <span class="acc-head-right"><span class="acc-total">{{ $rm($empRows->sum('amount')) }}</span><i class="bi bi-chevron-down acc-chev"></i></span>
                                                                </button>
                                                                <div class="collapse" id="cr-y{{ $yi }}-m{{ $mi }}-c{{ $ci }}-e{{ $ei }}">
                                                                    <div class="acc-body emp-body">
                                                                        <div class="table-responsive">
                                                                            <table class="table table-sm table-bordered align-middle mb-0">
                                                                                <thead>
                                                                                    <tr>
                                                                                        <th style="width:130px;">GL Code</th>
                                                                                        <th style="width:210px;">Category</th>
                                                                                        <th>Description</th>
                                                                                        <th class="text-end" style="width:130px;">Amount</th>
                                                                                    </tr>
                                                                                </thead>
                                                                                <tbody>
                                                                                    @foreach($empRows as $r)
                                                                                    <tr>
                                                                                        <td class="text-nowrap">{{ $r['gl_code'] }}</td>
                                                                                        <td>{{ $r['category'] }}</td>
                                                                                        <td>{{ $r['description'] }}</td>
                                                                                        <td class="text-end fw-semibold">{{ $rm($r['amount']) }}</td>
                                                                                    </tr>
                                                                                    @endforeach
                                                                                    <tr class="table-light">
                                                                                        <td colspan="3" class="text-end fw-semibold">Subtotal — {{ $employee }}</td>
                                                                                        <td class="text-end fw-bold">{{ $rm($empRows->sum('amount')) }}</td>
                                                                                    </tr>
                                                                                </tbody>
                                                                            </table>
                                                                        </div>
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
    @endif

</div>

@include('partials._user-manual-claimreports')
@endsection
