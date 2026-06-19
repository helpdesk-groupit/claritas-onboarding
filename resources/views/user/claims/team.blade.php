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

    {{-- ── Summary Stat Cards ── --}}
    @php
        $pendingCount  = $pendingClaims->count();
        $totalPending  = $pendingClaims->sum(fn ($c) => $c->items->where('approver_id', $employee->id)->sum('total_with_gst'));
        $staffPending  = $pendingClaims->pluck('employee_id')->unique()->count();
        $reviewedCount = $historyClaims->count();
    @endphp
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="widget-number">{{ $pendingCount }}</div>
                            <div class="widget-label">Pending Approval</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#3b82f6,#1d4ed8);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-cash-stack"></i></div>
                        <div>
                            <div class="widget-number" style="font-size:22px;">RM {{ number_format($totalPending, 2) }}</div>
                            <div class="widget-label">Total Pending</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-person-badge"></i></div>
                        <div>
                            <div class="widget-number">{{ $staffPending }}</div>
                            <div class="widget-label">Staff Awaiting</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card dash-widget h-100" style="min-height:auto;">
                <div class="widget-header" style="background:linear-gradient(135deg,#22c55e,#15803d);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="widget-icon"><i class="bi bi-clock-history"></i></div>
                        <div>
                            <div class="widget-number">{{ $reviewedCount }}</div>
                            <div class="widget-label">Reviewed</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Pending Claims ── --}}
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-header bg-white border-0 d-flex align-items-center">
            <h5 class="mb-0"><i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Approval</h5>
            @if($pendingCount > 0)
            <span class="badge rounded-pill bg-warning text-dark ms-2">{{ $pendingCount }}</span>
            @endif
        </div>
        <div class="card-body @if($pendingCount > 0) pt-0 @endif">
            @forelse($pendingClaims as $claim)
            @php
                $empName = $claim->employee->full_name ?? '—';
                $initials = collect(explode(' ', trim($empName)))->filter()->take(2)->map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
            @endphp
            @php
                $myItems  = $claim->items->where('approver_id', $employee->id)->values();
                $company  = \App\Models\Company::forName($claim->employee->company);
                $myAmount = $myItems->sum('amount');
                $myGst    = $myItems->sum('gst_amount');
                $myTotal  = $myItems->sum('total_with_gst');
                $otherCount = $claim->items->count() - $myItems->count();
            @endphp
            <div class="border rounded-3 p-2 p-md-3 mb-4 bg-light shadow-sm">
                {{-- Toolbar: meta + open the printable report for this manager's portion --}}
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 px-1">
                    <div class="small text-muted">
                        <span class="fw-semibold">{{ $claim->claim_number }}</span> &middot;
                        <i class="bi bi-send-check me-1"></i>Submitted {{ $claim->submitted_at?->format('d M Y') }}
                        @if($otherCount > 0)
                        &middot; <span class="badge bg-info-subtle text-info-emphasis">Showing the {{ $myItems->count() }} item(s) routed to you ({{ $otherCount }} more go to other managers)</span>
                        @endif
                    </div>
                    <a href="{{ route('user.claims.report-print', $claim) }}?approver={{ $employee->id }}" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Open / Print report</a>
                </div>

                {{-- The Expenses Claims Form (same layout the employee's Claim Reports prints) --}}
                <div class="bg-white p-3 p-md-4 rounded" style="max-width:1000px;margin:0 auto;">
                    @include('partials.claim-letterhead', [
                        'company' => $company,
                        'employee' => $claim->employee,
                        'event' => $claim->event,
                        'showRules' => true,
                        'claimDate' => $claim->submitted_at ?? \Carbon\Carbon::create($claim->year, $claim->month, 1),
                    ])

                    @include('partials.claim-review-summary', ['claim' => $claim])

                    <table class="table table-bordered align-middle" style="font-size:.78rem;">
                        <thead class="text-center">
                            <tr>
                                <th>Date</th>
                                <th>Expense Description</th>
                                <th>Project/Client Name</th>
                                <th>Expense Type</th>
                                <th>RM<br>(w/o GST)</th>
                                <th>RM<br>(GST)</th>
                                <th>Total<br>(w/ GST)</th>
                                <th>Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($myItems as $item)
                            <tr>
                                <td class="text-nowrap">{{ $item->expense_date->format('jS M Y') }}</td>
                                <td>{{ $item->description }}
                                    @include('partials.claim-item-checks', ['item' => $item])
                                </td>
                                <td>{{ $item->project_client ?: 'N/A' }}</td>
                                <td>{{ $item->category->gl_code ? $item->category->gl_code.': ' : '' }}{{ strtoupper($item->category->name ?? '') }}</td>
                                <td class="text-end">RM{{ number_format($item->amount, 2) }}</td>
                                <td class="text-end">{{ $item->gst_amount > 0 ? 'RM'.number_format($item->gst_amount, 2) : '-' }}</td>
                                <td class="text-end">RM{{ number_format($item->total_with_gst, 2) }}</td>
                                <td class="text-center">
                                    @if($item->receipt_path)
                                    <a href="{{ route('user.claims.items.receipt', $item) }}" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-1" title="View receipt"><i class="bi bi-paperclip"></i></a>
                                    @else
                                    <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                            @for($r = $myItems->count(); $r < 8; $r++)
                            <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td class="text-end">-</td><td></td></tr>
                            @endfor
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold text-end">
                                <td colspan="4"></td>
                                <td>{{ number_format($myAmount, 2) }}</td>
                                <td>{{ number_format($myGst, 2) }}</td>
                                <td>{{ number_format($myTotal, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>

                    <div class="row mt-4">
                        <div class="col-6">
                            <div>Staff :- {{ $claim->employee->full_name }}</div>
                            <div class="mt-4 pt-3 border-top" style="width:75%;">Signature / Date :</div>
                            <div class="mt-4">Approving Manager :- {{ $employee->full_name }}</div>
                            <div class="mt-4 pt-3 border-top" style="width:75%;">Signature / Date :</div>
                        </div>
                        <div class="col-6">
                            <div>Checked by / Date :-</div>
                            <div class="text-muted small">(HR/Finance)</div>
                            <div class="mt-5 pt-3 border-top" style="width:75%;">Date :</div>
                        </div>
                    </div>
                </div>

                @if($otherCount > 0)
                <div class="small text-muted mt-2 px-1"><i class="bi bi-people me-1"></i>This claim is also routed to other managers — overall progress: {{ $claim->managerProgress() }} items approved. It goes to HR once every manager has approved.</div>
                @endif

                <div class="d-flex gap-2 justify-content-end flex-wrap mt-3 px-1">
                    <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#reject-{{ $claim->id }}">
                        <i class="bi bi-x-octagon me-1"></i>Reject Claim
                    </button>
                    <form action="{{ route('user.claims.team.approve', $claim) }}" method="POST" class="js-confirm d-inline"
                          data-confirm="Approve your {{ $myItems->count() }} item(s)? Once every manager has approved, the claim goes to HR."
                          data-confirm-title="Approve your items" data-confirm-ok="Approve" data-confirm-variant="success">
                        @csrf
                        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Approve My Items</button>
                    </form>
                </div>

                {{-- Reject the WHOLE claim — one wrong item sends the entire claim back to the employee --}}
                <div class="collapse mt-2 px-1" id="reject-{{ $claim->id }}">
                    <form action="{{ route('user.claims.team.reject', $claim) }}" method="POST">
                        @csrf
                        <label class="form-label small text-danger mb-1"><i class="bi bi-exclamation-circle me-1"></i>Rejecting returns the <strong>whole claim</strong> to {{ $empName }} to fix and resubmit — reason (the employee will see this)</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="remarks" class="form-control" placeholder="e.g., The KLCC mileage isn't a business trip — please remove it and resubmit." required maxlength="1000">
                            <button class="btn btn-danger text-nowrap"><i class="bi bi-x-circle me-1"></i>Confirm Reject (whole claim)</button>
                        </div>
                    </form>
                </div>
            </div>
            @empty
            <div class="text-center text-muted py-5">
                <i class="bi bi-check2-circle text-success" style="font-size:2.5rem;"></i>
                <p class="mt-2 mb-0">All caught up — no pending claims to review.</p>
            </div>
            @endforelse
        </div>
    </div>

    {{-- ── Approval History — categorized by status ── --}}
    @if($reviewedCount > 0)
    @php
        $history    = $historyClaims->sortByDesc('updated_at')->values();
        $histApprov = $history->whereIn('status', ['manager_approved', 'hr_approved', 'paid']);
        $histReject = $history->whereIn('status', ['manager_rejected', 'hr_rejected']);
    @endphp
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0 d-flex align-items-center">
            <h5 class="mb-0"><i class="bi bi-clock-history me-2 text-muted"></i>Approval History</h5>
        </div>
        <div class="card-body">
            <ul class="nav nav-pills flex-wrap gap-1 mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#th-all" type="button" role="tab">All <span class="badge bg-secondary ms-1">{{ $history->count() }}</span></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#th-approved" type="button" role="tab">Approved <span class="badge bg-success ms-1">{{ $histApprov->count() }}</span></button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#th-rejected" type="button" role="tab">Rejected <span class="badge bg-danger ms-1">{{ $histReject->count() }}</span></button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="th-all" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $history, 'showEmployee' => true, 'emptyText' => 'No reviewed claims yet.'])
                </div>
                <div class="tab-pane fade" id="th-approved" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $histApprov, 'showEmployee' => true, 'emptyText' => 'No approved claims yet.'])
                </div>
                <div class="tab-pane fade" id="th-rejected" role="tabpanel">
                    @include('partials.claims-status-table', ['rows' => $histReject, 'showEmployee' => true, 'emptyText' => 'No rejected claims.'])
                </div>
            </div>
        </div>
    </div>
    @endif
</div>

@include('partials.confirm-modal')
@include('partials.item-verify-js')
@endsection
