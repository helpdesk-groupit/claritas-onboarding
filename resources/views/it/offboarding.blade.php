@extends('layouts.app')
@section('title', 'Offboarding')
@section('page-title', 'Offboarding')

@section('content')

<div class="card">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-box-arrow-right me-2 text-danger"></i>Offboarding Records</h6>
    </div>

    <div class="card-body border-bottom pb-3">
        <form action="{{ route('it.offboarding.index') }}" method="GET" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label form-label-sm fw-semibold mb-1" style="font-size:11px;">Month</label>
                <select name="month" class="form-select form-select-sm">
                    @foreach($months as $num => $name)
                        <option value="{{ $num }}" {{ $month == $num ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label form-label-sm fw-semibold mb-1" style="font-size:11px;">Year</label>
                <select name="year" class="form-select form-select-sm">
                    @foreach($years as $y)
                        <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                    placeholder="Name, email, company..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <select name="company" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    @foreach($companies as $c)
                        <option value="{{ $c }}" {{ request('company')==$c?'selected':'' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['search','company','department']))
                    <a href="{{ route('it.offboarding.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <small class="text-muted px-3 pt-2 d-block">{{ $offboardings->total() }} record(s) for {{ $months[$month] }} {{ $year }}</small>

        @if($offboardings->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox" style="font-size:40px;"></i>
                <p class="mt-2">No offboarding records for {{ $months[$month] }} {{ $year }}</p>
            </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Full Name</th>
                        <th>Department</th>
                        <th>Exit Date</th>
                        <th>1-Month Notice</th>
                        <th>Calendar Invite</th>
                        <th>1-Week Reminder</th>
                        <th>3-Day Reminder</th>
                        <th>Sendoff</th>
                        <th>AARF</th>
                        @if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
                        <th>Assigned PIC</th>
                        @endif
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($offboardings as $ob)
                    @php
                        $sc = ['pending'=>'secondary','sent'=>'success','failed'=>'danger'];
                        $ac = ['pending'=>'secondary','in_progress'=>'warning text-dark','done'=>'success'];
                    @endphp
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $ob->full_name ?? '—' }}</td>
                        <td>{{ $ob->department ?? '—' }}</td>
                        <td>{{ $ob->exit_date?->format('d M Y') ?? '—' }}</td>
                        <td><span class="badge bg-{{ $sc[$ob->notice_email_status ?? 'pending'] ?? 'secondary' }}">{{ ucfirst($ob->notice_email_status ?? 'pending') }}</span></td>
                        <td><span class="badge bg-{{ $sc[$ob->calendar_reminder_status ?? 'pending'] ?? 'secondary' }}">{{ ucfirst($ob->calendar_reminder_status ?? 'pending') }}</span></td>
                        <td><span class="badge bg-{{ $sc[$ob->week_reminder_email_status ?? 'pending'] ?? 'secondary' }}">{{ ucfirst($ob->week_reminder_email_status ?? 'pending') }}</span></td>
                        <td><span class="badge bg-{{ $sc[$ob->reminder_email_status ?? 'pending'] ?? 'secondary' }}">{{ ucfirst($ob->reminder_email_status ?? 'pending') }}</span></td>
                        <td><span class="badge bg-{{ $sc[$ob->sendoff_email_status ?? 'pending'] ?? 'secondary' }}">{{ ucfirst($ob->sendoff_email_status ?? 'pending') }}</span></td>
                        <td><span class="badge bg-{{ $ac[$ob->aarf_status ?? 'pending'] ?? 'secondary' }}">{{ ucfirst(str_replace('_',' ',$ob->aarf_status ?? 'pending')) }}</span></td>

                        {{-- PIC Column — IT Manager / Superadmin only --}}
                        @if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
                        @php $pastExit = $ob->exit_date && \Carbon\Carbon::today()->gt($ob->exit_date); @endphp
                        <td>
                            @if($pastExit)
                                <span class="text-muted small"><i class="bi bi-lock me-1"></i>Passed</span>
                                @if($ob->picUser)
                                    <br><small class="text-success"><i class="bi bi-person-check me-1"></i>{{ $ob->picUser->employee?->preferred_name ?? $ob->picUser->name }}</small>
                                @endif
                            @else
                                <div class="d-flex align-items-center gap-1">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Assign PIC"
                                            data-bs-toggle="modal"
                                            data-bs-target="#picModalOB{{ $ob->id }}">
                                        <i class="bi bi-person-gear"></i>
                                    </button>
                                    @if($ob->picUser)
                                        <small class="text-success"><i class="bi bi-person-check me-1"></i>{{ $ob->picUser->employee?->preferred_name ?? $ob->picUser->name }}</small>
                                    @endif
                                </div>
                            @endif
                        </td>
                        @endif

                        <td>
                            <a href="{{ route('it.offboarding.show', $ob) }}" class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">{{ $offboardings->withQueryString()->links() }}</div>
        @endif
    </div>
</div>

{{-- ── Assign PIC Modals (IT Offboarding) ──────────────────────────────── --}}
@if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
@foreach($offboardings as $ob)
@php $pastExit = $ob->exit_date && \Carbon\Carbon::today()->gt($ob->exit_date); @endphp
@if(!$pastExit)
<div class="modal fade" id="picModalOB{{ $ob->id }}" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:linear-gradient(135deg,#0052CC,#2684FE);">
                <h6 class="modal-title text-white fw-bold mb-0">
                    <i class="bi bi-person-gear me-2"></i>Assign PIC
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('it.offboarding.assign.pic', $ob) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <p class="small text-muted mb-2">{{ $ob->full_name ?? '—' }}</p>
                    <label class="form-label fw-semibold small">Select PIC</label>
                    <div class="d-grid gap-1">
                        <label class="d-flex align-items-center gap-2 p-2 border rounded" style="cursor:pointer;">
                            <input type="radio" name="assigned_pic_user_id" value=""
                                {{ !$ob->assigned_pic_user_id ? 'checked' : '' }}>
                            <span class="small text-muted">— Remove PIC —</span>
                        </label>
                        @foreach($itStaff as $staff)
                        <label class="d-flex align-items-center gap-2 p-2 border rounded" style="cursor:pointer;">
                            <input type="radio" name="assigned_pic_user_id" value="{{ $staff->id }}"
                                {{ $ob->assigned_pic_user_id == $staff->id ? 'checked' : '' }}>
                            <div>
                                <div class="fw-semibold small">{{ $staff->employee?->preferred_name ?? $staff->name }}</div>
                                <div class="text-muted" style="font-size:11px;">{{ ucfirst(str_replace('_',' ',$staff->role)) }}</div>
                            </div>
                        </label>
                        @endforeach
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="bi bi-check2 me-1"></i>Assign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endforeach
@endif

@endsection