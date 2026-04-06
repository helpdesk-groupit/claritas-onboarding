@extends('layouts.app')
@section('title', 'Work Schedules')
@section('page-title', 'Work Schedules')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Work Schedules</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addScheduleModal"><i class="bi bi-plus-lg me-1"></i>Add Schedule</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Work Hours</th>
                        <th>Break</th>
                        <th>Hours/Day</th>
                        <th>Working Days</th>
                        <th class="text-center">Default</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($schedules as $s)
                    <tr>
                        <td class="fw-bold">{{ $s->name }}</td>
                        <td>{{ \Carbon\Carbon::parse($s->start_time)->format('h:i A') }} — {{ \Carbon\Carbon::parse($s->end_time)->format('h:i A') }}</td>
                        <td>{{ $s->break_start ? \Carbon\Carbon::parse($s->break_start)->format('h:i A') . ' — ' . \Carbon\Carbon::parse($s->break_end)->format('h:i A') : '—' }}</td>
                        <td>{{ $s->work_hours_per_day }}h</td>
                        <td>
                            @php $days = json_decode($s->working_days, true) ?? []; @endphp
                            @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d)
                                <span class="badge bg-{{ in_array($i+1, $days) ? 'primary' : 'light text-muted' }} me-1">{{ $d }}</span>
                            @endforeach
                        </td>
                        <td class="text-center">{!! $s->is_default ? '<i class="bi bi-star-fill text-warning"></i>' : '' !!}</td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No schedules configured.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Schedule Modal -->
<div class="modal fade" id="addScheduleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('hr.attendance.schedules.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Add Work Schedule</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required placeholder="e.g. Standard Office Hours"></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Start Time</label><input type="time" name="start_time" class="form-control" required value="09:00"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">End Time</label><input type="time" name="end_time" class="form-control" required value="18:00"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Break Start</label><input type="time" name="break_start" class="form-control" value="13:00"></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Break End</label><input type="time" name="break_end" class="form-control" value="14:00"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Work Hours/Day</label><input type="number" name="work_hours_per_day" class="form-control" step="0.5" value="8" required></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Working Days</label><br>
                        @foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d)
                        <div class="form-check form-check-inline">
                            <input type="checkbox" name="working_days[]" value="{{ $i+1 }}" class="form-check-input" id="day{{ $i }}" {{ $i < 5 ? 'checked' : '' }}>
                            <label class="form-check-label" for="day{{ $i }}">{{ $d }}</label>
                        </div>
                        @endforeach
                    </div>
                    <div class="form-check"><input type="checkbox" name="is_default" value="1" class="form-check-input" id="isDefault"><label class="form-check-label" for="isDefault">Set as default schedule</label></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
            </div>
        </form>
    </div>
</div>
@endsection
