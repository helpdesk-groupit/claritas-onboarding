@extends('layouts.app')
@section('title', 'Attendance Records')
@section('page-title', 'Attendance Records')

@section('content')
<div class="card mb-4">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <select name="employee_id" class="form-select form-select-sm" style="width:200px">
                <option value="">All Employees</option>
                @foreach($employees as $emp)
                <option value="{{ $emp->id }}" {{ request('employee_id') == $emp->id ? 'selected' : '' }}>{{ $emp->full_name }}</option>
                @endforeach
            </select>
            <input type="date" name="date" class="form-control form-control-sm" style="width:160px" value="{{ request('date', now()->format('Y-m-d')) }}">
            <select name="view" class="form-select form-select-sm" style="width:120px">
                <option value="daily" {{ request('view','daily') == 'daily' ? 'selected' : '' }}>Daily</option>
                <option value="monthly" {{ request('view') == 'monthly' ? 'selected' : '' }}>Monthly</option>
            </select>
            <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Clock In</th>
                        <th>Clock Out</th>
                        <th class="text-center">Work Hours</th>
                        <th class="text-center">OT</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $rec)
                    <tr>
                        <td>{{ $rec->employee->full_name ?? '—' }}</td>
                        <td>{{ \Carbon\Carbon::parse($rec->date)->format('d M Y (D)') }}</td>
                        <td>{{ $rec->clock_in ? \Carbon\Carbon::parse($rec->clock_in)->format('h:i A') : '—' }}</td>
                        <td>{{ $rec->clock_out ? \Carbon\Carbon::parse($rec->clock_out)->format('h:i A') : '—' }}</td>
                        <td class="text-center">{{ $rec->work_hours ? number_format($rec->work_hours, 1) . 'h' : '—' }}</td>
                        <td class="text-center">{{ $rec->overtime_hours > 0 ? number_format($rec->overtime_hours, 1) . 'h' : '—' }}</td>
                        <td class="text-center">{!! $rec->statusBadge() !!}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No attendance records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if(method_exists($records, 'links'))
        <div class="mt-3">{{ $records->links() }}</div>
        @endif
    </div>
</div>
@endsection
