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
                    <li>Submit with your reporting manager's acknowledgement by the <strong>{{ ordinal($policy->submission_deadline_day ?? 22) }}</strong> of the month.</li>
                    <li>Attach <strong>supporting receipts/proof</strong> (you can save a draft now and attach later).</li>
                    <li>Mileage: state the route (From → To); Toll/Parking are separate lines.</li>
                </ol>
            </div>
        </div>
    </div>

    @php
        $sections = [
            ['key' => 'drafts', 'rows' => $drafts, 'title' => 'Drafts & to fix', 'icon' => 'bi-pencil-square', 'empty' => 'No drafts — start a new claim above.'],
            ['key' => 'active', 'rows' => $active, 'title' => 'Awaiting approval', 'icon' => 'bi-hourglass-split', 'empty' => 'Nothing awaiting approval.'],
            ['key' => 'done', 'rows' => $done, 'title' => 'Completed', 'icon' => 'bi-check-circle', 'empty' => 'No completed claims yet.'],
        ];
    @endphp

    @foreach($sections as $s)
    @if($s['rows']->isNotEmpty())
    <h6 class="text-muted mt-4 mb-2"><i class="bi {{ $s['icon'] }} me-1"></i>{{ $s['title'] }} <span class="badge bg-secondary">{{ $s['rows']->count() }}</span></h6>
    <div class="row g-2">
        @foreach($s['rows'] as $claim)
        <div class="col-12">
            <a href="{{ route('user.claims.show', $claim) }}" class="text-decoration-none">
                <div class="card border-0 shadow-sm claim-row">
                    <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-3">
                            <i class="bi bi-calendar3 text-primary"></i>
                            <div>
                                <div class="fw-semibold text-dark">{{ $claim->event ?: 'Untitled claim' }}</div>
                                <div class="small text-muted">
                                    {{ $claim->claim_number }} &middot;
                                    {{ \Carbon\Carbon::create($claim->year, $claim->month)->format('M Y') }} &middot;
                                    {{ $claim->item_count }} item{{ $claim->item_count == 1 ? '' : 's' }}
                                    @if($claim->status === 'manager_rejected' && $claim->manager_remarks)
                                    &middot; <span class="text-danger">manager: {{ \Illuminate\Support\Str::limit($claim->manager_remarks, 50) }}</span>
                                    @elseif($claim->status === 'hr_rejected' && $claim->hr_remarks)
                                    &middot; <span class="text-danger">HR: {{ \Illuminate\Support\Str::limit($claim->hr_remarks, 50) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-3">
                            <span class="badge bg-{{ $claim->statusBadge()['class'] }}">{{ $claim->statusBadge()['label'] }}</span>
                            <span class="fw-bold text-primary">RM {{ number_format($claim->total_with_gst, 2) }}</span>
                            <i class="bi bi-chevron-right text-muted"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        @endforeach
    </div>
    @endif
    @endforeach

    @if($claims->isEmpty())
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
                    <input type="text" name="event" class="form-control @error('event') is-invalid @enderror" placeholder="e.g., Parentcraft Shooting, Coffee With Mom, Petty Cash - MCA" maxlength="255" required autofocus>
                    @error('event')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">One claim per event/project. You'll add the expense items next.</div>
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
    .claim-row { transition: box-shadow .15s, transform .15s; }
    .claim-row:hover { box-shadow: 0 .25rem .75rem rgba(0,0,0,.1) !important; transform: translateY(-1px); }
</style>
@endpush
@endsection

@php
function ordinal($n) {
    $s = ['th','st','nd','rd'];
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
}
@endphp
