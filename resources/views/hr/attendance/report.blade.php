@extends('layouts.app')
@section('title', 'Attendance Report')
@section('page-title', 'Monthly Attendance Report')

@section('content')
<div class="card mb-4">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="month" class="form-select form-select-sm" style="width:140px">
                @for($m = 1; $m <= 12; $m++)
                <option value="{{ $m }}" {{ $month == $m ? 'selected' : '' }}>{{ \Carbon\Carbon::create(null, $m)->format('F') }}</option>
                @endfor
            </select>
            <select name="year" class="form-select form-select-sm" style="width:100px">
                @for($y = now()->year - 1; $y <= now()->year + 1; $y++)
                <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                @endfor
            </select>
            <button class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>View</button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th class="text-center">Present</th>
                        <th class="text-center">Late</th>
                        <th class="text-center">Absent</th>
                        <th class="text-center">On Leave</th>
                        <th class="text-center">Total Hours</th>
                        <th class="text-center">OT Hours</th>
                        <th class="text-center">Avg Hours/Day</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($report as $row)
                    <tr>
                        <td class="fw-bold">{{ $row['employee']->full_name }}</td>
                        <td class="text-center"><span class="badge bg-success">{{ $row['present'] }}</span></td>
                        <td class="text-center"><span class="badge bg-warning">{{ $row['late'] }}</span></td>
                        <td class="text-center"><span class="badge bg-danger">{{ $row['absent'] }}</span></td>
                        <td class="text-center"><span class="badge bg-info">{{ $row['on_leave'] }}</span></td>
                        <td class="text-center">{{ number_format($row['total_hours'], 1) }}h</td>
                        <td class="text-center">{{ number_format($row['ot_hours'], 1) }}h</td>
                        <td class="text-center">{{ $row['present'] > 0 ? number_format($row['total_hours'] / $row['present'], 1) : '0.0' }}h</td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No data for this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
