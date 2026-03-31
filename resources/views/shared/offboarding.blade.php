@extends('layouts.app')
@section('title', 'Offboarding')
@section('page-title', 'Offboarding')

@section('content')

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="card">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold"><i class="bi bi-box-arrow-right me-2 text-danger"></i>Offboarding Records</h6>
    </div>

    {{-- Filters --}}
    <div class="card-body border-bottom pb-3">
        <form action="{{ route('offboarding.index') }}" method="GET" class="row g-2 align-items-end">

            {{-- Month + Year filter --}}
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

            {{-- Search --}}
            <div class="col-md-3">
                <input type="text" name="search" class="form-select form-select-sm"
                    placeholder="Name, email, company..." value="{{ request('search') }}"
                    style="border-radius:.375rem;border:1px solid #dee2e6;padding:.25rem .5rem;font-size:.875rem;">
            </div>

            {{-- Company --}}
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
                @if(request()->hasAny(['search','company','department','exit_date']))
                    <a href="{{ route('offboarding.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
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
                        <th>Company</th>
                        <th>Department</th>
                        <th>Designation</th>
                        <th>Exit Date</th>
                        <th>Calendar Reminder</th>
                        <th>Exiting Email</th>
                        <th>AARF Status</th>
                        @if(Auth::user()->isIt() || Auth::user()->isSuperadmin())
                        <th>Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($offboardings as $ob)
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $ob->full_name ?? '—' }}</td>
                        <td>{{ $ob->company ?? '—' }}</td>
                        <td>{{ $ob->department ?? '—' }}</td>
                        <td>{{ $ob->designation ?? '—' }}</td>
                        <td>{{ $ob->exit_date?->format('d M Y') ?? '—' }}</td>

                        {{-- Calendar Reminder Status --}}
                        <td>
                            @php $calColors = ['pending'=>'secondary','sent'=>'success','failed'=>'danger']; @endphp
                            <span class="badge bg-{{ $calColors[$ob->calendar_reminder_status ?? 'pending'] ?? 'secondary' }}">
                                {{ ucfirst($ob->calendar_reminder_status ?? 'pending') }}
                            </span>
                        </td>

                        {{-- Exiting Email Status --}}
                        <td>
                            <span class="badge bg-{{ $calColors[$ob->exiting_email_status ?? 'pending'] ?? 'secondary' }}">
                                {{ ucfirst($ob->exiting_email_status ?? 'pending') }}
                            </span>
                        </td>

                        {{-- AARF Status --}}
                        <td>
                            @php $aarfColors = ['pending'=>'secondary','in_progress'=>'warning text-dark','done'=>'success']; @endphp
                            <span class="badge bg-{{ $aarfColors[$ob->aarf_status ?? 'pending'] ?? 'secondary' }}">
                                {{ ucfirst(str_replace('_',' ',$ob->aarf_status ?? 'pending')) }}
                            </span>
                        </td>

                        {{-- IT can update status columns --}}
                        @if(Auth::user()->isIt() || Auth::user()->isSuperadmin())
                        <td>
                            <button class="btn btn-xs btn-outline-secondary" style="font-size:11px;padding:2px 8px;"
                                    data-bs-toggle="modal" data-bs-target="#statusModal{{ $ob->id }}">
                                Update
                            </button>
                        </td>
                        @endif
                    </tr>

                    {{-- IT Status Update Modal --}}
                    @if(Auth::user()->isIt() || Auth::user()->isSuperadmin())
                    <div class="modal fade" id="statusModal{{ $ob->id }}" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header" style="background:linear-gradient(135deg,#1e3a5f,#2563eb);">
                                    <h6 class="modal-title text-white">
                                        <i class="bi bi-pencil me-2"></i>Update Offboarding Status
                                    </h6>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p class="fw-semibold mb-3">{{ $ob->full_name }}</p>

                                    {{-- Calendar Reminder --}}
                                    <form action="{{ route('offboarding.status', $ob) }}" method="POST" class="mb-2">
                                        @csrf
                                        <input type="hidden" name="field" value="calendar_reminder_status">
                                        <div class="d-flex align-items-center gap-2">
                                            <label class="form-label mb-0 small fw-semibold" style="min-width:140px;">Calendar Reminder</label>
                                            <select name="status" class="form-select form-select-sm">
                                                @foreach(['pending','sent','failed'] as $s)
                                                    <option value="{{ $s }}" {{ ($ob->calendar_reminder_status??'pending')===$s?'selected':'' }}>{{ ucfirst($s) }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check"></i></button>
                                        </div>
                                    </form>

                                    {{-- Exiting Email --}}
                                    <form action="{{ route('offboarding.status', $ob) }}" method="POST" class="mb-2">
                                        @csrf
                                        <input type="hidden" name="field" value="exiting_email_status">
                                        <div class="d-flex align-items-center gap-2">
                                            <label class="form-label mb-0 small fw-semibold" style="min-width:140px;">Exiting Email</label>
                                            <select name="status" class="form-select form-select-sm">
                                                @foreach(['pending','sent','failed'] as $s)
                                                    <option value="{{ $s }}" {{ ($ob->exiting_email_status??'pending')===$s?'selected':'' }}>{{ ucfirst($s) }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check"></i></button>
                                        </div>
                                    </form>

                                    {{-- AARF Status --}}
                                    <form action="{{ route('offboarding.status', $ob) }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="field" value="aarf_status">
                                        <div class="d-flex align-items-center gap-2">
                                            <label class="form-label mb-0 small fw-semibold" style="min-width:140px;">AARF Status</label>
                                            <select name="status" class="form-select form-select-sm">
                                                @foreach(['pending','in_progress','done'] as $s)
                                                    <option value="{{ $s }}" {{ ($ob->aarf_status??'pending')===$s?'selected':'' }}>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check"></i></button>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif

                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">
            {{ $offboardings->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection