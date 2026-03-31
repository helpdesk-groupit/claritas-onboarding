@extends('layouts.app')
@section('title', 'Onboarding')
@section('page-title', 'Onboarding')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <p class="text-muted mb-0 small">View all new hire onboarding records</p>
</div>

<div class="card">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i>Onboarding Records</h6>
    </div>
    {{-- Filters --}}
    <div class="card-body border-bottom pb-3">
        <form action="{{ route('it.onboarding') }}" method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                    placeholder="Search name, position..." value="{{ request('search') }}">
            </div>
            <div class="col-md-2">
                <select name="company" class="form-select form-select-sm">
                    <option value="">All Companies</option>
                    @foreach($companies as $c)
                        <option value="{{ $c }}" {{ request('company')==$c?'selected':'' }}>{{ $c }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <input type="text" name="position" class="form-control form-control-sm"
                    placeholder="Position..." value="{{ request('position') }}">
            </div>
            <div class="col-md-2">
                <input type="date" name="start_date_from" class="form-control form-control-sm"
                    value="{{ request('start_date_from') }}" title="Start date from">
            </div>
            <div class="col-md-2">
                <input type="date" name="start_date_to" class="form-control form-control-sm"
                    value="{{ request('start_date_to') }}" title="Start date to">
            </div>
            <div class="col-auto d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i></button>
                @if(request()->hasAny(['search','company','position','start_date_from','start_date_to']))
                    <a href="{{ route('it.onboarding') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                @endif
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <small class="text-muted px-3 pt-2 d-block">{{ $onboardings->total() }} record(s)</small>
        @if($onboardings->isEmpty())
            <div class="text-center py-5 text-muted"><i class="bi bi-inbox" style="font-size:40px;"></i><p class="mt-2">No records found</p></div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                <thead style="background:#f8fafc;">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Position</th>
                        <th>Company</th>
                        <th>Department</th>
                        <th>Start Date</th>
                        <th>Status</th>
                        <th>AARF</th>
                        <th>Welcome Email</th>
                        <th>Calendar Invite</th>
                        <th>Asset Prep</th>
                        <th>Work Email/GID</th>
                        @if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
                        <th>Assigned PIC</th>
                        @endif
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($onboardings as $ob)
                    <tr>
                        <td class="ps-3"><strong>{{ $ob->personalDetail?->full_name ?? '—' }}</strong></td>
                        <td>{{ $ob->workDetail?->designation ?? '—' }}</td>
                        <td>{{ $ob->workDetail?->company ?? '—' }}</td>
                        <td>{{ $ob->workDetail?->department ?? '—' }}</td>
                        <td>{{ $ob->workDetail?->start_date?->format('d M Y') ?? '—' }}</td>
                        <td>
                            <span class="badge bg-{{ $ob->status==='active'?'success':($ob->status==='pending'?'warning text-dark':'secondary') }}">
                                {{ ucfirst($ob->status) }}
                            </span>
                        </td>
                        <td>
                            @if($ob->aarf)
                                <span class="badge bg-{{ $ob->aarf->it_manager_acknowledged?'success':'warning text-dark' }}">
                                    {{ $ob->aarf->it_manager_acknowledged ? 'Done' : 'Pending' }}
                                </span>
                            @else
                                <span class="badge bg-light text-muted">—</span>
                            @endif
                        </td>
                        {{-- Welcome Email --}}
                        <td>
                            @if($ob->welcome_email_sent)
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
                            @elseif($ob->workDetail?->start_date && \Carbon\Carbon::today()->gt($ob->workDetail->start_date))
                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Failed</span>
                            @else
                                <span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Pending</span>
                            @endif
                        </td>
                        {{-- Calendar Invite --}}
                        <td>
                            @if($ob->calendar_invite_sent)
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Sent</span>
                            @else
                                <span class="badge bg-secondary"><i class="bi bi-dash me-1"></i>—</span>
                            @endif
                        </td>
                        {{-- Asset Preparation Status --}}
                        <td>
                            @php $assetColor = match($ob->asset_preparation_status) { 'done'=>'success','in_progress'=>'warning text-dark',default=>'secondary' }; @endphp
                            <span class="badge bg-{{ $assetColor }}">{{ ucfirst(str_replace('_',' ',$ob->asset_preparation_status ?? 'pending')) }}</span>
                        </td>
                        {{-- Work Email / Google ID Status --}}
                        <td>
                            @php $emailColor = match($ob->work_email_status) { 'done'=>'success','in_progress'=>'warning text-dark',default=>'secondary' }; @endphp
                            <span class="badge bg-{{ $emailColor }}">{{ ucfirst(str_replace('_',' ',$ob->work_email_status ?? 'pending')) }}</span>
                        </td>
                        {{-- Assigned PIC — only IT Manager sees this column --}}
                        @if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
                        @php $pastStart = $ob->workDetail?->start_date && \Carbon\Carbon::today()->gt($ob->workDetail->start_date); @endphp
                        <td>
                            @if($pastStart)
                                <span class="text-muted small"><i class="bi bi-lock me-1"></i>Passed</span>
                                @if($ob->assignedPic)
                                    <br><small class="text-success"><i class="bi bi-person-check me-1"></i>{{ $ob->assignedPic->employee?->preferred_name ?? $ob->assignedPic->name }}</small>
                                @endif
                            @else
                                <div class="d-flex align-items-center gap-1">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Assign PIC"
                                            data-bs-toggle="modal"
                                            data-bs-target="#picModalIT{{ $ob->id }}">
                                        <i class="bi bi-person-gear"></i>
                                    </button>
                                    @if($ob->assignedPic)
                                        <small class="text-success"><i class="bi bi-person-check me-1"></i>{{ $ob->assignedPic->employee?->preferred_name ?? $ob->assignedPic->name }}</small>
                                    @endif
                                </div>
                            @endif
                        </td>
                        @endif
                        <td>
                            @if(Auth::user()->isIt() || Auth::user()->isSuperadmin())
                            <a href="{{ route('onboarding.show', $ob) }}" class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            @else
                            <span class="text-muted small">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @if($onboardings->hasPages())
    <div class="card-footer bg-white py-3">{{ $onboardings->links() }}</div>
    @endif
</div>


{{-- ── Assign PIC Modals (IT Onboarding) ───────────────────────────────── --}}
@if(Auth::user()->isItManager() || Auth::user()->isSuperadmin())
@foreach($onboardings as $ob)
@php $pastStart = $ob->workDetail?->start_date && \Carbon\Carbon::today()->gt($ob->workDetail->start_date); @endphp
@if(!$pastStart)
<div class="modal fade" id="picModalIT{{ $ob->id }}" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                <h6 class="modal-title text-white fw-bold mb-0">
                    <i class="bi bi-person-gear me-2"></i>Assign PIC
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ route('it.assign.pic', $ob) }}" method="POST">
                @csrf
                <div class="modal-body">
                    <p class="small text-muted mb-2">{{ $ob->personalDetail?->full_name ?? '—' }}</p>
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