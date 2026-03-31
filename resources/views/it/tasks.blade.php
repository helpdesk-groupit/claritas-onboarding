@extends('layouts.app')
@section('title', 'Task Management')
@section('page-title', 'Task Management')

@section('content')

<div class="card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-list-task me-2 text-primary"></i>My Assigned Tasks</h6>
        <div class="d-flex gap-2">
            <span class="badge bg-secondary">{{ $tasks->where('status','pending')->count() }} Pending</span>
            <span class="badge bg-warning text-dark">{{ $tasks->where('status','in_progress')->count() }} In Progress</span>
            <span class="badge bg-success">{{ $tasks->where('status','done')->count() }} Done</span>
        </div>
    </div>

    @if($tasks->isEmpty())
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-check2-all" style="font-size:40px;display:block;margin-bottom:8px;color:#16a34a;"></i>
            <p class="mb-0">No tasks assigned to you yet.</p>
        </div>
    @else
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:13px;">
            <thead style="background:#f8fafc;">
                <tr>
                    <th class="ps-3">Employee</th>
                    <th>Type</th>
                    <th>Company</th>
                    <th>Date</th>
                    <th>Task</th>
                    <th>Assigned By</th>
                    <th>Status</th>
                    <th>Update</th>
                </tr>
            </thead>
            <tbody>
                @foreach($tasks as $task)
                @php
                    $isOffboarding = !is_null($task->offboarding_id);
                    $empName = $isOffboarding
                        ? ($task->offboarding?->full_name ?? '—')
                        : ($task->onboarding?->personalDetail?->full_name ?? '—');
                    $company = $isOffboarding
                        ? ($task->offboarding?->company ?? '—')
                        : ($task->onboarding?->workDetail?->company ?? '—');
                    $date = $isOffboarding
                        ? ($task->offboarding?->exit_date?->format('d M Y') ?? '—')
                        : ($task->onboarding?->workDetail?->start_date?->format('d M Y') ?? '—');
                    $typeColors = [
                        'asset_preparation' => '#2563eb',
                        'work_email'        => '#8b5cf6',
                        'asset_cleaning'    => '#dc2626',
                        'deactivation'      => '#d97706',
                        'other'             => '#64748b',
                    ];
                @endphp
                <tr>
                    <td class="ps-3">
                        <strong>{{ $empName }}</strong>
                        @if($isOffboarding)
                            <span class="badge bg-danger ms-1" style="font-size:9px;">Offboarding</span>
                            <div><a href="{{ route('it.offboarding.show', $task->offboarding) }}" class="text-muted small" style="font-size:11px;"><i class="bi bi-eye me-1"></i>View Record</a></div>
                        @elseif($task->onboarding)
                            <span class="badge bg-primary ms-1" style="font-size:9px;">Onboarding</span>
                            <div><a href="{{ route('onboarding.show', $task->onboarding) }}" class="text-muted small" style="font-size:11px;"><i class="bi bi-eye me-1"></i>View Record</a></div>
                        @endif
                    </td>
                    <td>
                        <span class="badge" style="background:{{ $isOffboarding ? '#7f1d1d' : '#1e3a5f' }};font-size:10px;">
                            {{ $isOffboarding ? 'Exit' : 'Onboard' }}
                        </span>
                    </td>
                    <td>{{ $company }}</td>
                    <td>
                        {{ $date }}
                        @if($isOffboarding)
                            <div class="text-muted" style="font-size:10px;">Exit date</div>
                        @else
                            <div class="text-muted" style="font-size:10px;">Start date</div>
                        @endif
                    </td>
                    <td>
                        <div class="fw-semibold">{{ $task->title }}</div>
                        @if($task->description)
                            <small class="text-muted">{{ $task->description }}</small>
                        @endif
                        <div class="mt-1">
                            <span class="badge" style="background:{{ $typeColors[$task->task_type] ?? '#64748b' }};font-size:10px;">
                                {{ str_replace('_',' ',ucwords($task->task_type)) }}
                            </span>
                        </div>
                    </td>
                    <td>{{ $task->assignedBy?->name ?? '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $task->statusColor() }}">
                            {{ ucfirst(str_replace('_',' ',$task->status)) }}
                        </span>
                        @if($task->completed_at)
                            <div class="text-muted" style="font-size:10px;">{{ $task->completed_at->format('d M Y H:i') }}</div>
                        @endif
                    </td>
                    <td style="min-width:140px;">
                        <form action="{{ route('it.tasks.status', $task) }}" method="POST" class="d-flex gap-1">
                            @csrf
                            <select name="status" class="form-select form-select-sm" style="font-size:11px;">
                                <option value="pending"      {{ $task->status==='pending'?'selected':'' }}>Pending</option>
                                <option value="in_progress"  {{ $task->status==='in_progress'?'selected':'' }}>In Progress</option>
                                <option value="done"         {{ $task->status==='done'?'selected':'' }}>Done</option>
                            </select>
                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Save">
                                <i class="bi bi-check2"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if($tasks->hasPages())
    <div class="card-footer bg-white py-3">{{ $tasks->links() }}</div>
    @endif
    @endif
</div>
@endsection