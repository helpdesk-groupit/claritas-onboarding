@extends('layouts.app')
@section('title', 'Overtime Requests')
@section('page-title', 'Overtime Requests')

@section('content')
<div class="card">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>Overtime Requests</h5></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Date</th>
                        <th>Time</th>
                        <th class="text-center">Hours</th>
                        <th class="text-center">Multiplier</th>
                        <th>Reason</th>
                        <th class="text-center">Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $req)
                    <tr>
                        <td>{{ $req->employee->full_name ?? '—' }}</td>
                        <td>{{ \Carbon\Carbon::parse($req->date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($req->start_time)->format('h:i A') }} — {{ \Carbon\Carbon::parse($req->end_time)->format('h:i A') }}</td>
                        <td class="text-center fw-bold">{{ number_format($req->hours, 1) }}</td>
                        <td class="text-center">{{ $req->multiplier }}x</td>
                        <td><small>{{ Str::limit($req->reason, 50) }}</small></td>
                        <td class="text-center">
                            <span class="badge bg-{{ $req->status === 'approved' ? 'success' : ($req->status === 'rejected' ? 'danger' : ($req->status === 'pending' ? 'warning' : 'secondary')) }}">
                                {{ ucfirst($req->status) }}
                            </span>
                        </td>
                        <td>
                            @if($req->status === 'pending')
                            <div class="d-flex gap-1">
                                <form method="POST" action="{{ route('hr.attendance.overtime.approve', $req) }}">@csrf<button class="btn btn-sm btn-success"><i class="bi bi-check"></i></button></form>
                                <form method="POST" action="{{ route('hr.attendance.overtime.reject', $req) }}">@csrf<button class="btn btn-sm btn-danger"><i class="bi bi-x"></i></button></form>
                            </div>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No overtime requests.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $requests->links() }}</div>
    </div>
</div>
@endsection
