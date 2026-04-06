@extends('layouts.app')
@section('title', 'My Leave')
@section('page-title', 'My Leave')

@section('content')
{{-- ═══════════════════════════════════════════════════════════════════
     LEAVE DASHBOARD
     Live balance tracker with entitled → taken → pending → available
     ═══════════════════════════════════════════════════════════════════ --}}

{{-- Balance Breakdown per Leave Type --}}
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Leave Balance Tracker — {{ now()->year }}</h5></div>
    <div class="card-body">
        @if($balances->isEmpty())
            <div class="text-center text-muted py-4">
                <i class="bi bi-info-circle" style="font-size:2rem"></i>
                <p class="mt-2 mb-0">No leave balances have been initialised for {{ now()->year }}.<br>Please contact HR to set up your entitlements.</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Leave Type</th>
                        <th class="text-center">Entitled</th>
                        <th class="text-center">Carry Fwd</th>
                        <th class="text-center">Adjustment</th>
                        <th class="text-center text-danger">Taken</th>
                        <th class="text-center text-warning">Pending</th>
                        <th class="text-center text-success fw-bold">Available</th>
                        <th style="min-width:160px">Usage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($balances as $bal)
                    @php
                        $pending  = $pendingByType[$bal->leave_type_id] ?? 0;
                        $total    = (float) $bal->entitled + (float) $bal->carry_forward + (float) $bal->adjustment;
                        $taken    = (float) $bal->taken;
                        $avail    = $bal->available;
                        $usedPct  = $total > 0 ? min(round($taken / $total * 100, 1), 100) : 0;
                        $pendPct  = $total > 0 ? min(round($pending / $total * 100, 1), 100 - $usedPct) : 0;
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $bal->leaveType->name ?? '' }}</strong>
                            <span class="badge bg-light text-dark ms-1">{{ $bal->leaveType->code ?? '' }}</span>
                        </td>
                        <td class="text-center">{{ $bal->entitled }}</td>
                        <td class="text-center">{{ $bal->carry_forward > 0 ? $bal->carry_forward : '—' }}</td>
                        <td class="text-center">{{ $bal->adjustment != 0 ? ($bal->adjustment > 0 ? '+' . $bal->adjustment : $bal->adjustment) : '—' }}</td>
                        <td class="text-center text-danger fw-semibold">{{ $taken }}</td>
                        <td class="text-center">
                            @if($pending > 0)
                                <span class="text-warning fw-semibold">{{ $pending }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="fw-bold {{ $avail > 0 ? 'text-success' : 'text-danger' }}" style="font-size:1.1em">
                                {{ $avail }}
                            </span>
                        </td>
                        <td>
                            <div class="progress" style="height:18px;border-radius:9px;">
                                {{-- Taken (red/orange) --}}
                                <div class="progress-bar bg-{{ $usedPct > 80 ? 'danger' : ($usedPct > 50 ? 'warning' : 'primary') }}"
                                     style="width:{{ $usedPct }}%" title="Taken: {{ $taken }} days">
                                    @if($usedPct > 15){{ $usedPct }}%@endif
                                </div>
                                {{-- Pending (striped yellow) --}}
                                @if($pendPct > 0)
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning"
                                     style="width:{{ $pendPct }}%" title="Pending: {{ $pending }} days"></div>
                                @endif
                            </div>
                            <div class="d-flex justify-content-between mt-1" style="font-size:10px;">
                                <span class="text-muted">0</span>
                                <span class="text-muted">{{ $total }} days</span>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-3 mt-2" style="font-size:12px;">
            <span><span class="badge bg-primary">&nbsp;</span> Taken</span>
            <span><span class="badge bg-warning">&nbsp;</span> Pending Approval</span>
            <span class="text-muted">Remaining = Available</span>
        </div>
        @endif
    </div>
</div>

{{-- Upcoming Leave --}}
@if($upcomingLeave->isNotEmpty())
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Upcoming Leave (Next 30 Days)</h5></div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush">
            @foreach($upcomingLeave as $upcoming)
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-success me-2">{{ $upcoming->leaveType->code ?? '' }}</span>
                    <strong>{{ $upcoming->leaveType->name ?? '' }}</strong>
                    <span class="text-muted ms-2">{{ $upcoming->start_date->format('d M') }}{{ $upcoming->start_date->ne($upcoming->end_date) ? ' — ' . $upcoming->end_date->format('d M') : '' }}</span>
                </div>
                <span class="badge bg-light text-dark">{{ $upcoming->total_days }} day{{ $upcoming->total_days > 1 ? 's' : '' }}{{ $upcoming->is_half_day ? ' (½)' : '' }}</span>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif

<!-- Apply Leave -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-calendar-plus me-2"></i>Apply for Leave</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('user.leave.apply') }}" enctype="multipart/form-data">
            @csrf
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Leave Type</label>
                    <select name="leave_type_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($leaveTypes as $lt)
                        <option value="{{ $lt->id }}" {{ old('leave_type_id') == $lt->id ? 'selected' : '' }}>{{ $lt->name }} ({{ $lt->code }})</option>
                        @endforeach
                    </select>
                    @error('leave_type_id')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" required value="{{ old('start_date') }}">
                    @error('start_date')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" required value="{{ old('end_date') }}">
                    @error('end_date')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="is_half_day" value="1" class="form-check-input" id="halfDay" {{ old('is_half_day') ? 'checked' : '' }}>
                        <label class="form-check-label" for="halfDay">Half Day</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Half Day Period</label>
                    <select name="half_day_period" class="form-select">
                        <option value="">N/A</option>
                        <option value="morning" {{ old('half_day_period') == 'morning' ? 'selected' : '' }}>Morning</option>
                        <option value="afternoon" {{ old('half_day_period') == 'afternoon' ? 'selected' : '' }}>Afternoon</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Attachment</label>
                    <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Reason</label>
                <textarea name="reason" class="form-control" rows="2">{{ old('reason') }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit Application</button>
        </form>
    </div>
</div>

<!-- My Applications -->
<div class="card">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>My Applications</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Start</th>
                        <th>End</th>
                        <th class="text-center">Days</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($applications as $app)
                    <tr>
                        <td>{{ $app->leaveType->name ?? '—' }}</td>
                        <td>{{ \Carbon\Carbon::parse($app->start_date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($app->end_date)->format('d M Y') }}</td>
                        <td class="text-center">{{ $app->total_days }}{{ $app->is_half_day ? ' (½)' : '' }}</td>
                        <td class="text-center">{!! $app->statusBadge() !!}</td>
                        <td>
                            @if($app->status === 'pending')
                            <form method="POST" action="{{ route('user.leave.cancel', $app) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel this application?')"><i class="bi bi-x-lg"></i></button>
                            </form>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No leave applications.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $applications->links() }}</div>
    </div>
</div>
@endsection
