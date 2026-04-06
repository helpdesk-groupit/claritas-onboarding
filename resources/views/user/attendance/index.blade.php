@extends('layouts.app')
@section('title', 'My Attendance')
@section('page-title', 'My Attendance')

@section('content')
<!-- Clock In/Out Card -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-4 text-center">
                <h4 class="mb-1" id="liveClock">{{ now()->format('h:i:s A') }}</h4>
                <p class="text-muted mb-0">{{ now()->format('l, d F Y') }}</p>
            </div>
            <div class="col-md-4 text-center">
                @if($todayRecord && $todayRecord->clock_in && !$todayRecord->clock_out)
                    <p class="text-muted mb-1">Clocked in at <strong>{{ \Carbon\Carbon::parse($todayRecord->clock_in)->format('h:i A') }}</strong></p>
                    <form method="POST" action="{{ route('user.attendance.clock-out') }}">
                        @csrf
                        <button class="btn btn-lg btn-danger"><i class="bi bi-box-arrow-right me-2"></i>Clock Out</button>
                    </form>
                @elseif($todayRecord && $todayRecord->clock_out)
                    <p class="text-success mb-1"><i class="bi bi-check-circle me-1"></i>Completed for today</p>
                    <p class="text-muted mb-0">
                        {{ \Carbon\Carbon::parse($todayRecord->clock_in)->format('h:i A') }} — {{ \Carbon\Carbon::parse($todayRecord->clock_out)->format('h:i A') }}
                        ({{ number_format($todayRecord->work_hours, 1) }}h)
                    </p>
                @else
                    <form method="POST" action="{{ route('user.attendance.clock-in') }}">
                        @csrf
                        <button class="btn btn-lg btn-success"><i class="bi bi-box-arrow-in-right me-2"></i>Clock In</button>
                    </form>
                @endif
            </div>
            <div class="col-md-4 text-center">
                <div class="text-muted small">This Month</div>
                <h5 class="mb-0">{{ number_format($monthlyHours, 1) }}h</h5>
                <small class="text-muted">{{ $presentDays }} days present</small>
            </div>
        </div>
    </div>
</div>

<!-- Recent Records -->
<div class="card mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Attendance</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th class="text-center">Hours</th>
                        <th class="text-center">OT</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $rec)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($rec->date)->format('d M Y (D)') }}</td>
                        <td>{{ $rec->clock_in ? \Carbon\Carbon::parse($rec->clock_in)->format('h:i A') : '—' }}</td>
                        <td>{{ $rec->clock_out ? \Carbon\Carbon::parse($rec->clock_out)->format('h:i A') : '—' }}</td>
                        <td class="text-center">{{ $rec->work_hours ? number_format($rec->work_hours, 1) . 'h' : '—' }}</td>
                        <td class="text-center">{{ $rec->overtime_hours > 0 ? number_format($rec->overtime_hours, 1) . 'h' : '—' }}</td>
                        <td class="text-center">{!! $rec->statusBadge() !!}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if(method_exists($records, 'links'))
        <div class="mt-3">{{ $records->links() }}</div>
        @endif
    </div>
</div>

<!-- Overtime Request -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>Overtime Requests</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#otModal"><i class="bi bi-plus-lg me-1"></i>Request OT</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Date</th><th>Time</th><th class="text-center">Hours</th><th>Reason</th><th class="text-center">Status</th></tr>
                </thead>
                <tbody>
                    @forelse($overtimeRequests as $ot)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($ot->date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($ot->start_time)->format('h:i A') }} — {{ \Carbon\Carbon::parse($ot->end_time)->format('h:i A') }}</td>
                        <td class="text-center">{{ number_format($ot->hours, 1) }}</td>
                        <td><small>{{ Str::limit($ot->reason, 40) }}</small></td>
                        <td class="text-center"><span class="badge bg-{{ $ot->status === 'approved' ? 'success' : ($ot->status === 'rejected' ? 'danger' : 'warning') }}">{{ ucfirst($ot->status) }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">No overtime requests.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- OT Modal -->
<div class="modal fade" id="otModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('user.attendance.overtime') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Request Overtime</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Date</label><input type="date" name="date" class="form-control" required value="{{ now()->format('Y-m-d') }}"></div>
                    <div class="row">
                        <div class="col-6 mb-3"><label class="form-label">Start Time</label><input type="time" name="start_time" class="form-control" required value="18:00"></div>
                        <div class="col-6 mb-3"><label class="form-label">End Time</label><input type="time" name="end_time" class="form-control" required value="20:00"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="2" required></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Submit</button></div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
setInterval(() => {
    const el = document.getElementById('liveClock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit',second:'2-digit'});
}, 1000);
</script>
@endpush
@endsection
