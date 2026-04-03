@extends('layouts.app')
@section('title', 'Account Management')
@section('page-title', 'Account Management')

@section('content')

<div class="card mb-4">
    <div class="card-body py-3">
        <form method="GET" action="{{ route('superadmin.accounts.index') }}" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name or email…" value="{{ request('search') }}">
            </div>
                <div class="col-md-4 d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                @if(request('search') || request('reason'))
                <a href="{{ route('superadmin.accounts.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between py-3" style="background:#fff;">
        <div>
            <h6 class="mb-0 fw-bold"><i class="bi bi-person-x me-2 text-danger"></i>Deactivated Accounts</h6>
            <small class="text-muted">{{ $deactivated->total() }} account(s) deactivated</small>
        </div>
    </div>

    <div class="card-body p-0">
        @if($deactivated->isEmpty())
        <div class="text-center py-5">
            <div style="width:56px;height:56px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <i class="bi bi-person-check" style="font-size:26px;color:#16a34a;"></i>
            </div>
            <div class="fw-semibold text-muted">No deactivated accounts</div>
            <small class="text-muted">All user accounts are currently active.</small>
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:14px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-4">User</th>
                        <th>Role</th>
                        <th>Company</th>
                        <th>Reason</th>
                        <th>Deactivated At</th>
                        <th>Failed Attempts</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($deactivated as $user)
                <tr>
                    <td class="ps-4">
                        <div class="fw-semibold">{{ $user->name }}</div>
                        <small class="text-muted">{{ $user->work_email }}</small>
                    </td>
                    <td>
                        <span class="badge bg-secondary" style="font-size:11px;">
                            {{ ucwords(str_replace('_', ' ', $user->role)) }}
                        </span>
                    </td>
                    <td>
                        <span style="font-size:13px;">{{ $user->employee?->company ?? '—' }}</span>
                    </td>
                    <td>
                        @if($user->deactivation_reason === 'login_lockout')
                            <span class="badge bg-danger" style="font-size:11px;">
                                <i class="bi bi-lock-fill me-1"></i>Login Lockout
                            </span>
                            <div class="text-muted" style="font-size:11px;">5 consecutive failed login attempts</div>
                        @elseif($user->deactivation_reason === 'exit_date')
                            <span class="badge bg-warning text-dark" style="font-size:11px;">
                                <i class="bi bi-calendar-x me-1"></i>Exit Date Passed
                            </span>
                        @elseif($user->deactivation_reason === 'manual')
                            <span class="badge bg-secondary" style="font-size:11px;">
                                <i class="bi bi-hand-index me-1"></i>Manual
                            </span>
                        @else
                            <span class="text-muted" style="font-size:12px;">—</span>
                        @endif
                    </td>
                    <td>
                        @if($user->deactivated_at)
                            <span style="font-size:13px;">{{ $user->deactivated_at->format('d M Y') }}</span>
                            <div class="text-muted" style="font-size:11px;">{{ $user->deactivated_at->format('H:i') }}</div>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($user->login_attempts > 0)
                            <span class="badge bg-danger" style="font-size:12px;">{{ $user->login_attempts }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td class="text-end pe-4">
                        <form action="{{ route('superadmin.accounts.activate', $user) }}" method="POST"
                              onsubmit="return confirm('Activate account for {{ addslashes($user->name) }}?')">
                            @csrf
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="bi bi-person-check me-1"></i>Activate
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if($deactivated->hasPages())
        <div class="d-flex justify-content-end p-3">
            {{ $deactivated->links() }}
        </div>
        @endif
        @endif
    </div>
</div>

@endsection
