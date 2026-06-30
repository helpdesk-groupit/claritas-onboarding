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
    </div>

    {{-- ── Pipeline-stage cards (Draft → Awaiting Manager → Awaiting HR → Completed) ── --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-pencil-square"></i></div>
                        <div>
                            <div class="widget-number">{{ $stageCounts['draft'] }}</div>
                            <div class="widget-label">Draft</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-person-check"></i></div>
                        <div>
                            <div class="widget-number">{{ $stageCounts['awaiting_manager'] }}</div>
                            <div class="widget-label">Awaiting Manager Approval</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number">{{ $stageCounts['awaiting_hr'] }}</div>
                            <div class="widget-label">Awaiting HR Approval</div>
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
                            <div class="widget-number">{{ $stageCounts['completed'] }}</div>
                            <div class="widget-label">Completed: Approved by Manager &amp; HR</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Important Reminders (always visible) ── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-info bg-opacity-10 border-0 d-flex align-items-center">
            <i class="bi bi-info-circle text-info me-2"></i><strong>Important Reminders</strong>
        </div>
        <div>
            <div class="card-body small text-muted" style="line-height:1.8;">
                <ol class="mb-0">
                    <li>All claims are for <strong>business purposes only</strong>.</li>
                    <li>File a <strong>separate claim per event / project</strong>; state the project/client name (except Sales). Keep personal general claims separate too.</li>
                    <li>For an <strong>Extra Hours</strong> claim, state the number of extra hours clearly on the form (e.g. <em>Parentcraft Event, 8am&ndash;6pm</em>).</li>
                    <li>Submit to your reporting manager by the <strong>{{ ordinal($policy->submission_deadline_day ?? 20) }}</strong>. If a draft is <strong>complete</strong> (all receipts attached) but still unsubmitted on the {{ ordinal($policy->submission_deadline_day ?? 20) }}, the system <strong>auto-submits it for you</strong>.</li>
                    <li>Attach <strong>supporting receipts/proof</strong> (you can save a draft now and attach later).</li>
                    <li>Mileage: state the route (From → To); Toll/Parking are separate lines.</li>
                    <li>Filing reimbursement for small cash you fronted? A <strong>"Petty Cash – [project]"</strong> claim is fine — just categorise each line properly (don't pick "Petty Cash" as the category).</li>
                </ol>
            </div>
        </div>
    </div>

    {{-- ════════ THE CLAIM FORM: month → Category A → Add New Claim → Category B + items ════════ --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body">
            {{-- Auto-save assurance banner --}}
            <div class="alert d-flex align-items-center gap-2 py-2 px-3 mb-3" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;">
                <i class="bi bi-cloud-check-fill fs-5"></i>
                <div class="small">
                    <strong>Everything auto-saves as you go.</strong> You can safely refresh or log out — your claim is kept as a <strong>Draft</strong> in the list below. Reopen it any time with <em>Continue editing</em>.
                </div>
            </div>

            @if($pastCutoff)
            {{-- Past the monthly cutoff (e.g. the 20th): submission still works but rolls to next cycle. --}}
            <div class="alert d-flex align-items-start gap-2 py-2 px-3 mb-3" style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div class="small">
                    <strong>It's past the {{ ordinal($deadlineDay) }} cutoff for this month.</strong> You can still submit as usual — but anything submitted now may be <strong>processed together with next month's claims</strong>. Any complete draft left unsubmitted will be auto-submitted on the {{ ordinal($deadlineDay) }}.
                </div>
            </div>
            @endif

            @php
                // Reflect the OPEN draft's month when one is loaded (so it doesn't read as a
                // mismatch); otherwise default to the current month for a brand-new claim.
                $claimMonthValue = $activeDraft ? sprintf('%04d-%02d', $activeDraft->year, $activeDraft->month) : now()->format('Y-m');
                $claimMonthName = $activeDraft ? \Carbon\Carbon::createFromDate($activeDraft->year, $activeDraft->month, 1)->format('F') : now()->format('F');
            @endphp
            {{-- Month indicator (reflects the open draft, else the current month) --}}
            <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
                <label for="claimMonth" class="fw-semibold mb-0"><i class="bi bi-calendar3 me-1 text-primary"></i>Claim month</label>
                <input type="month" id="claimMonth" class="form-control form-control-sm" style="max-width:190px;"
                       value="{{ $claimMonthValue }}" max="{{ now()->format('Y-m') }}">
                <span class="text-muted small">{{ $activeDraft ? "This draft's reporting month. Change it to file a new claim under a different month." : 'The reporting month a new claim is filed under.' }}</span>
            </div>

            {{-- Category A — company letterhead (name, department, date, company) --}}
            <div class="border rounded-3 p-3 mb-3 bg-white">
                @include('partials.claim-letterhead', [
                    'company' => $company,
                    'employee' => $employee,
                    'showEvent' => false,
                    'showRules' => false,
                    'claimDate' => now(),
                ])
            </div>

            {{-- Add New Claim (starts a new event claim). Existing drafts live in the list below. --}}
            <div class="d-flex justify-content-end align-items-center flex-wrap gap-2">
                <div>
                    <form id="createClaimForm" action="{{ route('user.claims.create') }}" method="POST" class="d-none">
                        @csrf
                        <input type="hidden" name="period" id="newClaimPeriod" value="{{ $claimMonthValue }}">
                        <input type="hidden" name="event" id="newClaimEvent" value="General Claim {{ $claimMonthName }}">
                    </form>
                    <button type="button" id="addNewClaimBtn" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add New Claim</button>
                </div>
            </div>

            {{-- Category B + item listing — the active draft (the selected chip), inline. --}}
            @if($activeDraft)
            <div class="active-draft-form"
                 data-claim-card data-claim-id="{{ $activeDraft->id }}" id="claim-{{ $activeDraft->id }}">
                <hr class="my-4">
                @include('partials._claim-card-body', ['claim' => $activeDraft])
            </div>
            @endif
        </div>
    </div>

    @php
        // Each status maps to its own filter group so the pills can target a single stage.
        $statusGroup = fn ($s) => match ($s) {
            'draft' => 'draft',
            'submitted' => 'awaiting_manager',
            'manager_approved' => 'awaiting_hr',
            'hr_approved', 'paid' => 'completed',
            default => 'rejected', // manager_rejected / hr_rejected / cancelled
        };
        $reports = $claims; // "All" + the filter counts now include drafts
        $countIn = fn ($group) => $reports->filter(fn ($c) => $statusGroup($c->status) === $group)->count();
        // Group the month buckets by year so the accordion nests Year → Month → Claims.
        $byYear = $byMonth->groupBy(fn ($monthClaims, $key) => substr($key, 0, 4), true);
    @endphp

    {{-- ════════ Reports — collapsible Year → Month → Claims, filterable by status ════════ --}}
    @if($byMonth->isNotEmpty())
    <div class="claim-filter-panel mb-4">
        <div class="claim-filter-label"><i class="bi bi-funnel-fill me-2"></i>Filter by status</div>
        <div class="claim-filters d-flex flex-wrap gap-2 justify-content-center">
            <button type="button" class="claim-filter-btn active" data-filter="all">All <span class="cf-count">{{ $reports->count() }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="draft">Draft <span class="cf-count">{{ $countIn('draft') }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="awaiting_manager">Awaiting Manager <span class="cf-count">{{ $countIn('awaiting_manager') }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="awaiting_hr">Awaiting HR <span class="cf-count">{{ $countIn('awaiting_hr') }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="completed">Completed <span class="cf-count">{{ $countIn('completed') }}</span></button>
            <button type="button" class="claim-filter-btn" data-filter="rejected">Rejected <span class="cf-count">{{ $countIn('rejected') }}</span></button>
        </div>
    </div>

    @foreach($byYear as $yr => $yearMonths)
    @php
        $yrOpen = (int) $yr === (int) $currentYear;                  // current year expanded; past years collapsed
        $yrReports = $yearMonths->sum(fn ($mc) => $mc->count());
        $yrTotal = $yearMonths->sum(fn ($mc) => $mc->whereIn('status', ['hr_approved', 'paid'])->sum('total_with_gst'));
    @endphp
    <div class="year-group mb-3" data-default-open="{{ $yrOpen ? '1' : '0' }}">
        <button type="button" class="year-head" data-bs-toggle="collapse" data-bs-target="#y-{{ $yr }}" aria-expanded="{{ $yrOpen ? 'true' : 'false' }}">
            <span class="year-head-left">
                <span class="year-chip"><i class="bi bi-calendar3"></i></span>
                <span>
                    <span class="year-title">{{ $yr }}</span>
                    <span class="year-sub">{{ $yrReports }} report{{ $yrReports == 1 ? '' : 's' }}</span><span class="head-hint"></span>
                </span>
            </span>
            <span class="year-head-right">
                <span class="year-total" title="Total of reports approved by both Manager/PIC and HR">RM {{ number_format($yrTotal, 2) }}</span>
                <i class="bi bi-chevron-down year-chevron"></i>
            </span>
        </button>
        <div class="collapse {{ $yrOpen ? 'show' : '' }}" id="y-{{ $yr }}">
            <div class="year-body">
                @foreach($yearMonths as $key => $monthClaims)
                @php [$gy, $gm] = explode('-', $key); @endphp
                @php $monthOpen = $yrOpen && $loop->first; @endphp  {{-- only the newest month of the current year opens --}}
                <div class="month-group mb-2" data-default-open="{{ $monthOpen ? '1' : '0' }}">
                    <button type="button" class="month-head" data-bs-toggle="collapse" data-bs-target="#m-{{ $key }}" aria-expanded="{{ $monthOpen ? 'true' : 'false' }}">
                        <span class="month-head-left">
                            <span class="month-chip"><i class="bi bi-calendar3"></i></span>
                            <span>
                                <span class="month-title">{{ \Carbon\Carbon::createFromDate($gy, $gm, 1)->format('F Y') }}</span>
                                <span class="month-sub">{{ $monthClaims->count() }} report{{ $monthClaims->count() == 1 ? '' : 's' }}</span><span class="head-hint"></span>
                            </span>
                        </span>
                        <span class="month-head-right">
                            {{-- Only fully-completed reports (approved by BOTH Manager/PIC and HR) count toward the month total. --}}
                            <span class="month-total" title="Total of reports approved by both Manager/PIC and HR">RM {{ number_format($monthClaims->whereIn('status', ['hr_approved', 'paid'])->sum('total_with_gst'), 2) }}</span>
                            <i class="bi bi-chevron-down month-chevron"></i>
                        </span>
                    </button>
                    <div class="collapse {{ $monthOpen ? 'show' : '' }}" id="m-{{ $key }}">
                        <div class="month-body">
                            @foreach($monthClaims as $claim)
                            <div class="claim-card-wrap" data-status-group="{{ $statusGroup($claim->status) }}">
                                @if($claim->status === 'draft')
                                    @include('partials.claim-draft-row', ['claim' => $claim])
                                @else
                                    @include('partials.claim-card', ['claim' => $claim, 'openClaimId' => $openClaimId])
                                @endif
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

@include('partials.confirm-modal')

{{-- Submit-claim reminder modal (double-check event + approving manager) --}}
<div class="modal fade" id="submitClaimModal" tabindex="-1" aria-labelledby="submitClaimModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-semibold" id="submitClaimModalTitle"><i class="bi bi-send-check me-2 text-success"></i>Submit claim</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary mb-2">Please double-check before submitting:</p>
                <div class="border rounded-3 p-3" style="background:#f8fafc;">
                    <div class="d-flex mb-2">
                        <div class="text-muted" style="width:150px;flex-shrink:0;"><i class="bi bi-bookmark-star me-1 text-primary"></i>Event</div>
                        <div class="fw-semibold" id="smEvent">—</div>
                    </div>
                    <div class="d-flex">
                        <div class="text-muted" style="width:150px;flex-shrink:0;"><i class="bi bi-person-check me-1 text-primary"></i>Approving manager</div>
                        <div class="fw-semibold" id="smManager">—</div>
                    </div>
                </div>
                <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Once submitted you can’t edit this claim.</p>
                @if($pastCutoff)
                <div class="alert mt-2 mb-0 py-2 px-3 small" style="background:#fffbeb;border:1px solid #fcd34d;color:#92400e;">
                    <i class="bi bi-exclamation-triangle me-1"></i>It's past the {{ ordinal($deadlineDay) }} cutoff — this claim may be processed with <strong>next month's</strong> batch.
                </div>
                @endif
            </div>
            <div class="modal-footer border-0 pt-1">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="submitClaimOk"><i class="bi bi-send me-1"></i>Submit claim</button>
            </div>
        </div>
    </div>
</div>

{{-- Delete-whole-claim confirmation modal --}}
<div class="modal fade" id="deleteClaimModal" tabindex="-1" aria-labelledby="deleteClaimModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-semibold" id="deleteClaimModalTitle"><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>Are you sure you want to delete the whole draft claim?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert mb-0 py-2 px-3" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;">
                    <i class="bi bi-trash me-1"></i>By deleting you are deleting the <strong>whole claim</strong>. This action cannot be reversed.
                </div>
            </div>
            <div class="modal-footer border-0 pt-1">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteClaimOk"><i class="bi bi-trash me-1"></i>Proceed</button>
            </div>
        </div>
    </div>
</div>

{{-- Delete-item confirmation modal — lists the exact item(s) being removed (whole attachment group) --}}
<div class="modal fade" id="deleteItemModal" tabindex="-1" aria-labelledby="deleteItemTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-semibold" id="deleteItemTitle"><i class="bi bi-trash me-2 text-danger"></i>Delete item?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary mb-2" id="deleteItemLead">By choosing to delete the receipt, you will delete all the items that are attached to this receipt. To recapture the items, you have to reupload the receipt.</p>
                <ul class="list-group mb-2" id="deleteItemList"></ul>
                <p class="fw-semibold mb-0">Are you sure?</p>
            </div>
            <div class="modal-footer border-0 pt-1">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteItemOk"><i class="bi bi-trash me-1"></i>Proceed</button>
            </div>
        </div>
    </div>
</div>

{{-- Multi-receipt review modal — one uploaded image split into several transactions.
     The user reviews/edits each AI-read line, then adds the selected ones at once. --}}
<div class="modal fade" id="multiReviewModal" tabindex="-1" aria-labelledby="multiReviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-semibold" id="multiReviewTitle"><i class="bi bi-images me-2 text-primary"></i>Multiple receipts found</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning d-flex align-items-start gap-2 py-2 px-3 small mb-2">
                    <i class="bi bi-stars mt-1"></i>
                    <div>The AI read these transactions from your upload. <strong>Check &amp; edit every line</strong> if needed, then tick the ones to add. Each line keeps its source image as proof.</div>
                </div>
                <div class="alert alert-info d-none align-items-start gap-2 py-2 px-3 small mb-2" id="mrTrunc">
                    <i class="bi bi-exclamation-triangle mt-1"></i>
                    <div id="mrTruncMsg"></div>
                </div>
                <div class="small mb-2" id="mrLegend"></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="multiReviewTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="mrCheckAll" class="form-check-input" checked></th>
                                <th style="width:150px;">Date</th>
                                <th>Expense Description <span class="text-danger">*</span></th>
                                <th style="width:230px;">Category</th>
                                <th style="width:130px;" class="text-end">Amount (RM)</th>
                            </tr>
                        </thead>
                        <tbody id="multiReviewBody"></tbody>
                    </table>
                </div>
                <div class="small text-muted" id="mrStatus"></div>
            </div>
            <div class="modal-footer border-0 pt-1">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="mrAddAll"><i class="bi bi-plus-circle me-1"></i>Add selected</button>
            </div>
        </div>
    </div>
</div>

{{-- Toast popup (e.g. "Draft saved") — fixed, top-centre, above content for clear visibility. --}}
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index:1090;">
    <div id="claimToast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body fw-semibold"><i class="bi bi-check-circle me-2"></i><span id="claimToastMsg">Draft saved</span></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

@push('styles')
<style nonce="{{ $cspNonce ?? '' }}">
    /* Make the gradient header fill the whole stat card (so equal-height cards stay full-colour). */
    .dash-widget { display: flex; flex-direction: column; }
    .dash-widget .widget-header { flex: 1 1 auto; }
    /* All stat-card text fully white. */
    .dash-widget .widget-number, .dash-widget .widget-label { color: #fff; }

    /* ── Filter panel — a distinct control bar so it isn't mistaken for the report ── */
    .claim-filter-panel {
        background: #fff; border: 1px solid #e2e8f0; border-radius: 16px;
        padding: 1rem 1.1rem; box-shadow: 0 2px 10px rgba(15,23,42,.06);
    }
    .claim-filter-label {
        text-align: center; font-size: .72rem; font-weight: 700; letter-spacing: .09em;
        text-transform: uppercase; color: #64748b; margin-bottom: .75rem;
    }
    /* ── Filter pills ── */
    .claim-filter-btn {
        display: inline-flex; align-items: center; gap: .5rem;
        border: 2px solid #cbd5e1; background: #fff; color: #1e293b;
        border-radius: 999px; font-weight: 600; font-size: .95rem;
        padding: .55rem 1.35rem; transition: all .15s ease;
        box-shadow: 0 1px 3px rgba(15,23,42,.08);
    }
    .claim-filter-btn:hover { background: #eef2ff; color: #1e293b; border-color: #6366f1; box-shadow: 0 3px 8px rgba(99,102,241,.2); transform: translateY(-1px); }
    .claim-filter-btn .cf-count {
        background: #e2e8f0; color: #334155; border-radius: 999px;
        font-size: .78rem; font-weight: 700; padding: .1rem .55rem; min-width: 1.7em; text-align: center;
    }
    .claim-filter-btn.active {
        background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; border-color: transparent;
        box-shadow: 0 3px 8px rgba(37,99,235,.3);
    }
    .claim-filter-btn.active .cf-count { background: rgba(255,255,255,.25); color: #fff; }

    /* ── Year group (outer collapsible) ── */
    .year-group { border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; background: #fff; box-shadow: 0 1px 4px rgba(15,23,42,.06); }
    .year-head {
        width: 100%; border: 0; text-align: left; cursor: pointer;
        display: flex; justify-content: space-between; align-items: center;
        padding: .9rem 1.15rem;
        background: linear-gradient(135deg, #1e293b, #334155);
        border-bottom: 1px solid transparent;
    }
    .year-head-left { display: flex; align-items: center; gap: .8rem; }
    .year-chip {
        width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
        background: rgba(255,255,255,.16); color: #fff;
        display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem;
    }
    .year-title { display: block; font-weight: 800; color: #fff; font-size: 1.1rem; line-height: 1.2; letter-spacing: .02em; }
    .year-sub { font-size: .75rem; color: #cbd5e1; }
    .year-head-right { display: flex; align-items: center; gap: .85rem; }
    .year-total { font-weight: 700; color: #fff; }
    .year-chevron { color: #cbd5e1; transition: transform .2s ease; }
    .year-head[aria-expanded="false"] .year-chevron { transform: rotate(-90deg); }

    /* ── Collapse-guidance hints (same idea as Ticket Management) — text swaps with state.
          Sits inline next to the year/month report count, separated by a middot. ── */
    .head-hint { font-size: .72rem; font-style: italic; opacity: .8; white-space: nowrap; }
    .year-head .head-hint { color: #cbd5e1; }
    .month-head .head-hint { color: #475569; }
    .head-hint::before { content: '·'; margin: 0 .35rem 0 .15rem; opacity: .7; }
    .year-head .head-hint::after { content: 'Click to view months'; }
    .year-head[aria-expanded="true"] .head-hint::after { content: 'Click to collapse'; }
    .month-head .head-hint::after { content: 'Click to view claims'; }
    .month-head[aria-expanded="true"] .head-hint::after { content: 'Click to collapse'; }
    @media (max-width: 575.98px) { .head-hint { display: none; } }
    .year-body { padding: .7rem; display: flex; flex-direction: column; gap: .5rem; }

    /* ── Draft summary row (Option B — non-editable, links to the top editor) ── */
    .draft-row { background: #fffdf5; border-color: #fde68a; }
    .draft-row-head { cursor: default; }
    .draft-icon { background: linear-gradient(135deg, #8b5cf6, #6d28d9) !important; }
    .draft-row-actions .btn { padding: .15rem .55rem; font-size: .78rem; }

    /* ── Month group ── */
    .month-group { border: 1px solid #e9eef5; border-radius: 14px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
    .month-head {
        width: 100%; border: 0; text-align: left; cursor: pointer;
        display: flex; justify-content: space-between; align-items: center;
        padding: .8rem 1.1rem;
        background: linear-gradient(135deg, #c7d2fe, #dbe4ff);
        border-bottom: 1px solid transparent;
    }
    .month-head[aria-expanded="true"] { border-bottom-color: #aab8f0; }
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

    /* ── Claim cards (expandable per-claim builder) ── */
    .claim-card { border: 1px solid #e9eef5; border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(15,23,42,.05); }
    .claim-card-head { width: 100%; border: 0; text-align: left; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: .7rem .9rem; background: #fff; }
    .claim-card-head:hover { background: #f8fafc; }
    .cc-head-left { display: flex; align-items: center; gap: .7rem; min-width: 0; }
    .cc-icon { width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
    .cc-title { display: block; font-weight: 600; color: #1e293b; }
    .cc-sub { font-size: .75rem; color: #64748b; }
    .cc-head-right { display: flex; align-items: center; gap: .7rem; }
    .cc-total { font-weight: 700; color: #1d4ed8; white-space: nowrap; }
    .cc-chevron { color: #94a3b8; transition: transform .2s ease; }
    .claim-card-head[aria-expanded="false"] .cc-chevron { transform: rotate(-90deg); }
    .cc-body { padding: .9rem; border-top: 1px solid #eef2f7; background: #f8fafc; }
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    const pills = document.querySelectorAll('.claim-filter-btn');
    if (!pills.length) return;
    // Toggle a Bootstrap collapse open/closed AND keep its toggle button's aria in sync.
    function setOpen(collapseEl, open) {
        if (!collapseEl) return;
        collapseEl.classList.toggle('show', open);
        const btn = document.querySelector('[data-bs-target="#' + collapseEl.id + '"]');
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    // Restore the server-rendered default collapse state (current year + its newest month open).
    function restoreDefault() {
        document.querySelectorAll('.year-group, .month-group').forEach(function (g) {
            setOpen(g.querySelector(':scope > .collapse'), g.dataset.defaultOpen === '1');
        });
    }
    function apply(filter) {
        const isAll = filter === 'all';
        document.querySelectorAll('.claim-card-wrap').forEach(function (row) {
            row.style.display = (isAll || row.dataset.statusGroup === filter) ? '' : 'none';
        });
        // Month groups: hide those with no matching card. For a non-All filter, keep the
        // matching months COLLAPSED — the user expands each month themselves to see its reports.
        document.querySelectorAll('.month-group').forEach(function (group) {
            const anyVisible = Array.from(group.querySelectorAll('.claim-card-wrap')).some(r => r.style.display !== 'none');
            group.style.display = anyVisible ? '' : 'none';
            if (!isAll) setOpen(group.querySelector(':scope > .collapse'), false);
        });
        // Year groups: hide those with no visible month; keep the rest OPEN so the (collapsed)
        // month list is visible to click into.
        document.querySelectorAll('.year-group').forEach(function (group) {
            const anyMonth = Array.from(group.querySelectorAll('.month-group')).some(m => m.style.display !== 'none');
            group.style.display = anyMonth ? '' : 'none';
            if (!isAll) setOpen(group.querySelector(':scope > .collapse'), anyMonth);
        });
        if (isAll) restoreDefault();
    }
    pills.forEach(function (btn) {
        btn.addEventListener('click', function () {
            pills.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            apply(btn.dataset.filter);
        });
    });
    // Draft summary-row delete (Option B) — confirm, then submit its hidden DELETE form.
    document.addEventListener('click', function (e) {
        const del = e.target.closest('.draft-delete-btn');
        if (!del) return;
        if (!confirm('Delete this draft claim and all its items? This cannot be undone.')) return;
        const form = del.parentElement.querySelector('.draft-delete-form');
        if (form) form.submit();
    });
})();

// Month indicator → keep the top "Add New Claim" form's period + default event in sync.
(function () {
    const monthInput = document.getElementById('claimMonth');
    const period = document.getElementById('newClaimPeriod');
    const event = document.getElementById('newClaimEvent');
    if (!monthInput) return;
    const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    monthInput.addEventListener('change', function () {
        const v = monthInput.value; if (!/^\d{4}-\d{2}$/.test(v)) return;
        if (period) period.value = v;
        if (event) event.value = 'General Claim ' + (MONTHS[parseInt(v.slice(5,7),10) - 1] || '');
    });
})();

// Add New Claim — reveal the inline Category B + items form if a draft exists,
// otherwise create the (single) draft. Never opens a separate card.
(function () {
    const btn = document.getElementById('addNewClaimBtn');
    if (!btn) return;
    // Always start a NEW event claim (the server reuses an empty draft if one exists,
    // so blank clicks don't pile up, but a draft with items begins a fresh claim).
    btn.addEventListener('click', function () {
        document.getElementById('createClaimForm').submit();
    });
})();

// ── Per-claim inline builder (event-delegated so it works for every claim card) ──
(function () {
    const CSRF = '{{ csrf_token() }}';
    const CAP_INFO = @json($capInfo ?? []); // per-category {remaining, limit, period, name} for capped categories
    const SCAN_URL = '{{ route('user.claims.scan-receipt') }}';
    const ADDITEM_BASE = '{{ url('/my/claims') }}';      // + /{id}/inline-item
    const REMOVE_BASE = '{{ url('/my/claims/item') }}';  // + /{id}/inline-remove
    const MILEAGE_GL = '{{ config('claims.mileage.gl_code') }}';        // Petrol category GL code
    const PROJECT_REQUIRED = @json($projectRequired ?? true);          // project/client name required (non-Sales)
    const MILEAGE_RATE_CAR = {{ (float) config('claims.mileage.rates.car', 0.7) }}; // RM per km
    const ORS_ROUTE_URL = '{{ route('user.claims.mileage-distance-route') }}'; // multi-stop distance

    // Calculate the total driving distance for a route (list of stops) via ORS, then
    // fill the km field. Used when a map screenshot has no km on it.
    function calcRouteDistance(c, stops, hint) {
        stops = (stops || []).filter(s => s && s !== '?');
        if (stops.length < 2) { if (hint) hint.textContent = 'No route to measure — enter the km manually.'; return; }
        if (hint) hint.textContent = '⏳ Calculating distance from the route…';
        fetch(ORS_ROUTE_URL, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ stops: stops }) })
            .then(r => r.json()).then(d => {
                if (d && d.ok && d.km) {
                    q(c,'.cc-i-km').value = d.km;
                    computeMileage(c);
                    if (hint) hint.textContent = '≈ Estimated ' + d.km + ' km (maps estimate — may differ from Google; edit the km if you know the exact figure).';
                } else if (d && d.enabled === false) {
                    if (hint) hint.textContent = 'Auto-distance is off — enter the km manually.';
                } else {
                    if (hint) hint.textContent = (d && d.message) ? d.message : 'Couldn’t calculate the distance — enter the km manually.';
                }
            })
            .catch(() => { if (hint) hint.textContent = 'Couldn’t calculate the distance — enter the km manually.'; });
    }
    const escHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const cardOf = (el) => el.closest('[data-claim-card]');
    const q = (c, sel) => c.querySelector(sel);
    const showErr = (el, msg) => { el.textContent = msg; el.classList.remove('d-none'); };

    function syncTotal(c) {
        const a = parseFloat(q(c,'.cc-i-amount').value) || 0;
        const g = parseFloat(q(c,'.cc-i-gst').value) || 0;
        q(c,'.cc-i-total').value = (a + g).toFixed(2);
        applyCapPreview(c);
        applyReceiptCheck(c);
    }
    // Receipt counter-check for a plain RECEIPT category (not capped/computed). Returns TRUE
    // when the entry is OVER-claiming (claimed > receipt) — that case is shown in RED and the
    // caller (addItem) must BLOCK the add. Under-claiming is shown in amber as a soft warning
    // and returns FALSE (allowed — e.g. a partial claim).
    function applyReceiptCheck(c) {
        const el = q(c, '.cc-receipt-check'); if (!el) return false;
        const amber = () => { el.style.background = '#fffbeb'; el.style.border = '1px solid #fcd34d'; el.style.color = '#92400e'; };
        const red   = () => { el.style.background = '#fef2f2'; el.style.border = '1px solid #f87171'; el.style.color = '#b91c1c'; };
        const hide = () => { el.classList.add('d-none'); el.innerHTML = ''; amber(); return false; };
        const cat = q(c, '.cc-i-cat'), opt = (cat && cat.value) ? cat.selectedOptions[0] : null;
        // If a category IS chosen, skip capped ones (Medical, Optical & Dental, etc.) and
        // computed/fixed ones (mileage, per-day/hour, season parking) — their claimable is
        // intentionally ≠ the receipt. With NO category yet, still counter-check (it re-runs
        // when a category is picked).
        if (opt && ((opt.dataset.rateType || 'receipt') !== 'receipt' || CAP_INFO[opt.value])) return hide();
        const receipt = parseFloat(q(c, '.cc-c-total').value);   // total read from the receipt (scan)
        const claimed = parseFloat(q(c, '.cc-i-total').value);   // amount + SST being claimed
        if (!(receipt > 0) || !(claimed > 0)) return hide();      // nothing to compare against
        const diff = Math.round((claimed - receipt) * 100) / 100; // 2-dp difference
        const fmt = n => Number(n).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (diff > 0) {
            // ANY amount over the receipt → hard block (even 1 cent). Red banner; addItem refuses.
            el.classList.remove('d-none');
            red();
            el.innerHTML = '<i class="bi bi-x-octagon-fill me-1"></i><strong>You can’t claim more than the receipt</strong> — you’re claiming <strong>RM ' + fmt(claimed) +
                '</strong> but the receipt only shows <strong>RM ' + fmt(receipt) + '</strong>. Lower the amount to <strong>RM ' + fmt(receipt) + '</strong> or less to add this item.';
            return true;
        }
        if (Math.abs(diff) <= 0.01) return hide();   // exact match or ≤1 cent under — all good
        // Under-claim → soft warning only (allowed).
        el.classList.remove('d-none');
        amber();
        el.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Heads up — you’re claiming <strong>RM ' + fmt(claimed) +
            '</strong> but the receipt shows <strong>RM ' + fmt(receipt) + '</strong>. That’s fine if it’s intentional ' +
            '(e.g. a partial claim); otherwise please double-check before adding.';
        return false;
    }
    // Capped-category preview (e.g. intern Medical RM100/mo): show the remaining allowance and
    // auto-cap the claimable TOTAL (amount + SST) to it, keeping the SST when it still fits.
    function applyCapPreview(c) {
        const cat = q(c,'.cc-i-cat'), hint = q(c,'.cc-cap-hint');
        const info = cat ? CAP_INFO[cat.value] : null;
        if (!info) { if (hint) { hint.classList.add('d-none'); hint.innerHTML = ''; } return; }
        const amountEl = q(c,'.cc-i-amount'), gstEl = q(c,'.cc-i-gst'), totalEl = q(c,'.cc-i-total');
        const fmt = n => Number(n).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const remaining = Number(info.remaining);
        if (hint) {
            hint.classList.remove('d-none');
            hint.className = 'cc-cap-hint small fw-semibold ' + (remaining <= 0 ? 'text-danger' : 'text-success');
            hint.innerHTML = remaining <= 0
                ? '<i class="bi bi-exclamation-triangle me-1"></i>RM ' + fmt(info.limit) + ' ' + info.period + ' ' + info.name + ' allowance fully used this period.'
                : '<i class="bi bi-wallet2 me-1"></i>RM ' + fmt(remaining) + ' of your RM ' + fmt(info.limit) + ' ' + info.period + ' ' + info.name + ' allowance is left — a bigger receipt is auto-capped to this.';
        }
        // Auto-cap only editable amounts (skip fixed categories); leave a fully-used category
        // for the server to block with its proper message.
        if (amountEl.readOnly || remaining <= 0) return;
        let a = parseFloat(amountEl.value) || 0, g = parseFloat(gstEl.value) || 0;
        if ((a + g) > remaining + 0.001) {
            if (g > 0 && g < remaining) { a = +(remaining - g).toFixed(2); }
            else { g = 0; a = +remaining.toFixed(2); }
            amountEl.value = a.toFixed(2);
            gstEl.value = g.toFixed(2);
            totalEl.value = (a + g).toFixed(2);
        }
    }
    function normalizeDate(s) {
        s = String(s || '').trim();
        let m = s.match(/^(\d{4})-(\d{2})-(\d{2})/); if (m) return m[1]+'-'+m[2]+'-'+m[3];
        m = s.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/); if (m) return m[3]+'-'+String(m[2]).padStart(2,'0')+'-'+String(m[1]).padStart(2,'0');
        return null;
    }
    function filterAppr(c, query) {
        query = (query || '').trim().toLowerCase();
        const coEl = q(c,'.cc-appr-company'); const co = coEl ? coEl.value : '';
        let any = false;
        c.querySelectorAll('.cc-appr-opt').forEach(o => {
            // Show approvers matching the search AND (when a company is selected) that company.
            const ok = (!query || o.dataset.search.indexOf(query) !== -1) && (!co || o.dataset.company === co);
            o.classList.toggle('d-none', !ok); if (ok) any = true;
        });
        const nm = c.querySelector('.cc-appr-nomatch'); if (nm) nm.classList.toggle('d-none', any);
    }
    // Category C — read-only receipt details captured at scan.
    function setC(c, o) {
        o = o || {};
        q(c,'.cc-c-company').value = o.company || '';
        q(c,'.cc-c-itemdesc').value = o.itemdesc || '';
        q(c,'.cc-c-date').value = o.date || '';
        q(c,'.cc-c-paidby').value = o.paidby || '';
        q(c,'.cc-c-total').value = (o.total !== undefined && o.total !== null && o.total !== '') ? o.total : '';
        const calc = q(c,'.cc-c-calc'), calcWrap = c.querySelector('.cc-c-calc-wrap');
        if (calc) calc.value = o.calc || '';
        if (calcWrap) calcWrap.classList.toggle('d-none', !o.calc);
    }
    // Mileage: amount = distance × vehicle rate; updates the calculation shown in Category C.
    function computeMileage(c) {
        const veh = q(c,'.cc-i-vehicle'), kmEl = q(c,'.cc-i-km'), amount = q(c,'.cc-i-amount');
        if (!veh || !kmEl) return;
        const rate = parseFloat(veh.selectedOptions[0] && veh.selectedOptions[0].dataset.rate) || 0;
        const km = parseFloat(kmEl.value) || 0;
        const amt = km * rate;
        amount.value = amt ? amt.toFixed(2) : '';
        syncTotal(c);
        const vehLabel = veh.value === 'motorcycle' ? 'Motorcycle' : 'Car';
        const calc = km > 0 ? (km + ' km × RM' + rate.toFixed(2) + ' (' + vehLabel + ') = RM' + amt.toFixed(2)) : '';
        const calcEl = q(c,'.cc-c-calc'), calcWrap = c.querySelector('.cc-c-calc-wrap');
        if (calcEl) calcEl.value = calc;
        if (calcWrap) calcWrap.classList.toggle('d-none', !calc);
        // No "Total paid" for mileage — a maps screenshot has no paid amount; the
        // calculation above is the figure. (Receipt details only show what was read.)
        q(c,'.cc-c-total').value = '';
    }
    function resetEntry(c) {
        q(c,'.cc-i-desc').value = ''; q(c,'.cc-i-cat').value = '';
        const a = q(c,'.cc-i-amount'); a.value = ''; a.readOnly = false;
        const g = q(c,'.cc-i-gst'); g.value = '0'; g.readOnly = false;
        q(c,'.cc-i-total').value = ''; q(c,'.cc-i-file').value = '';
        const sup = q(c,'.cc-i-support'); if (sup) sup.value = '';
        const mrow = c.querySelector('.cc-mileage-row'); if (mrow) mrow.classList.add('d-none');
        const km = q(c,'.cc-i-km'); if (km) km.value = '';
        const veh = q(c,'.cc-i-vehicle'); if (veh) veh.value = 'car';
        setC(c, {});
        const sb = q(c,'.cc-scan-btn'); if (sb) sb.classList.add('d-none');
        const od = q(c,'.cc-ocr-details'); if (od) { od.classList.add('d-none'); od.innerHTML = ''; }
        const h = q(c,'.cc-scan-hint'); if (h) h.textContent = '';
        applyCapPreview(c); // category cleared → hide any leftover cap allowance hint
        applyReceiptCheck(c); // hide any leftover amount-mismatch warning
    }
    function updateTotals(c, total, count) {
        q(c,'.cc-claim-total').textContent = 'RM ' + total;
        const badge = q(c,'.cc-item-count'); if (badge) badge.textContent = count;
        const headTotal = c.querySelector('.cc-total'); if (headTotal) headTotal.textContent = 'RM ' + total;
        const headSub = c.querySelector('.cc-sub'); if (headSub) headSub.textContent = headSub.textContent.replace(/\d+ items?/, count + ' item' + (count == 1 ? '' : 's'));
        const submitBtn = c.querySelector('.cc-submit-claim'); if (submitBtn) submitBtn.disabled = (parseInt(count, 10) || 0) === 0;
        // Keep the draft chip's count badge in sync with live add/remove (it's server-rendered
        // at page load, so without this it shows a stale count after a bulk/single add).
        const chip = document.querySelector('.cc-draft-count[data-draft-id="' + c.dataset.claimId + '"]');
        if (chip) chip.textContent = count;
        // Mirror the change onto this draft's summary row in the list below (it's a separate,
        // server-rendered element, so it would otherwise show a stale total/count until reload).
        const row = document.querySelector('[data-draft-summary="' + c.dataset.claimId + '"]');
        if (row) {
            const rt = row.querySelector('.ds-total'); if (rt) rt.textContent = total;
            const rc = row.querySelector('.ds-count'); if (rc) rc.textContent = count;
        }
    }
    function appendRow(c, item) {
        const body = q(c,'.cc-items-body');
        const empty = body.querySelector('.cc-empty-row'); if (empty) empty.remove();
        const tr = document.createElement('tr');
        tr.setAttribute('data-item-row', item.id);
        fillRow(tr, item);
        body.appendChild(tr);
    }

    // Several files selected at once → OCR each, aggregate into the review list.
    function scanMultipleFiles(c, files, hint, btn) {
        const fd = new FormData();
        files.forEach(f => fd.append('receipt_files[]', f));
        btn.disabled = true; hint.textContent = 'Scanning ' + files.length + ' files…';
        fetch(SCAN_URL, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd })
            .then(r => r.json()).then(d => {
                btn.disabled = false;
                if (!d || d.enabled === false) { hint.textContent = 'OCR is off — enter details manually.'; return; }
                if (!d.ok || !Array.isArray(d.items) || !d.items.length) { hint.textContent = 'Couldn’t read those files — try adding them one at a time.'; return; }
                hint.textContent = '✨ Found ' + d.items.length + ' transactions across ' + files.length + ' files — review and add them.';
                openMultiReview(c, d.items, files, d.truncated);
            })
            .catch(() => { btn.disabled = false; hint.textContent = 'Scan failed — try again or add manually.'; });
    }

    // ── PDF receipts: rasterise page 1 IN THE BROWSER (PDF.js, served same-origin) so the
    // image OCR can read it. The server never touches the PDF; the original PDF is still what
    // gets attached on "Add to list". Lazy-loaded only when a PDF is actually scanned. ──
    let __pdfjs = null;
    function loadPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (__pdfjs) return __pdfjs;
        __pdfjs = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = '{{ asset('vendor/pdfjs/pdf.min.js') }}';
            s.onload = () => {
                if (!window.pdfjsLib) return reject(new Error('pdfjsLib missing'));
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = '{{ asset('vendor/pdfjs/pdf.worker.min.js') }}';
                resolve(window.pdfjsLib);
            };
            s.onerror = () => reject(new Error('pdf.js load failed'));
            document.head.appendChild(s);
        });
        return __pdfjs;
    }
    const isPdfFile = (f) => f && (f.type === 'application/pdf' || /\.pdf$/i.test(f.name || ''));
    // PDF File → PNG File of its first page (for OCR only). Resolves null on any failure.
    async function pdfToPngFile(file) {
        try {
            const lib = await loadPdfJs();
            const pdf = await lib.getDocument({ data: await file.arrayBuffer() }).promise;
            const page = await pdf.getPage(1);
            let viewport = page.getViewport({ scale: 2 }); // 2x for legible OCR
            const MAXW = 1600;
            if (viewport.width > MAXW) viewport = page.getViewport({ scale: 2 * (MAXW / viewport.width) });
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(viewport.width); canvas.height = Math.ceil(viewport.height);
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); // flatten transparency
            await page.render({ canvasContext: ctx, viewport }).promise;
            const blob = await new Promise(res => canvas.toBlob(res, 'image/png'));
            if (!blob) return null;
            return new File([blob], (file.name || 'receipt').replace(/\.pdf$/i, '') + '.png', { type: 'image/png' });
        } catch (e) { return null; }
    }

    function scanItem(c, btn) {
        const file = q(c,'.cc-i-file'); if (!file.files.length) return;
        const hint = q(c,'.cc-scan-hint'), details = q(c,'.cc-ocr-details');
        const files = Array.from(file.files);
        // A single PDF → render page 1 to an image, then scan that image. (Multi-file batches
        // pass straight through to the existing path.)
        if (files.length === 1 && isPdfFile(files[0])) {
            btn.disabled = true; hint.textContent = 'Preparing PDF…';
            pdfToPngFile(files[0]).then(png => {
                btn.disabled = false;
                if (!png) { hint.textContent = 'Couldn’t read this PDF — please enter the details manually.'; return; }
                scanSingle(c, btn, png, hint, details);
            });
            return;
        }
        // Multiple files chosen → multi-file path (each file OCR'd, then the review list).
        if (files.length > 1) { scanMultipleFiles(c, files, hint, btn); return; }
        scanSingle(c, btn, files[0], hint, details);
    }

    function scanSingle(c, btn, scanFile, hint, details) {
        const fd = new FormData(); fd.append('receipt', scanFile);
        btn.disabled = true; hint.textContent = 'Scanning…';
        fetch(SCAN_URL, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd })
            .then(r => r.json()).then(d => {
                btn.disabled = false;
                if (!d || d.enabled === false) { hint.textContent = 'OCR is off — enter details manually.'; return; }
                if (!d.ok) { hint.textContent = (d && d.message) ? d.message : 'Couldn’t read it — enter details manually.'; return; }
                // One image holding several receipts / a dated statement → review table.
                // When editing a single item, a re-scan must be one receipt — but if it still
                // reads as several lines, unlock the fields anyway so the user isn't stuck.
                if (d.multi && Array.isArray(d.items) && d.items.length) {
                    if (c.dataset.editingItem) {
                        setEditLock(c, false);
                        const att = q(c,'.cc-edit-att'); if (att) att.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>New receipt scanned — it will replace the previous attachment.</span>';
                        hint.textContent = 'Read several lines on this receipt — kept the current details; adjust if needed, then Save.';
                        return;
                    }
                    hint.textContent = '✨ Found ' + d.items.length + ' transactions — review and add them.';
                    // Attach the ORIGINAL uploaded file (e.g. the PDF), not the rasterised scan image.
                    const origFile = (q(c,'.cc-i-file').files[0]) || scanFile;
                    openMultiReview(c, d.items, [origFile], d.truncated);
                    return;
                }
                const desc = q(c,'.cc-i-desc'), cat = q(c,'.cc-i-cat'), amount = q(c,'.cc-i-amount'), gst = q(c,'.cc-i-gst'), date = q(c,'.cc-i-date');
                const bits = [];

                const isMap = (d.distance_km !== null && d.distance_km !== undefined) || d.route_from || (Array.isArray(d.route_stops) && d.route_stops.length >= 2);
                if (isMap) {
                    // ── Google Maps / route screenshot → Petrol mileage ──
                    // Select the mileage (Petrol) category → reveals the Vehicle + Distance row.
                    const mOpt = Array.from(cat.options).find(o => o.dataset.glCode && o.dataset.glCode === MILEAGE_GL);
                    if (mOpt) { cat.value = mOpt.value; cat.dispatchEvent(new Event('change', { bubbles: true })); }
                    // Route → Category C (Item description). Multi-stop: A → B → A.
                    const stops = (Array.isArray(d.route_stops) && d.route_stops.length >= 2)
                        ? d.route_stops
                        : [(d.route_from || '?'), (d.route_to || '?')];
                    const route = stops.join(' → ');
                    q(c,'.cc-c-itemdesc').value = route;
                    const hasKm = d.distance_km !== null && d.distance_km !== undefined;
                    if (hasKm) {
                        // The map already shows Google's exact distance — use it (most accurate).
                        q(c,'.cc-i-km').value = parseFloat(d.distance_km);
                        computeMileage(c);
                        hint.textContent = '✨ Distance read from the map — pick the vehicle, then enter the description & date.';
                        bits.push(parseFloat(d.distance_km) + ' km (from the map)');
                    } else {
                        // No km on the screenshot → best-effort estimate from the route via ORS.
                        q(c,'.cc-i-km').value = '';
                        computeMileage(c);
                        calcRouteDistance(c, stops, hint);
                    }
                    bits.push('Route ' + route);
                    // (Expense Description & Date are NOT auto-filled — the user enters them.)
                } else {
                    // ── Receipt — only the CATEGORY & AMOUNT auto-fill. The user types the
                    // Expense Description and Date of Expense manually (the OCR's reading of
                    // company/date/etc. is captured separately as Category C, below).
                    if (d.category_id) { cat.value = String(d.category_id); cat.dispatchEvent(new Event('change', { bubbles: true })); }
                    if (d.amount && !amount.readOnly) {
                        // Split SST out of the grand total when the receipt shows it: "Amount (w/o SST)"
                        // holds the net, the SST field the tax, and the total stays the receipt total.
                        const total = parseFloat(d.amount);
                        const tax = (d.gst && !gst.readOnly) ? parseFloat(d.gst) : 0;
                        if (tax > 0 && tax < total) { amount.value = (total - tax).toFixed(2); gst.value = tax.toFixed(2); }
                        else { amount.value = total.toFixed(2); }
                        syncTotal(c);
                    }
                    hint.textContent = '✨ Category & amount auto-filled, receipt details captured below — now enter the description & date.';
                    // Capture Category C (read-only receipt details) into the fields below.
                    setC(c, { company: d.vendor, itemdesc: d.item_description, date: d.date, paidby: d.paid_by, total: d.amount });
                    applyReceiptCheck(c); // re-check now that the receipt total is captured
                    // No detailed "extracted" line for receipts — the Category C fields show it.
                }

                if (bits.length && details) { details.innerHTML = '<i class="bi bi-magic me-1"></i>Extracted from attachment: ' + escHtml(bits.join(' · ')); details.classList.remove('d-none'); }
                // Editing: a fresh receipt was scanned → unlock the fields so they can edit & save.
                if (c.dataset.editingItem) {
                    setEditLock(c, false);
                    const att = q(c,'.cc-edit-att'); if (att) att.innerHTML = '<span class="text-success"><i class="bi bi-check-circle me-1"></i>New receipt scanned — it will replace the previous attachment.</span>';
                    hint.textContent = '✨ Re-scanned — review the details, then Save changes.';
                }
            })
            .catch(() => { btn.disabled = false; hint.textContent = 'Scan failed — enter details manually.'; });
    }

    function fillRow(tr, item) {
        tr.setAttribute('data-date-input', item.date_input);
        tr.setAttribute('data-desc', item.description);
        tr.setAttribute('data-cat-id', item.category_id);
        tr.setAttribute('data-amount', item.amount.replace(/,/g, ''));
        tr.setAttribute('data-gst', item.gst.replace(/,/g, ''));
        tr.setAttribute('data-km', (item.ocr && item.ocr.km) ? item.ocr.km : '');
        tr.setAttribute('data-vehicle', (item.ocr && item.ocr.vehicle) ? item.ocr.vehicle : '');
        tr.setAttribute('data-ocr', item.ocr ? JSON.stringify(item.ocr) : '');
        tr.setAttribute('data-receipt-url', item.receipt_url || '');
        tr.setAttribute('data-has-receipt', item.has_receipt ? '1' : '');
        tr.setAttribute('data-receipt-hash', item.receipt_hash || '');
        tr.innerHTML =
            '<td class="text-nowrap">' + escHtml(item.date) + '</td>' +
            '<td>' + escHtml(item.description) + '</td>' +
            '<td><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">' + escHtml(item.category) + '</span></td>' +
            '<td class="text-end">RM ' + escHtml(item.amount) + '</td>' +
            '<td class="text-end">RM ' + escHtml(item.gst) + '</td>' +
            '<td class="text-end fw-semibold">RM ' + escHtml(item.total) + '</td>' +
            '<td>' + (item.receipt_url ? '<a href="' + escHtml(item.receipt_url) + '" target="_blank" class="text-success" title="View attachment"><i class="bi bi-paperclip"></i></a>' : (item.has_receipt ? '<i class="bi bi-paperclip text-success"></i>' : '<span class="text-muted small">—</span>')) + '</td>' +
            '<td class="text-end text-nowrap">' +
                '<button type="button" class="btn btn-sm btn-outline-danger py-0 cc-remove-item" data-item-id="' + item.id + '" title="Delete (to change an item, delete it and add it again)"><i class="bi bi-trash"></i></button>' +
            '</td>';
    }

    function setEditMode(c, on) {
        const btn = q(c,'.cc-add-item-btn'), cancel = q(c,'.cc-cancel-edit');
        if (on) {
            btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save changes';
            cancel.classList.remove('d-none');
        } else {
            c.removeAttribute('data-editing-item');
            btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>Add to list';
            cancel.classList.add('d-none');
            setEditLock(c, false); // leaving edit → unlock the fields
            const att = q(c,'.cc-edit-att'); if (att) { att.classList.add('d-none'); att.innerHTML = ''; }
        }
    }
    // Map a stored ocr_details object to the setC (Category C) shape.
    function ocrToC(ocr) {
        ocr = ocr || {};
        return { company: ocr.company || '', itemdesc: ocr.item_description || '', date: ocr.date || '',
            paidby: ocr.paid_by || '', total: (ocr.total !== undefined && ocr.total !== null ? ocr.total : ''), calc: ocr.calculation || '' };
    }
    // Lock/unlock the editable item fields + Save button (used by the "re-scan to edit" flow).
    function setEditLock(c, locked) {
        ['.cc-i-desc', '.cc-i-date', '.cc-i-cat', '.cc-i-amount', '.cc-i-gst', '.cc-i-km', '.cc-i-vehicle'].forEach(function (sel) {
            const el = q(c, sel); if (el) el.disabled = locked;
        });
        const btn = q(c,'.cc-add-item-btn'); if (btn) btn.disabled = locked;
        c.dataset.editLocked = locked ? '1' : '';
    }

    function startEdit(c, tr) {
        c.dataset.editingItem = tr.dataset.itemRow || tr.getAttribute('data-item-row');
        q(c,'.cc-i-desc').value = tr.dataset.desc || '';
        q(c,'.cc-i-date').value = tr.dataset.dateInput || '';
        const cat = q(c,'.cc-i-cat'); cat.value = tr.dataset.catId || ''; cat.dispatchEvent(new Event('change', { bubbles: true }));
        const opt = cat.selectedOptions[0];
        if (opt && opt.dataset.glCode === MILEAGE_GL) {
            // Restore vehicle + distance so the mileage amount isn't reset to 0.
            const veh = q(c,'.cc-i-vehicle'); if (veh && tr.dataset.vehicle) veh.value = tr.dataset.vehicle;
            const kmEl = q(c,'.cc-i-km'); if (kmEl) kmEl.value = tr.dataset.km || '';
            computeMileage(c);
        } else {
            q(c,'.cc-i-amount').value = tr.dataset.amount || '';
            q(c,'.cc-i-gst').value = tr.dataset.gst || '0';
        }
        // Show the CURRENT receipt details (Category C) read from the stored scan.
        let ocr = {}; try { ocr = JSON.parse(tr.dataset.ocr || '{}') || {}; } catch (e) { ocr = {}; }
        setC(c, ocrToC(ocr));
        syncTotal(c);
        setEditMode(c, true);
        // Show the existing attachment (will be replaced when a new file is re-uploaded).
        const att = q(c,'.cc-edit-att'), url = tr.dataset.receiptUrl || '';
        if (att) {
            att.innerHTML = url
                ? '<i class="bi bi-paperclip me-1"></i>Current attachment: <a href="' + escHtml(url) + '" target="_blank">view</a> <span class="text-muted">— re-upload &amp; scan a new file to replace it and edit.</span>'
                : '<span class="text-muted"><i class="bi bi-paperclip me-1"></i>No attachment on this item yet — upload &amp; scan to edit.</span>';
            att.classList.remove('d-none');
        }
        // Lock everything until a fresh receipt is re-uploaded and scanned.
        setEditLock(c, true);
        const eh = q(c,'.cc-scan-hint'); if (eh) eh.textContent = 'Re-upload & Scan the receipt to edit this item.';
    }

    // AJAX-save the claim's Category B details (so items inherit the current values).
    function saveCatB(c) {
        const form = c.querySelector('.cc-details');
        if (!form) return Promise.resolve(true);
        return fetch(form.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: new FormData(form) })
            .then(r => r.json().then(d => r.ok && d.ok === true)).catch(() => false);
    }
    // ── Auto-save the claim header (event/approver/project/date) — no "Save draft" button. ──
    const catBTimers = new WeakMap();
    function setSaveStatus(c, state) {
        const el = c.querySelector('.cc-autosave-status'); if (!el) return;
        if (state === 'saving') el.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Saving…';
        else if (state === 'saved') el.innerHTML = '<i class="bi bi-cloud-check me-1 text-success"></i>All changes saved';
        else if (state === 'error') el.innerHTML = '<i class="bi bi-exclamation-triangle me-1 text-danger"></i>Couldn’t save — check the details';
    }
    function autoSaveCatB(c) {
        if (!c || !c.querySelector('.cc-details')) return;
        clearTimeout(catBTimers.get(c));
        setSaveStatus(c, 'saving');
        catBTimers.set(c, setTimeout(function () {
            saveCatB(c).then(function (ok) { setSaveStatus(c, ok ? 'saved' : 'error'); if (ok) updateHeaderTitle(c); });
        }, 700));
    }
    // Save Category B, then post the (hidden) submit form. Used after the reminder modal.
    let pendingSubmitCard = null;
    function doSubmit(c) {
        const btn = c.querySelector('.cc-submit-claim'); if (btn) btn.disabled = true;
        saveCatB(c).then(ok => {
            if (ok) { c.querySelector('.cc-submit-form').submit(); }
            else { if (btn) btn.disabled = false; alert('Could not save the claim details before submitting.'); }
        });
    }
    (function () {
        const ok = document.getElementById('submitClaimOk');
        if (!ok) return;
        ok.addEventListener('click', function () {
            const c = pendingSubmitCard; pendingSubmitCard = null;
            const sm = document.getElementById('submitClaimModal');
            if (sm && window.bootstrap) bootstrap.Modal.getOrCreateInstance(sm).hide();
            if (c) doSubmit(c);
        });
    })();
    // Delete-whole-claim confirmation modal → Proceed submits the hidden delete form.
    let pendingDeleteCard = null;
    (function () {
        const ok = document.getElementById('deleteClaimOk');
        if (!ok) return;
        ok.addEventListener('click', function () {
            const c = pendingDeleteCard; pendingDeleteCard = null;
            const dm = document.getElementById('deleteClaimModal');
            if (dm && window.bootstrap) bootstrap.Modal.getOrCreateInstance(dm).hide();
            const form = c && c.querySelector('.cc-delete-form'); if (form) form.submit();
        });
    })();
    function showToast(msg, variant) {
        const el = document.getElementById('claimToast');
        if (!el) return;
        el.className = 'toast align-items-center border-0 text-bg-' + (variant || 'success');
        const body = document.getElementById('claimToastMsg'); if (body) body.textContent = msg || 'Saved';
        if (window.bootstrap && bootstrap.Toast) {
            bootstrap.Toast.getOrCreateInstance(el, { delay: 2500 }).show();
        } else { // graceful fallback if Bootstrap JS hasn't loaded
            el.classList.add('show'); setTimeout(() => el.classList.remove('show'), 2500);
        }
    }
    function flashSaved(c) { showToast('Draft saved', 'success'); }
    function collapseCard(c) {
        const coll = c.querySelector('.collapse');
        if (coll && window.bootstrap && bootstrap.Collapse) { bootstrap.Collapse.getOrCreateInstance(coll).hide(); }
        else if (c.classList.contains('active-draft-form')) { c.classList.add('d-none'); } // inline form → hide
    }
    function updateHeaderTitle(c) {
        const ev = q(c,'.cc-details [name="event"]'), title = c.querySelector('.cc-title');
        const name = ev ? ev.value.trim() : '';
        if (ev && title && name) title.textContent = name;
        // Keep this draft's summary-row title in the list below in sync as the event is renamed.
        const row = document.querySelector('[data-draft-summary="' + c.dataset.claimId + '"]');
        const dsTitle = row && row.querySelector('.ds-title');
        if (dsTitle) dsTitle.textContent = name || 'Untitled claim';
    }

    function addItem(c, btn) {
        const err = q(c,'.cc-item-error');
        const desc = q(c,'.cc-i-desc'), cat = q(c,'.cc-i-cat'), amount = q(c,'.cc-i-amount'), gst = q(c,'.cc-i-gst'), date = q(c,'.cc-i-date'), file = q(c,'.cc-i-file');
        err.classList.add('d-none');
        if (!desc.value.trim()) return showErr(err, 'Enter the expense description.');
        if (!date.value) return showErr(err, 'Choose the date of expense.');
        if (!cat.value) return showErr(err, 'Choose a category.');
        const opt = cat.selectedOptions[0];
        const isMileage = opt && opt.dataset.glCode === MILEAGE_GL;
        const fixed = opt && opt.dataset.rateType === 'fixed';
        if (isMileage) {
            if (!(parseFloat(q(c,'.cc-i-km').value) > 0)) return showErr(err, 'Enter the distance (km) for the mileage claim.');
        } else if (!fixed && (!amount.value || parseFloat(amount.value) <= 0)) {
            return showErr(err, 'Enter the amount.');
        }

        // Hard stop: never let the claimed amount exceed the receipt total the scan read.
        // applyReceiptCheck() shows the red banner and returns true only for an over-claim
        // (under-claims pass through as an allowed soft warning).
        if (applyReceiptCheck(c)) {
            const rc = q(c, '.cc-receipt-check'); if (rc) rc.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            const receiptTotal = parseFloat(q(c, '.cc-c-total').value);
            const fmt = n => Number(n).toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            showErr(err, 'Can’t add — the amount is more than the receipt' + (receiptTotal > 0 ? ' (RM ' + fmt(receiptTotal) + ')' : '') + '. Lower it to the receipt total or less, then click Add to list.');
            amount.focus();
            return;
        }

        const editingId = c.dataset.editingItem;
        // Editing forces a fresh receipt upload — the previous attachment is replaced.
        if (editingId && !file.files.length) {
            return showErr(err, 'Re-upload the receipt to save your changes — the previous attachment will be replaced.');
        }
        const fd = new FormData();
        fd.append('expense_category_id', cat.value);
        fd.append('description', desc.value.trim());
        fd.append('expense_date', date.value);
        fd.append('amount', amount.value || '');
        fd.append('gst_amount', gst.value || '0');
        // Category C — read-only OCR receipt details (empty when no scan).
        fd.append('c_company', q(c,'.cc-c-company').value || '');
        fd.append('c_itemdesc', q(c,'.cc-c-itemdesc').value || '');
        fd.append('c_date', q(c,'.cc-c-date').value || '');
        fd.append('c_paidby', q(c,'.cc-c-paidby').value || '');
        fd.append('c_total', q(c,'.cc-c-total').value || '');
        fd.append('c_calc', q(c,'.cc-c-calc').value || '');
        if (isMileage) { fd.append('c_km', q(c,'.cc-i-km').value || ''); fd.append('c_vehicle', q(c,'.cc-i-vehicle').value || 'car'); }
        if (file.files.length) fd.append('receipt', file.files[0]);
        // Optional supporting documents (multiple, not scanned).
        const support = q(c,'.cc-i-support');
        if (support && support.files.length) { Array.from(support.files).forEach(f => fd.append('support_files[]', f)); }
        const url = editingId
            ? (REMOVE_BASE + '/' + editingId + '/inline-update')
            : (ADDITEM_BASE + '/' + c.dataset.claimId + '/inline-item');
        btn.disabled = true;
        // Save Category B first so the item inherits the current event/project/approver.
        saveCatB(c).then(saved => {
            if (!saved) { btn.disabled = false; return showErr(err, 'Check the claim details (event, approver, project) — they could not be saved.'); }
            updateHeaderTitle(c);
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd })
                .then(r => r.json()).then(d => {
                    btn.disabled = false;
                    if (!d.ok) return showErr(err, d.message || Object.values(d.errors || {})[0] || 'Could not save the item.');
                    if (editingId) {
                        const row = c.querySelector('[data-item-row="' + editingId + '"]');
                        if (row) fillRow(row, d.item);
                        setEditMode(c, false);
                    } else {
                        appendRow(c, d.item);
                    }
                    updateTotals(c, d.claim_total, d.item_count);
                    if (d.cap_note) { const h = q(c,'.cc-scan-hint'); if (h) h.textContent = d.cap_note; }
                    resetEntry(c);
                })
                .catch(() => { btn.disabled = false; showErr(err, 'Could not save the item — try again.'); });
        });
    }

    // ── Multi-receipt review (one image split into many lines, or several files) ──
    let reviewCard = null, reviewFiles = [];
    function openMultiReview(c, items, files, truncated) {
        reviewCard = c;
        reviewFiles = Array.isArray(files) ? files : (files ? [files] : []);
        const body = document.getElementById('multiReviewBody');
        const sel = q(c, '.cc-i-cat');
        const optsHtml = sel ? sel.innerHTML : '<option value="">-- Select --</option>';
        const today = new Date().toISOString().slice(0, 10);
        // If ANY row was highlighted, the user singled those out → pre-tick ONLY those.
        // Otherwise pre-tick everything except auto-detected non-claimable rows (reloads/fees).
        const anyHighlighted = items.some(it => it && it.highlighted);
        body.innerHTML = '';
        items.forEach(function (it, i) {
            const tr = document.createElement('tr');
            tr.setAttribute('data-mr-row', i);
            // Which uploaded file backs this line (multi-file: each row keeps its source).
            tr.dataset.mrFile = (it.file_index !== null && it.file_index !== undefined) ? it.file_index : 0;
            // Stash the AI-read receipt details so they save as Category C on add.
            tr.dataset.cCompany = it.vendor || '';
            tr.dataset.cItemdesc = it.item_description || '';
            tr.dataset.cDate = it.date || '';
            tr.dataset.cPaidby = it.paid_by || '';
            tr.dataset.cTotal = (it.amount !== null && it.amount !== undefined) ? it.amount : '';
            // Pre-fill the Expense Description with the read Item — for a toll row this is the
            // Entry → Exit route (e.g. "DUKE-BATU → 07_AKLEH"). The user can still edit it.
            const desc = it.item_description || '';
            const dateVal = it.date || today;
            const amt = (it.amount !== null && it.amount !== undefined) ? Number(it.amount).toFixed(2) : '';
            // Default tick: highlighted-only when any highlight exists; else on unless non-claimable.
            // A highlight always wins over the non-claimable default (the user marked it on purpose).
            const checked = anyHighlighted ? !!it.highlighted : !it.non_claimable;
            const badges =
                (it.highlighted ? '<span class="badge bg-warning text-dark me-1"><i class="bi bi-stars"></i> highlighted</span>' : '') +
                (it.non_claimable ? '<span class="badge bg-secondary"><i class="bi bi-slash-circle"></i> ' + escHtml(it.transaction_type || 'not a claim') + '?</span>' : '');
            // Extra details read from the attachment (Company / payer), shown so the user can verify.
            const extraBits = [];
            if (it.vendor) extraBits.push('Company: ' + escHtml(it.vendor));
            if (it.paid_by) extraBits.push('Paid by: ' + escHtml(it.paid_by));
            const extraLine = extraBits.length ? '<div class="small text-muted mt-1"><i class="bi bi-receipt me-1"></i>' + extraBits.join(' · ') + '</div>' : '';
            // All four detail fields are EDITABLE — the OCR pre-fills them; the user can fix
            // anything before ticking the rows to add.
            tr.innerHTML =
                '<td><input type="checkbox" class="form-check-input mr-pick"' + (checked ? ' checked' : '') + '></td>' +
                '<td><input type="date" class="form-control form-control-sm mr-date" value="' + escHtml(dateVal) + '"></td>' +
                '<td><input type="text" class="form-control form-control-sm mr-desc" value="' + escHtml(desc) + '" placeholder="Describe this expense">' +
                    (badges ? '<div class="mt-1">' + badges + '</div>' : '') + extraLine + '</td>' +
                '<td><select class="form-select form-select-sm mr-cat">' + optsHtml + '</select></td>' +
                '<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end mr-amt" value="' + escHtml(amt) + '"></td>';
            body.appendChild(tr);
            // Expense Type IS auto-captured from the scan (the Expense Description is the
            // field the user keys in manually).
            if (it.category_id) tr.querySelector('.mr-cat').value = String(it.category_id);
        });
        // Legend explains the pre-selection so it isn't a surprise.
        const legend = document.getElementById('mrLegend');
        if (legend) {
            const pick = anyHighlighted
                ? 'We pre-selected the <span class="badge bg-warning text-dark">highlighted</span> rows. Tick others if you also want them.'
                : 'All rows are selected except <span class="badge bg-secondary">reload / top-up / fee</span> lines. Adjust as needed.';
            legend.innerHTML = '<i class="bi bi-info-circle me-1"></i>' + pick + ' Details are pre-filled from the attachment — <strong>edit anything that\'s wrong</strong>, then tick the rows to add.';
        }
        // Truncation warning — never drop rows silently.
        const trunc = document.getElementById('mrTrunc');
        if (trunc) {
            trunc.classList.toggle('d-none', !truncated);
            trunc.classList.toggle('d-flex', !!truncated);
            const tm = document.getElementById('mrTruncMsg');
            if (tm && truncated) tm.textContent = 'This statement is long — only the first ' + items.length + ' rows were read. If some are missing, upload the pages separately or screenshot just the rows you’re claiming.';
        }
        const all = document.getElementById('mrCheckAll');
        if (all) all.checked = Array.from(body.querySelectorAll('.mr-pick')).every(cb => cb.checked);
        document.getElementById('mrStatus').textContent = '';
        const m = document.getElementById('multiReviewModal');
        if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).show();
    }
    function markReviewRow(tr, ok, msg) {
        tr.classList.toggle('table-success', ok);
        tr.classList.toggle('table-danger', !ok);
        tr.title = ok ? '' : (msg || '');
        if (ok) { tr.dataset.mrAdded = '1'; const p = tr.querySelector('.mr-pick'); if (p) p.checked = false; }
    }
    function addAllReviewed() {
        if (!reviewCard) return;
        const c = reviewCard;
        const rows = Array.from(document.querySelectorAll('#multiReviewBody [data-mr-row]'))
            .filter(tr => tr.querySelector('.mr-pick') && tr.querySelector('.mr-pick').checked);
        const status = document.getElementById('mrStatus');
        if (!rows.length) { status.textContent = 'Tick at least one line to add.'; return; }
        const btn = document.getElementById('mrAddAll'); btn.disabled = true;
        status.textContent = 'Saving…';
        const addUrl = ADDITEM_BASE + '/' + c.dataset.claimId + '/inline-item';
        let added = 0, failed = 0, lastTotal = null, lastCount = null;
        // Persist Category B once so every line inherits event/project/approver.
        saveCatB(c).then(function (saved) {
            if (!saved) { btn.disabled = false; status.textContent = 'Couldn’t save the claim details (event/approver) — fix them, then retry.'; return; }
            // Sequential adds keep ordering and don’t hammer the server.
            const seq = rows.reduce((p, tr) => p.then(function () {
                const cat = tr.querySelector('.mr-cat').value;
                const desc = tr.querySelector('.mr-desc').value.trim();
                const date = tr.querySelector('.mr-date').value;
                const amt = tr.querySelector('.mr-amt').value;
                if (!cat || !desc || !date) { failed++; markReviewRow(tr, false, 'Fill in the date, description and category.'); return; }
                const fd = new FormData();
                fd.append('expense_category_id', cat);
                fd.append('description', desc);
                fd.append('expense_date', date);
                fd.append('amount', amt || '');
                fd.append('gst_amount', '0');
                fd.append('c_company', tr.dataset.cCompany || '');
                fd.append('c_itemdesc', tr.dataset.cItemdesc || '');
                fd.append('c_date', tr.dataset.cDate || '');
                fd.append('c_paidby', tr.dataset.cPaidby || '');
                fd.append('c_total', tr.dataset.cTotal || '');
                fd.append('c_calc', '');
                fd.append('batch', '1'); // a shared image may back several lines — skip dedup
                const f = reviewFiles[parseInt(tr.dataset.mrFile || '0', 10)] || reviewFiles[0];
                if (f) fd.append('receipt', f);
                return fetch(addUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd })
                    .then(r => r.json()).then(function (d) {
                        if (!d.ok) { failed++; markReviewRow(tr, false, (Object.values(d.errors || {})[0]) || d.message || 'Could not add.'); return; }
                        added++; lastTotal = d.claim_total; lastCount = d.item_count;
                        appendRow(c, d.item); markReviewRow(tr, true);
                    })
                    .catch(function () { failed++; markReviewRow(tr, false, 'Network error.'); });
            }), Promise.resolve());
            seq.then(function () {
                if (lastTotal !== null) updateTotals(c, lastTotal, lastCount);
                btn.disabled = false;
                status.textContent = added + ' added' + (failed ? (', ' + failed + ' still need fixing (red rows).') : '.');
                if (added) showToast(added + ' item' + (added === 1 ? '' : 's') + ' added', 'success');
                if (!failed) {
                    const m = document.getElementById('multiReviewModal');
                    if (m && window.bootstrap) bootstrap.Modal.getOrCreateInstance(m).hide();
                    resetEntry(c);
                } else {
                    // Drop the rows that succeeded so a retry only re-adds the failures.
                    Array.from(document.querySelectorAll('#multiReviewBody [data-mr-added="1"]')).forEach(tr => tr.remove());
                }
            });
        });
    }
    document.getElementById('mrAddAll')?.addEventListener('click', addAllReviewed);
    document.getElementById('mrCheckAll')?.addEventListener('change', function () {
        const on = this.checked;
        document.querySelectorAll('#multiReviewBody .mr-pick').forEach(cb => { cb.checked = on; });
    });

    // Open the styled delete-item modal, listing the exact item(s) to be removed.
    let pendingDeleteItem = null;
    function removeItem(c, btn) {
        const id = btn.dataset.itemId;
        const row = c.querySelector('[data-item-row="' + id + '"]');
        const hash = (row && row.dataset.receiptHash) ? row.dataset.receiptHash : '';
        // Items read from ONE attachment share a receipt hash → deleting one deletes them all.
        const group = hash ? Array.from(c.querySelectorAll('[data-item-row][data-receipt-hash="' + hash + '"]')) : (row ? [row] : []);
        const n = group.length || 1;
        const items = group.map(function (r) {
            return {
                date: (r.querySelector('td:nth-child(1)')?.textContent || '').trim(),
                desc: (r.querySelector('td:nth-child(2)')?.textContent || r.dataset.desc || 'Item').trim(),
                total: (r.querySelector('td:nth-child(6)')?.textContent || '').trim(),
            };
        });
        const dm = document.getElementById('deleteItemModal');
        if (!dm || !window.bootstrap) { // fallback
            if (confirm('Delete ' + n + ' item(s)? To change an item, delete and add it again.')) doRemoveItem(c, id);
            return;
        }
        document.getElementById('deleteItemTitle').innerHTML = '<i class="bi bi-trash me-2 text-danger"></i>' +
            (n > 1 ? 'Delete ' + n + ' items from this receipt?' : 'Delete this item?');
        document.getElementById('deleteItemLead').textContent =
            'By choosing to delete the receipt, you will delete all the items that are attached to this receipt. To recapture the items, you have to reupload the receipt.';
        const list = document.getElementById('deleteItemList');
        list.innerHTML = '';
        items.forEach(function (it) {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-start gap-2';
            const left = document.createElement('span');
            left.innerHTML = '<span class="text-muted small me-1">' + escHtml(it.date) + '</span>' + escHtml(it.desc);
            const right = document.createElement('span');
            right.className = 'fw-semibold text-nowrap';
            right.textContent = it.total;
            li.appendChild(left); li.appendChild(right);
            list.appendChild(li);
        });
        pendingDeleteItem = { c: c, id: id };
        bootstrap.Modal.getOrCreateInstance(dm).show();
    }
    // Actually delete (whole attachment group); strip every removed row + update totals.
    function doRemoveItem(c, id) {
        const fd = new FormData(); fd.append('_method', 'DELETE');
        fetch(REMOVE_BASE + '/' + id + '/inline-remove', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd })
            .then(r => r.json()).then(d => {
                if (!d.ok) { alert(d.message || 'Could not remove the item.'); return; }
                (Array.isArray(d.removed_ids) && d.removed_ids.length ? d.removed_ids : [id]).forEach(function (rid) {
                    const r = c.querySelector('[data-item-row="' + rid + '"]'); if (r) r.remove();
                });
                updateTotals(c, d.claim_total, d.item_count);
                if ((parseInt(d.item_count, 10) || 0) === 0) {
                    const body = q(c,'.cc-items-body');
                    const tr = document.createElement('tr'); tr.className = 'cc-empty-row';
                    tr.innerHTML = '<td colspan="8" class="text-center text-muted py-3">No items yet.</td>';
                    body.appendChild(tr);
                }
            })
            .catch(() => { alert('Could not remove — try again.'); });
    }
    (function () {
        const ok = document.getElementById('deleteItemOk');
        if (!ok) return;
        ok.addEventListener('click', function () {
            const p = pendingDeleteItem; pendingDeleteItem = null;
            const dm = document.getElementById('deleteItemModal');
            if (dm && window.bootstrap) bootstrap.Modal.getOrCreateInstance(dm).hide();
            if (p) doRemoveItem(p.c, p.id);
        });
    })();

    // Delegated events ──────────────────────────────────────────────
    document.addEventListener('input', function (e) {
        if (e.target.matches('.cc-i-amount, .cc-i-gst')) { const c = cardOf(e.target); if (c) { syncTotal(c); const er = q(c,'.cc-item-error'); if (er) er.classList.add('d-none'); } }
        if (e.target.matches('.cc-i-km')) { const c = cardOf(e.target); if (c) computeMileage(c); }
        if (e.target.matches('.cc-appr-search')) { const c = cardOf(e.target); if (c) { q(c,'.cc-appr-id').value = ''; filterAppr(c, e.target.value); q(c,'.cc-appr-list').classList.remove('d-none'); } }
        // Auto-save the claim header (event / project) as the user types.
        if (e.target.matches('.cc-details [name="event"], .cc-details [name="project_client"]')) { const c = cardOf(e.target); if (c) autoSaveCatB(c); }
    });
    document.addEventListener('focusin', function (e) {
        if (e.target.matches('.cc-appr-search')) { const c = cardOf(e.target); if (c) { filterAppr(c, e.target.value); q(c,'.cc-appr-list').classList.remove('d-none'); } }
    });
    // Fool-proofing: the claim header auto-saves and added items are already in the database,
    // so this only warns when leaving with an UNFINISHED item row (typed but not "Add to list").
    window.addEventListener('beforeunload', function (e) {
        const dirty = Array.from(document.querySelectorAll('[data-claim-card]')).some(function (c) {
            if (c.dataset.editingItem) return true;
            const desc = q(c,'.cc-i-desc'), file = q(c,'.cc-i-file');
            return (desc && desc.value.trim() !== '') || (file && file.files && file.files.length > 0);
        });
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
    document.addEventListener('change', function (e) {
        // Auto-save the claim header when the event date changes.
        if (e.target.matches('.cc-details [name="event_date"]')) { const c = cardOf(e.target); if (c) autoSaveCatB(c); }
        // Approver-company switch (cross-company events): drop a now-out-of-company approver
        // and re-open the filtered list for the newly chosen company.
        if (e.target.matches('.cc-appr-company')) {
            const c = cardOf(e.target); if (!c) return;
            const id = q(c,'.cc-appr-id').value;
            const cur = id ? c.querySelector('.cc-appr-opt[data-id="' + id + '"]') : null;
            if (cur && cur.dataset.company !== e.target.value) { q(c,'.cc-appr-id').value = ''; q(c,'.cc-appr-search').value = ''; }
            filterAppr(c, q(c,'.cc-appr-search').value);
            const list = q(c,'.cc-appr-list'); if (list) list.classList.remove('d-none');
            return;
        }
        if (e.target.matches('.cc-i-cat')) {
            const c = cardOf(e.target); if (!c) return;
            const opt = e.target.selectedOptions[0];
            const amount = q(c,'.cc-i-amount'), gst = q(c,'.cc-i-gst');
            const isMileage = opt && opt.dataset.glCode === MILEAGE_GL;
            const mrow = c.querySelector('.cc-mileage-row');
            if (mrow) mrow.classList.toggle('d-none', !isMileage);
            if (isMileage) {
                // Mileage: amount comes from km × vehicle rate (choose the vehicle below).
                amount.readOnly = true; gst.value = '0'; gst.readOnly = true;
                computeMileage(c);
            } else if (opt && opt.dataset.rateType === 'fixed') {
                amount.value = parseFloat(opt.dataset.rateAmount || 0).toFixed(2); amount.readOnly = true;
                gst.value = '0'; gst.readOnly = true;
                const cw = c.querySelector('.cc-c-calc-wrap'); if (cw) cw.classList.add('d-none');
            } else {
                amount.readOnly = false; gst.readOnly = false;
                const cw = c.querySelector('.cc-c-calc-wrap'); if (cw) cw.classList.add('d-none');
            }
            syncTotal(c);
        }
        if (e.target.matches('.cc-i-vehicle')) { const c = cardOf(e.target); if (c) computeMileage(c); }
        if (e.target.matches('.cc-i-file')) {
            const c = cardOf(e.target); if (!c) return;
            const sb = q(c,'.cc-scan-btn'); if (sb) sb.classList.toggle('d-none', !e.target.files.length);
            const h = q(c,'.cc-scan-hint'); if (h) h.textContent = '';
        }
    });
    document.addEventListener('click', function (e) {
        const opt = e.target.closest('.cc-appr-opt');
        if (opt) { const c = cardOf(opt); q(c,'.cc-appr-id').value = opt.dataset.id; q(c,'.cc-appr-search').value = opt.dataset.name; q(c,'.cc-appr-list').classList.add('d-none'); autoSaveCatB(c); return; }
        if (!e.target.closest('.approver-combo')) document.querySelectorAll('.cc-appr-list').forEach(l => l.classList.add('d-none'));
        const calcDist = e.target.closest('.cc-calc-dist');
        if (calcDist) {
            const c = cardOf(calcDist);
            const route = (q(c,'.cc-c-itemdesc').value || '').split('→').map(s => s.trim()).filter(Boolean);
            calcRouteDistance(c, route, q(c,'.cc-mileage-note'));
            return;
        }
        const scanBtn = e.target.closest('.cc-scan-btn'); if (scanBtn) { scanItem(cardOf(scanBtn), scanBtn); return; }
        const addBtn = e.target.closest('.cc-add-item-btn'); if (addBtn) { addItem(cardOf(addBtn), addBtn); return; }
        const rmBtn = e.target.closest('.cc-remove-item'); if (rmBtn) { removeItem(cardOf(rmBtn), rmBtn); return; }
        const cancelEdit = e.target.closest('.cc-cancel-edit');
        if (cancelEdit) { const c = cardOf(cancelEdit); resetEntry(c); setEditMode(c, false); q(c,'.cc-item-error').classList.add('d-none'); return; }
        // Clear the whole item entry (for a different receipt).
        const clearBtn = e.target.closest('.cc-clear-entry');
        if (clearBtn) {
            const c = cardOf(clearBtn);
            resetEntry(c);
            const dt = q(c,'.cc-i-date'); if (dt) dt.value = dt.defaultValue; // back to the default date
            setEditMode(c, false);
            q(c,'.cc-item-error').classList.add('d-none');
            return;
        }

        // Submit — save Category B first, then post the (hidden) submit form.
        const submitBtn = e.target.closest('.cc-submit-claim');
        if (submitBtn) {
            const c = cardOf(submitBtn);
            // Pre-check required fields HERE (client-side) so a missing field shows a quick toast
            // instead of bouncing through the server and reloading/clearing the open claim form.
            const projInput = q(c,'.cc-details [name="project_client"]');
            if (PROJECT_REQUIRED && projInput && !projInput.value.trim()) {
                showToast('Enter the project / client name before submitting.', 'danger');
                projInput.classList.add('is-invalid'); projInput.focus();
                projInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            if (projInput) projInput.classList.remove('is-invalid');
            const apprId = q(c,'.cc-appr-id'), apprSearch = q(c,'.cc-appr-search');
            if (apprId && !apprId.value) {
                showToast('Choose an approving PIC / manager before submitting.', 'danger');
                if (apprSearch) { apprSearch.focus(); apprSearch.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                return;
            }
            // Styled reminder modal — double-check the event + approving manager before it locks.
            const event = (q(c,'.cc-details [name="event"]')?.value || '').trim() || '(unnamed)';
            const mgr = (apprSearch?.value || '').trim() || (submitBtn.dataset.approver || 'your approver');
            const sm = document.getElementById('submitClaimModal');
            if (sm && window.bootstrap) {
                document.getElementById('smEvent').textContent = event;
                document.getElementById('smManager').textContent = mgr;
                pendingSubmitCard = c;
                bootstrap.Modal.getOrCreateInstance(sm).show();
            } else {
                // Fallback if Bootstrap isn't available.
                if (confirm('Submit "' + event + '" to ' + mgr + '? You can’t edit it after.')) doSubmit(c);
            }
            return;
        }
        // Delete the whole draft — styled confirmation modal (Cancel / Proceed).
        const delBtn = e.target.closest('.cc-delete-claim');
        if (delBtn) {
            const c = cardOf(delBtn);
            const dm = document.getElementById('deleteClaimModal');
            if (dm && window.bootstrap) {
                pendingDeleteCard = c;
                bootstrap.Modal.getOrCreateInstance(dm).show();
            } else {
                // Fallback if Bootstrap isn't available.
                if (confirm('Delete this draft claim and all its items? This cannot be undone.')) c.querySelector('.cc-delete-form').submit();
            }
            return;
        }
    });

    // Auto-expand & scroll to the claim opened via ?open (and its month group).
    @if($openClaimId)
    (function () {
        // Strip ?open from the address bar so a REFRESH lands on the clean (empty) form —
        // the draft is auto-saved in the list and reopens via "Continue editing". The draft
        // stays loaded for THIS view; only a reload clears it.
        try { const u = new URL(window.location); u.searchParams.delete('open'); history.replaceState({}, '', u); } catch (e) {}
        const card = document.getElementById('claim-{{ $openClaimId }}');
        if (!card) return;
        // Expand the ancestor year then month so a deep-linked claim is actually visible.
        const yearGroup = card.closest('.year-group')?.querySelector(':scope > .collapse');
        if (yearGroup) yearGroup.classList.add('show');
        const monthGroup = card.closest('.month-group')?.querySelector(':scope > .collapse');
        if (monthGroup) monthGroup.classList.add('show');
        const own = document.getElementById('cc-{{ $openClaimId }}'); if (own) own.classList.add('show');
        setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'center' }), 150);
    })();
    @endif
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
