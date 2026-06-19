@extends('layouts.app')
@section('title', 'My Claims')

@section('content')
@include('partials.dashboard-widgets-style')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <div>
            <h3 class="mb-1"><i class="bi bi-receipt-cutoff me-2"></i>My Expense Claims</h3>
            <p class="text-muted mb-0">{{ $employee->full_name }} &mdash; {{ $employee->department ?? 'N/A' }}</p>
        </div>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newClaimModal">
            <i class="bi bi-plus-lg me-1"></i>New Claim
        </button>
    </div>

    {{-- ── Summary Stat Cards (year) ── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-receipt"></i></div>
                        <div>
                            <div class="widget-number">{{ $claims->count() }}</div>
                            <div class="widget-label">Total Claims</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-pencil-square"></i></div>
                        <div>
                            <div class="widget-number">{{ $drafts->count() }}</div>
                            <div class="widget-label">Drafts to finish</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number" style="font-size:22px;">RM {{ number_format($pendingYtd, 2) }}</div>
                            <div class="widget-label">Pending in {{ $year }}</div>
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
                            <div class="widget-number" style="font-size:22px;">RM {{ number_format($approvedYtd, 2) }}</div>
                            <div class="widget-label">Approved in {{ $year }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Important Reminders ── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-info bg-opacity-10 border-0 d-flex align-items-center">
            <i class="bi bi-info-circle text-info me-2"></i><strong>Important Reminders</strong>
            <button class="btn btn-sm btn-link ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#rulesCollapse"><i class="bi bi-chevron-down"></i></button>
        </div>
        <div class="collapse" id="rulesCollapse">
            <div class="card-body small text-muted" style="line-height:1.8;">
                <ol class="mb-0">
                    <li>All claims are for <strong>business purposes only</strong>.</li>
                    <li>File a <strong>separate claim per event / project</strong>; state the project/client name (except Sales).</li>
                    <li>Submit with your reporting manager's acknowledgement by the <strong>{{ ordinal($policy->submission_deadline_day ?? 20) }}</strong> of the month.</li>
                    <li>Attach <strong>supporting receipts/proof</strong> (you can save a draft now and attach later).</li>
                    <li>Mileage: state the route (From → To); Toll/Parking are separate lines.</li>
                </ol>
            </div>
        </div>
    </div>

    @php
        $statusGroup = fn ($s) => match ($s) {
            'draft' => 'draft',
            'submitted', 'manager_approved' => 'pending',
            'hr_approved', 'paid' => 'approved',
            default => 'rejected', // manager_rejected / hr_rejected / cancelled
        };
    @endphp

    @if($claims->isNotEmpty())
    {{-- Status filter pills (filter the month groups below) --}}
    <div class="claim-filters d-flex flex-wrap gap-2 mb-4">
        <button type="button" class="claim-filter-btn active" data-filter="all">All <span class="cf-count">{{ $claims->count() }}</span></button>
        <button type="button" class="claim-filter-btn" data-filter="draft">Drafts <span class="cf-count">{{ $claims->filter(fn ($c) => $statusGroup($c->status) === 'draft')->count() }}</span></button>
        <button type="button" class="claim-filter-btn" data-filter="pending">Pending <span class="cf-count">{{ $claims->filter(fn ($c) => $statusGroup($c->status) === 'pending')->count() }}</span></button>
        <button type="button" class="claim-filter-btn" data-filter="approved">Approved <span class="cf-count">{{ $claims->filter(fn ($c) => $statusGroup($c->status) === 'approved')->count() }}</span></button>
        <button type="button" class="claim-filter-btn" data-filter="rejected">Rejected <span class="cf-count">{{ $claims->filter(fn ($c) => $statusGroup($c->status) === 'rejected')->count() }}</span></button>
    </div>

    {{-- Month / Year accordion --}}
    @php $prevYear = null; @endphp
    @foreach($byMonth as $key => $monthClaims)
    @php [$gy, $gm] = explode('-', $key); @endphp
    @if($prevYear !== $gy)
    <div class="year-label">{{ $gy }}</div>
    @php $prevYear = $gy; @endphp
    @endif
    <div class="month-group mb-3">
        <button type="button" class="month-head" data-bs-toggle="collapse" data-bs-target="#m-{{ $key }}" aria-expanded="{{ $loop->first ? 'true' : 'false' }}">
            <span class="month-head-left">
                <span class="month-chip"><i class="bi bi-calendar3"></i></span>
                <span>
                    <span class="month-title">{{ \Carbon\Carbon::createFromDate($gy, $gm, 1)->format('F Y') }}</span>
                    <span class="month-sub">{{ $monthClaims->count() }} claim{{ $monthClaims->count() == 1 ? '' : 's' }}</span>
                </span>
            </span>
            <span class="month-head-right">
                <span class="month-total">RM {{ number_format($monthClaims->sum('total_with_gst'), 2) }}</span>
                <i class="bi bi-chevron-down month-chevron"></i>
            </span>
        </button>
        <div class="collapse {{ $loop->first ? 'show' : '' }}" id="m-{{ $key }}">
            <div class="month-body">
                @foreach($monthClaims as $claim)
                <a href="{{ route('user.claims.show', $claim) }}" class="claim-row" data-status-group="{{ $statusGroup($claim->status) }}">
                    <div class="claim-row-main">
                        <div class="ev-title">{{ $claim->event ?: 'Untitled claim' }}</div>
                        <div class="ev-sub">{{ $claim->claim_number }} &middot; {{ $claim->item_count }} item{{ $claim->item_count == 1 ? '' : 's' }}
                            @if($claim->status === 'manager_rejected' && $claim->manager_remarks) &middot; <span class="text-danger">manager: {{ \Illuminate\Support\Str::limit($claim->manager_remarks, 40) }}</span>
                            @elseif($claim->status === 'hr_rejected' && $claim->hr_remarks) &middot; <span class="text-danger">HR: {{ \Illuminate\Support\Str::limit($claim->hr_remarks, 40) }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="claim-row-meta">
                        <span class="badge bg-{{ $claim->statusBadge()['class'] }}">{{ $claim->statusBadge()['label'] }}</span>
                        <span class="ev-amount">RM {{ number_format($claim->total_with_gst, 2) }}</span>
                        <i class="bi bi-chevron-right text-muted"></i>
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </div>
    @endforeach
    @else
    <div class="text-center text-muted py-5">
        <i class="bi bi-inbox" style="font-size:2.5rem;"></i>
        <p class="mt-2 mb-0">No claims yet. Click <strong>New Claim</strong> to file your first event.</p>
    </div>
    @endif
</div>

{{-- New Claim modal --}}
<div class="modal fade" id="newClaimModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form action="{{ route('user.claims.create') }}" method="POST" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>New Claim</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Event / purpose <span class="text-danger">*</span></label>
                    <input type="text" name="event" class="form-control @error('event') is-invalid @enderror" placeholder="e.g., Parentcraft Shooting, Coffee With Mom, Petty Cash - MCA" maxlength="255" list="eventList" autocomplete="off" required autofocus>
                    <datalist id="eventList">
                        @foreach($eventSuggestions as $ev)
                        <option value="{{ $ev }}"></option>
                        @endforeach
                    </datalist>
                    @error('event')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">One claim per event/project — pick an existing event to keep naming consistent. You'll add the items next.</div>
                </div>
                <div class="mb-1">
                    <label class="form-label">Claim month <span class="text-muted">(for reporting)</span></label>
                    <input type="month" name="period" class="form-control" value="{{ now()->format('Y-m') }}" max="{{ now()->format('Y-m') }}">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="bi bi-arrow-right me-1"></i>Create &amp; add items</button>
            </div>
        </form>
    </div>
</div>

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    /* ── Filter pills ── */
    .claim-filter-btn {
        display: inline-flex; align-items: center; gap: .45rem;
        border: 1px solid #e2e8f0; background: #fff; color: #475569;
        border-radius: 999px; font-weight: 500; font-size: .82rem;
        padding: .4rem 1rem; transition: all .15s ease;
    }
    .claim-filter-btn:hover { background: #f1f5f9; color: #1e293b; }
    .claim-filter-btn .cf-count {
        background: #eef2f7; color: #475569; border-radius: 999px;
        font-size: .7rem; font-weight: 600; padding: .05rem .5rem; min-width: 1.5em; text-align: center;
    }
    .claim-filter-btn.active {
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; border-color: transparent;
        box-shadow: 0 3px 8px rgba(37,99,235,.3);
    }
    .claim-filter-btn.active .cf-count { background: rgba(255,255,255,.25); color: #fff; }

    /* ── Year label ── */
    .year-label { font-size: .8rem; font-weight: 700; letter-spacing: .08em; color: #94a3b8; text-transform: uppercase; margin: 1.25rem 0 .6rem; }

    /* ── Month group ── */
    .month-group { border: 1px solid #e9eef5; border-radius: 14px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
    .month-head {
        width: 100%; border: 0; text-align: left; cursor: pointer;
        display: flex; justify-content: space-between; align-items: center;
        padding: .8rem 1.1rem;
        background: linear-gradient(135deg, #eef2ff, #f8fafc);
        border-bottom: 1px solid transparent;
    }
    .month-head[aria-expanded="true"] { border-bottom-color: #eef2f7; }
    .month-head-left { display: flex; align-items: center; gap: .8rem; }
    .month-chip {
        width: 40px; height: 40px; border-radius: 11px; flex-shrink: 0;
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem;
        box-shadow: 0 3px 7px rgba(37,99,235,.3);
    }
    .month-title { display: block; font-weight: 700; color: #1e293b; font-size: 1rem; line-height: 1.2; }
    .month-sub { font-size: .75rem; color: #64748b; }
    .month-head-right { display: flex; align-items: center; gap: .85rem; }
    .month-total { font-weight: 700; color: #1d4ed8; }
    .month-chevron { color: #94a3b8; transition: transform .2s ease; }
    .month-head[aria-expanded="false"] .month-chevron { transform: rotate(-90deg); }

    /* ── Claim rows ── */
    .month-body { padding: .45rem; display: flex; flex-direction: column; gap: .4rem; }
    .claim-row {
        display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap;
        text-decoration: none; border: 1px solid #f1f5f9; border-radius: 10px;
        padding: .7rem .9rem; background: #fff; transition: all .15s ease;
    }
    .claim-row:hover { border-color: #c7d7ec; background: #f8fafc; transform: translateX(3px); }
    .claim-row .ev-title { font-weight: 600; color: #1e293b; }
    .claim-row .ev-sub { font-size: .78rem; color: #64748b; }
    .claim-row-meta { display: flex; align-items: center; gap: .85rem; }
    .claim-row-meta .ev-amount { font-weight: 700; color: #1d4ed8; }
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    const pills = document.querySelectorAll('.claim-filter-btn');
    if (!pills.length) return;
    function apply(filter) {
        document.querySelectorAll('.claim-row').forEach(function (row) {
            row.style.display = (filter === 'all' || row.dataset.statusGroup === filter) ? '' : 'none';
        });
        document.querySelectorAll('.month-group').forEach(function (group) {
            const anyVisible = Array.from(group.querySelectorAll('.claim-row')).some(r => r.style.display !== 'none');
            group.style.display = anyVisible ? '' : 'none';
        });
    }
    pills.forEach(function (btn) {
        btn.addEventListener('click', function () {
            pills.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            apply(btn.dataset.filter);
        });
    });
})();
</script>
@endpush
@endsection

@php
function ordinal($n) {
    $s = ['th','st','nd','rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}
@endphp
